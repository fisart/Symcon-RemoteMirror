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
        $this->RegisterPropertyInteger('RemoteServerKey', 0); // The selected server ID (1, 2, 3...)
        $this->RegisterPropertyInteger('LocalRootID', 0);
        $this->RegisterPropertyInteger('RemoteRootID', 0);
        $this->RegisterPropertyString('SyncList', '[]');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // --- Dynamic Part 1: Populate Server List from Encrypted Data ---
        $accessData = $this->GetAccessData();
        $serverOptions = [];

        if ($accessData !== false) {
            foreach ($accessData as $key => $data) {
                // Check if 'Location' exists, otherwise fallback to key
                $name = $data['Location'] ?? "Server Key $key";
                $serverOptions[] = [
                    'caption' => $name,
                    'value'   => $key
                ];
            }
        } else {
            $serverOptions[] = ['caption' => "Please select Auth Variables and Apply", 'value' => 0];
        }

        // Inject options into the form
        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] == 'RemoteServerKey') {
                $element['options'] = $serverOptions;
                break;
            }
        }

        // --- Dynamic Part 2: Populate Sync List from Local Root ---
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

        // 1. Clean up
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // 2. Load Config
        $syncList = json_decode($this->ReadPropertyString('SyncList'), true);
        $localRoot = $this->ReadPropertyInteger('LocalRootID');
        $remoteServerKey = $this->ReadPropertyInteger('RemoteServerKey');
        
        $activeCount = 0;
        $continueInitialSync = true;

        // 3. Status Checks
        if ($localRoot == 0 || !IPS_ObjectExists($localRoot)) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        
        if ($remoteServerKey == 0) {
            $this->SetStatus(IS_INACTIVE); // Waiting for server selection
            return;
        }

        // 4. Process Sync
        if (is_array($syncList)) {
            foreach ($syncList as $item) {
                if (empty($item['Active'])) continue;
                
                $objID = $item['ObjectID'];
                if (!IPS_ObjectExists($objID)) continue;

                $this->RegisterMessage($objID, VM_UPDATE);
                $activeCount++;
                
                // Initial Push
                if ($continueInitialSync) {
                    $currentValue = GetValue($objID);
                    $success = $this->SyncVariable($objID, $currentValue);
                    if (!$success) {
                        $continueInitialSync = false;
                        $this->LogDebug("Initial sync failed. Stopping bulk update.");
                    }
                }
            }
        }

        if ($activeCount > 0) {
            $this->SetStatus(IS_ACTIVE);
        } else {
            $this->SetStatus(IS_INACTIVE);
        }
        
        $this->idMappingCache = [];
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $this->SyncVariable($SenderID, $Data[0]);
        }
    }

    // --- Helper Functions ---

    /**
     * Centralized function to decrypt credentials.
     * Returns the array of servers or false on failure.
     */
    private function GetAccessData()
    {
        $cipherID = $this->ReadPropertyInteger('CipherVarID');
        $keysID = $this->ReadPropertyInteger('KeyVarID');

        if ($cipherID == 0 || $keysID == 0) return false;
        if (!IPS_VariableExists($cipherID) || !IPS_VariableExists($keysID)) return false;

        $ciphertext = GetValueString($cipherID);
        $cipher_keys = GetValueString($keysID);
        
        $keyring_hex = @unserialize($cipher_keys);
        if ($keyring_hex === false || !isset($keyring_hex['secret_key'])) return false;

        $secret_key = hex2bin($keyring_hex['secret_key']);
        $iv = hex2bin($keyring_hex['iv']);
        $tag = hex2bin($keyring_hex['tag']);

        $original_plaintext = openssl_decrypt($ciphertext, $keyring_hex['cipher'], $secret_key, $options=0, $iv, $tag);
        
        if ($original_plaintext === false) return false;

        $data = @unserialize($original_plaintext);
        return ($data === false) ? false : $data;
    }

    private function InitConnection()
    {
        if ($this->rpcClient !== null) return true;

        $accessData = $this->GetAccessData();
        if ($accessData === false) {
            $this->LogDebug("Failed to decrypt access data.");
            return false;
        }

        // Get the selected server key from config
        $selectedKey = $this->ReadPropertyInteger('RemoteServerKey');

        if (!isset($accessData[$selectedKey])) {
            $this->LogDebug("Selected Server Key $selectedKey not found in encrypted data.");
            return false;
        }

        $serverConfig = $accessData[$selectedKey];
        
        // Build URL
        $url = 'https://'.$serverConfig['User'].":".$serverConfig['PW']."@".$serverConfig['URL']."/api/";
        
        $this->LogDebug("Connecting to: " . ($serverConfig['Location'] ?? $serverConfig['URL']));
        
        $this->rpcClient = new SimpleJSONRPC($url);
        return true;
    }

    private function SyncVariable(int $localID, $value): bool
    {
        if (!$this->InitConnection()) return false;
        
        $remoteID = $this->ResolveRemoteID($localID);

        if ($remoteID > 0) {
            try {
                $this->LogDebug("Pushing Value: " . json_encode($value) . " to Remote ID: $remoteID");
                $this->rpcClient->SetValue($remoteID, $value);
                return true;
            } catch (Exception $e) {
                $this->LogDebug("RPC Error: " . $e->getMessage());
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

        // Build path relative to root
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
                        // Dummy Instance
                        $childID = $this->rpcClient->IPS_CreateInstance("{485D0419-BE97-4548-AA9C-C083EB82E61E}");
                    }
                    try {
                        $this->rpcClient->IPS_SetParent($childID, $currentRemoteID);
                        $this->rpcClient->IPS_SetName($childID, $nodeName);
                    } catch (Exception $e) {
                         $this->LogDebug("Creation failed for $nodeName: " . $e->getMessage());
                         return 0;
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