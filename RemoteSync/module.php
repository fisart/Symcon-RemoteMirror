<?php

declare(strict_types=1);

class RemoteSync extends IPSModule
{
    private $rpcClient = null;
    private $idMappingCache = [];
    private $profileCache = []; 

    public function Create()
    {
        parent::Create();

        // Register Properties
        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyBoolean('AutoCreate', true);
        
        // Outgoing Auth
        $this->RegisterPropertyInteger('CipherVarID', 0);
        $this->RegisterPropertyInteger('KeyVarID', 0);
        $this->RegisterPropertyInteger('RemoteServerKey', 0); 
        
        // Incoming Auth (Reverse Control)
        $this->RegisterPropertyInteger('RemoteCipherID', 0);
        $this->RegisterPropertyInteger('RemoteKeyID', 0);
        $this->RegisterPropertyString('LocalServerLocation', '');

        // Mirror Settings
        $this->RegisterPropertyInteger('LocalRootID', 0);
        $this->RegisterPropertyInteger('RemoteRootID', 0);
        $this->RegisterPropertyString('SyncList', '[]');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // --- Populate Server List ---
        $accessData = $this->GetAccessData();
        $serverOptions = [];

        if ($accessData !== false) {
            foreach ($accessData as $key => $data) {
                $name = $data['Location'] ?? "Server Key $key";
                $serverOptions[] = ['caption' => $name, 'value' => $key];
            }
        } else {
            $serverOptions[] = ['caption' => "Please select Auth Variables and Apply", 'value' => 0];
        }

        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] == 'RemoteServerKey') {
                $element['options'] = $serverOptions;
                break;
            }
        }

        // --- Populate Sync List ---
        $rootID = $this->ReadPropertyInteger('LocalRootID');
        $savedListJSON = $this->ReadPropertyString('SyncList');
        $savedList = json_decode($savedListJSON, true);

        // Map Saved States
        $activeMap = [];
        $actionMap = [];
        $deleteMap = [];
        if (is_array($savedList)) {
            foreach ($savedList as $item) {
                if (isset($item['ObjectID'])) {
                    $activeMap[$item['ObjectID']] = $item['Active'] ?? false;
                    $actionMap[$item['ObjectID']] = $item['Action'] ?? false;
                    $deleteMap[$item['ObjectID']] = $item['Delete'] ?? false;
                }
            }
        }

        $values = [];
        if ($rootID > 0 && IPS_ObjectExists($rootID)) {
            $variables = $this->GetRecursiveVariables($rootID);
            foreach ($variables as $varID) {
                $isActive = isset($activeMap[$varID]) ? $activeMap[$varID] : false;
                $isAction = isset($actionMap[$varID]) ? $actionMap[$varID] : false;
                $isDelete = isset($deleteMap[$varID]) ? $deleteMap[$varID] : false;
                
                $values[] = [
                    'ObjectID' => $varID,
                    'Name'     => IPS_GetName($varID),
                    'Active'   => $isActive,
                    'Action'   => $isAction,
                    'Delete'   => $isDelete
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

        if ($localRoot == 0 || !IPS_ObjectExists($localRoot)) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        
        if ($remoteServerKey == 0) {
            $this->SetStatus(IS_INACTIVE); 
            return;
        }

        // 3. Process Sync List
        if (is_array($syncList)) {
            foreach ($syncList as $item) {
                $objID = $item['ObjectID'];
                
                // --- CASE 1: DELETION REQUESTED (Active=False, Delete=True) ---
                if (empty($item['Active']) && !empty($item['Delete'])) {
                    if (IPS_ObjectExists($objID)) {
                        $this->LogDebug("Processing Deletion for Local ID: $objID");
                        // We need the connection to delete
                        if ($this->InitConnection()) {
                             // Find ID without creating it
                            $remoteID = $this->ResolveRemoteID($objID, false);
                            if ($remoteID > 0) {
                                $this->DeleteRemoteObject($remoteID);
                            } else {
                                $this->LogDebug("Remote object not found, nothing to delete.");
                            }
                        }
                    }
                    continue; // Skip the rest for this item
                }

                // --- CASE 2: NORMAL SYNC (Active=True) ---
                if (empty($item['Active'])) continue;
                if (!IPS_ObjectExists($objID)) continue;

                $this->RegisterMessage($objID, VM_UPDATE);
                $activeCount++;
                
                if ($continueInitialSync) {
                    $currentValue = GetValue($objID);
                    // ResolveRemoteID(..., true) means create if missing
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
        $this->profileCache = []; 
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $this->SyncVariable($SenderID, $Data[0]);
        }
    }

    // --- Core Sync Logic ---

    private function SyncVariable(int $localID, $value): bool
    {
        if (!$this->InitConnection()) return false;
        
        // Pass 'true' to create if missing
        $remoteID = $this->ResolveRemoteID($localID, true);

        if ($remoteID > 0) {
            try {
                $this->LogDebug("Pushing Value: " . json_encode($value) . " to Remote ID: $remoteID");
                $this->rpcClient->SetValue($remoteID, $value);
                
                // Handle Action Script
                $this->EnsureRemoteAction($localID, $remoteID);
                
                return true;
            } catch (Exception $e) {
                $this->LogDebug("RPC Error: " . $e->getMessage());
                unset($this->idMappingCache[$localID]);
                return false;
            }
        }
        return false;
    }

    private function ResolveRemoteID(int $localID, bool $createIfMissing = true): int
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
        $autoCreate = $this->ReadPropertyBoolean('AutoCreate') && $createIfMissing;

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
                         $this->LogDebug("Creation failed for $nodeName: " . $e->getMessage());
                         return 0;
                    }
                } else {
                    return 0;
                }
            }
            $currentRemoteID = $childID;
        }

        if ($createIfMissing) {
            $this->EnsureRemoteProfile($localID, $currentRemoteID);
        }

        $this->idMappingCache[$localID] = $currentRemoteID;
        return $currentRemoteID;
    }

    private function DeleteRemoteObject(int $remoteID)
    {
        try {
            // 1. Check for Children (e.g. Action Script)
            $children = @$this->rpcClient->IPS_GetChildrenIDs($remoteID);
            
            if (is_array($children)) {
                foreach ($children as $childID) {
                    $this->LogDebug("Deleting child object $childID first...");
                    $this->rpcClient->IPS_DeleteObject($childID);
                }
            }

            // 2. Delete the Object itself
            $this->LogDebug("Deleting Remote Object: $remoteID");
            $this->rpcClient->IPS_DeleteObject($remoteID);
            
            // Remove from cache
            if (($key = array_search($remoteID, $this->idMappingCache)) !== false) {
                unset($this->idMappingCache[$key]);
            }

        } catch (Exception $e) {
            $this->LogDebug("Failed to delete remote object: " . $e->getMessage());
        }
    }

    // --- Profile Logic ---
    
    private function EnsureRemoteProfile(int $localID, int $remoteID)
    {
        $localVar = IPS_GetVariable($localID);
        $profileName = $localVar['VariableCustomProfile'];
        if ($profileName == "") $profileName = $localVar['VariableProfile'];
        if ($profileName == "") return; 

        $this->SyncProfileToRemote($profileName, $localVar['VariableType']);

        try {
            @$this->rpcClient->IPS_SetVariableCustomProfile($remoteID, $profileName);
        } catch (Exception $e) {
            $this->LogDebug("Failed to assign profile $profileName: " . $e->getMessage());
        }
    }

    private function SyncProfileToRemote($profileName, $varType)
    {
        if (substr($profileName, 0, 1) == "~") return;
        if (in_array($profileName, $this->profileCache)) return;

        try {
            if ($this->rpcClient->IPS_VariableProfileExists($profileName)) {
                $this->profileCache[] = $profileName;
                return;
            }
        } catch (Exception $e) { return; }

        $localProfile = IPS_GetVariableProfile($profileName);

        try {
            $this->rpcClient->IPS_CreateVariableProfile($profileName, $varType);
            $this->rpcClient->IPS_SetVariableProfileText($profileName, $localProfile['Prefix'], $localProfile['Suffix']);
            $this->rpcClient->IPS_SetVariableProfileValues($profileName, $localProfile['MinValue'], $localProfile['MaxValue'], $localProfile['StepSize']);
            $this->rpcClient->IPS_SetVariableProfileDigits($profileName, $localProfile['Digits']);
            $this->rpcClient->IPS_SetVariableProfileIcon($profileName, $localProfile['Icon']);

            foreach ($localProfile['Associations'] as $assoc) {
                $this->rpcClient->IPS_SetVariableProfileAssociation($profileName, $assoc['Value'], $assoc['Name'], $assoc['Icon'], $assoc['Color']);
            }
            $this->profileCache[] = $profileName;
        } catch (Exception $e) {
            $this->LogDebug("Error creating profile $profileName: " . $e->getMessage());
        }
    }

    // --- Reverse Control / Action Script Logic ---

    private function EnsureRemoteAction(int $localID, int $remoteID)
    {
        $remCipher = $this->ReadPropertyInteger('RemoteCipherID');
        $remKey = $this->ReadPropertyInteger('RemoteKeyID');
        $locName = $this->ReadPropertyString('LocalServerLocation');
        
        $syncList = json_decode($this->ReadPropertyString('SyncList'), true);
        $actionEnabled = false;
        
        if (is_array($syncList)) {
            foreach ($syncList as $item) {
                if ($item['ObjectID'] == $localID) {
                    $actionEnabled = $item['Action'] ?? false;
                    break;
                }
            }
        }

        if (!$actionEnabled || $remCipher == 0 || $remKey == 0 || $locName == '') {
            try {
                @$this->rpcClient->IPS_SetVariableCustomAction($remoteID, 0);
            } catch (Exception $e) { }
            return;
        }

        $children = @$this->rpcClient->IPS_GetChildrenIDs($remoteID);
        if ($children === false) return;

        $scriptID = 0;
        foreach ($children as $child) {
            $obj = @$this->rpcClient->IPS_GetObject($child);
            if ($obj && $obj['ObjectType'] == 3) {
                $scriptID = $child;
                break;
            }
        }

        if ($scriptID == 0) {
            $this->LogDebug("Creating Reverse Action Script for Local ID $localID");
            try {
                $scriptID = $this->rpcClient->IPS_CreateScript(0); 
                $this->rpcClient->IPS_SetParent($scriptID, $remoteID);
                $this->rpcClient->IPS_SetName($scriptID, "ActionScript");
                $this->rpcClient->IPS_SetHidden($scriptID, true); 
                
                $code = $this->GenerateActionScriptCode($localID, $remCipher, $remKey, $locName);
                $this->rpcClient->IPS_SetScriptContent($scriptID, $code);

                $this->rpcClient->IPS_SetVariableCustomAction($remoteID, $scriptID);
            } catch (Exception $e) {
                $this->LogDebug("Failed to create Action Script: " . $e->getMessage());
            }
        }
    }

    private function GenerateActionScriptCode($localID, $remCipher, $remKey, $locName)
    {
        return "<?php
/* Auto-Generated by RemoteSync Module */
\$targetID = $localID;
\$cipherID = $remCipher;
\$keyID = $remKey;
\$targetLocation = '$locName';

\$ciphertext = GetValueString(\$cipherID);
\$keysStr = GetValueString(\$keyID);

\$keyring = @unserialize(\$keysStr);
if (!\$keyring) die('Key Error');

\$secret = hex2bin(\$keyring['secret_key']);
\$iv = hex2bin(\$keyring['iv']);
\$tag = hex2bin(\$keyring['tag']);

\$plain = openssl_decrypt(\$ciphertext, \$keyring['cipher'], \$secret, \$options=0, \$iv, \$tag);
\$data = @unserialize(\$plain);
if (!\$data) die('Decrypt Error');

\$creds = null;
foreach(\$data as \$entry) {
    if (isset(\$entry['Location']) && \$entry['Location'] == \$targetLocation) {
        \$creds = \$entry;
        break;
    }
}

if (!\$creds) die('Location not found');

\$url = 'https://'.\$creds['User'].':'.\$creds['PW'].'@'.\$creds['URL'].'/api/';

class MiniRPC {
    private \$url;
    public function __construct(\$url) { \$this->url = \$url; }
    public function __call(\$method, \$params) {
        \$payload = json_encode(['jsonrpc'=>'2.0', 'method'=>\$method, 'params'=>\$params, 'id'=>time()]);
        \$opts = ['http'=>['method'=>'POST', 'header'=>'Content-Type: application/json', 'content'=>\$payload, 'timeout'=>5], 'ssl'=>['verify_peer'=>false, 'verify_peer_name'=>false]];
        \$ctx = stream_context_create(\$opts);
        @file_get_contents(\$this->url, false, \$ctx);
    }
}

\$rpc = new MiniRPC(\$url);
\$rpc->RequestAction(\$targetID, \$_IPS['VALUE']);
SetValue(\$_IPS['VARIABLE'], \$_IPS['VALUE']);
?>";
    }

    // --- Helpers ---

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
        if ($accessData === false) return false;

        $selectedKey = $this->ReadPropertyInteger('RemoteServerKey');
        if (!isset($accessData[$selectedKey])) return false;

        $serverConfig = $accessData[$selectedKey];
        $url = 'https://'.$serverConfig['User'].":".$serverConfig['PW']."@".$serverConfig['URL']."/api/";
        
        $this->rpcClient = new SimpleJSONRPC($url);
        return true;
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