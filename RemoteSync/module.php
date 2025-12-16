<?php

declare(strict_types=1);

class RemoteSync extends IPSModule
{
    private $rpcClient = null;
    private $idMappingCache = [];

    public function Create()
    {
        parent::Create();

        // Register Properties
        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyBoolean('AutoCreate', true);
        $this->RegisterPropertyInteger('CipherVarID', 0);
        $this->RegisterPropertyInteger('KeyVarID', 0);
        $this->RegisterPropertyInteger('LocalRootID', 0);
        $this->RegisterPropertyInteger('RemoteRootID', 0);
        $this->RegisterPropertyString('SyncList', '[]');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $rootID = $this->ReadPropertyInteger('LocalRootID');
        $savedListJSON = $this->ReadPropertyString('SyncList');
        $savedList = json_decode($savedListJSON, true);

        $activeMap = [];
        if (is_array($savedList)) {
            foreach ($savedList as $item) {
                if (isset($item['ObjectID'])) {
                    $activeMap[$item['ObjectID']] = $item['Active'] ?? false;
                }
            }
        }

        $values = [];
        if ($rootID > 0 && IPS_ObjectExists($rootID)) {
            $variables = $this->GetRecursiveVariables($rootID);
            foreach ($variables as $varID) {
                $isActive = isset($activeMap[$varID]) ? $activeMap[$varID] : false;
                $values[] = [
                    'ObjectID' => $varID,
                    'Name'     => IPS_GetName($varID),
                    'Active'   => $isActive
                ];
            }
        }

        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] == 'SyncList') {
                $element['values'] = $values;
                break;
            }
        }

        return json_encode($form);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // 1. Unregister all existing Message Sinks
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // 2. Load Config
        $syncList = json_decode($this->ReadPropertyString('SyncList'), true);
        $localRoot = $this->ReadPropertyInteger('LocalRootID');
        $activeCount = 0;
        
        // 3. Process List & Perform Initial Sync
        $continueInitialSync = true; // Flag to stop trying if remote is down (prevents hang)

        if (is_array($syncList)) {
            foreach ($syncList as $item) {
                if (empty($item['Active'])) continue;
                
                $objID = $item['ObjectID'];
                if (!IPS_ObjectExists($objID)) continue;

                // A. Register for future updates
                $this->RegisterMessage($objID, VM_UPDATE);
                $activeCount++;
                
                // B. Sync IMMEDIATELY (Create & Push)
                if ($continueInitialSync) {
                    $currentValue = GetValue($objID);
                    // SyncVariable returns false if connection failed
                    $success = $this->SyncVariable($objID, $currentValue);
                    
                    if (!$success) {
                        $continueInitialSync = false;
                        $this->LogDebug("Initial sync failed. Aborting bulk sync to prevent timeouts.");
                    }
                }
            }
        }

        // 4. Set Module Status
        if ($localRoot == 0 || !IPS_ObjectExists($localRoot)) {
            $this->SetStatus(IS_INACTIVE);
        } elseif ($activeCount > 0) {
            $this->SetStatus(IS_ACTIVE);
        } else {
            $this->SetStatus(IS_INACTIVE);
        }
        
        // Clear cache
        $this->idMappingCache = [];
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $this->SyncVariable($SenderID, $Data[0]);
        }
    }

    private function GetRecursiveVariables($parentID)
    {
        $result = [];
        $children = IPS_GetChildrenIDs($parentID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] == 2) { 
                $result[] = $childID;
            } elseif ($obj['ObjectType'] == 1 || $obj['ObjectType'] == 0) { 
                $result = array_merge($result, $this->GetRecursiveVariables($childID));
            }
        }
        return $result;
    }

    /**
     * Returns true on success, false on failure
     */
    private function SyncVariable(int $localID, $value): bool
    {
        if (!$this->InitConnection()) return false;
        
        // ResolveRemoteID handles the creation if AutoCreate is ON
        $remoteID = $this->ResolveRemoteID($localID);

        if ($remoteID > 0) {
            try {
                $this->LogDebug("Pushing Value: " . json_encode($value) . " to Remote ID: $remoteID");
                $this->rpcClient->SetValue($remoteID, $value);
                return true;
            } catch (Exception $e) {
                $this->LogDebug("Error setting value: " . $e->getMessage());
                unset($this->idMappingCache[$localID]);
                return false;
            }
        }
        return false;
    }

    private function ResolveRemoteID(int $localID): int
    {
        if (isset($this->idMappingCache[$localID])) return $this->idMappingCache[$localID];

        $localRoot = $this->ReadPropertyInteger('LocalRootID');
        $remoteRoot = $this->ReadPropertyInteger('RemoteRootID');

        // Build path
        $pathStack = [];
        $currentID = $localID;
        while ($currentID != $localRoot) {
            if ($currentID == 0) break; 
            array_unshift($pathStack, IPS_GetName($currentID));
            $currentID = IPS_GetParent($currentID);
        }

        $currentRemoteID = $remoteRoot;
        $autoCreate = $this->ReadPropertyBoolean('AutoCreate');

        foreach ($pathStack as $index => $nodeName) {
            if(!$this->rpcClient) return 0;
            try {
                $childID = @$this->rpcClient->IPS_GetObjectIDByName($nodeName, $currentRemoteID);
            } catch (Exception $e) { $childID = 0; }

            if ($childID == 0) {
                if ($autoCreate) {
                    $isLeaf = ($index === count($pathStack) - 1);
                    if ($isLeaf) {
                        $localObj = IPS_GetVariable($localID);
                        $type = $localObj['VariableType'];
                        $childID = $this->rpcClient->IPS_CreateVariable($type);
                    } else {
                        $childID = $this->rpcClient->IPS_CreateInstance("{485D0419-BE97-4548-AA9C-C083EB82E61E}");
                    }
                    try {
                        $this->rpcClient->IPS_SetParent($childID, $currentRemoteID);
                        $this->rpcClient->IPS_SetName($childID, $nodeName);
                    } catch (Exception $e) {
                        return 0; // Creation failed
                    }
                } else {
                    return 0;
                }
            }
            $currentRemoteID = $childID;
        }

        $this->idMappingCache[$localID] = $currentRemoteID;
        return $currentRemoteID;
    }

    private function InitConnection()
    {
        if ($this->rpcClient !== null) return true;
        $cipherID = $this->ReadPropertyInteger('CipherVarID');
        $keysID = $this->ReadPropertyInteger('KeyVarID');

        if (!IPS_VariableExists($cipherID) || !IPS_VariableExists($keysID)) return false;

        $ciphertext = GetValueString($cipherID);
        $cipher_keys = GetValueString($keysID);
        $keyring_hex = @unserialize($cipher_keys);
        
        if ($keyring_hex === false) return false;

        $secret_key = hex2bin($keyring_hex['secret_key']);
        $iv = hex2bin($keyring_hex['iv']);
        $tag = hex2bin($keyring_hex['tag']);

        $original_plaintext = openssl_decrypt($ciphertext, $keyring_hex['cipher'], $secret_key, $options=0, $iv, $tag);
        if ($original_plaintext === false) return false;

        $access_data = @unserialize($original_plaintext);
        $key = 1; 
        if (!isset($access_data[$key])) $key = array_key_first($access_data);

        $url = 'https://'.$access_data[$key]['User'].":".$access_data[$key]['PW']."@".$access_data[$key]['URL']."/api/";
        $this->rpcClient = new SimpleJSONRPC($url);
        return true;
    }

    private function LogDebug($msg)
    {
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->SendDebug('RemoteSync', $msg, 0);
        }
    }
}

class SimpleJSONRPC {
    private $url;
    public function __construct($url) { $this->url = $url; }
    public function __call($method, $params) {
        $payload = json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => time()]);
        $opts = ['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => $payload, 'timeout' => 5], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
        $context = stream_context_create($opts);
        $result = @file_get_contents($this->url, false, $context);
        if ($result === false) throw new Exception("Connection failed");
        $response = json_decode($result, true);
        if (isset($response['error'])) throw new Exception($response['error']['message']);
        return $response['result'] ?? null;
    }
}