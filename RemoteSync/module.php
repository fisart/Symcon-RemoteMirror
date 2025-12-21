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

        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyBoolean('AutoCreate', true);
        
        $this->RegisterPropertyInteger('LocalPasswordModuleID', 0);
        $this->RegisterPropertyString('RemoteServerKey', ''); 
        
        $this->RegisterPropertyInteger('RemotePasswordModuleID', 0);
        $this->RegisterPropertyString('LocalServerKey', '');

        $this->RegisterPropertyInteger('LocalRootID', 0);
        $this->RegisterPropertyInteger('RemoteRootID', 0);
        $this->RegisterPropertyString('SyncList', '[]');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // Use try-catch block for property reading during form load to prevent UI crashes
        try {
            $secID = $this->ReadPropertyInteger('LocalPasswordModuleID');
            $currentRemoteKey = $this->ReadPropertyString('RemoteServerKey');
            $currentLocalKey = $this->ReadPropertyString('LocalServerKey');
        } catch (Exception $e) {
            $secID = 0;
            $currentRemoteKey = '';
            $currentLocalKey = '';
        }
        
        $serverOptions = [];
        $serverOptions[] = ['caption' => "Please select...", 'value' => ""];

        if ($secID > 0 && IPS_InstanceExists($secID)) {
            if (function_exists('SEC_GetKeys')) {
                try {
                    $jsonKeys = SEC_GetKeys($secID);
                    $keys = json_decode($jsonKeys, true);
                    
                    if (is_array($keys)) {
                        foreach ($keys as $k) {
                            $val = (string)$k;
                            $serverOptions[] = ['caption' => $val, 'value' => $val];
                        }
                    }
                } catch (Exception $e) { /* Fail silently */ }
            } else {
                $serverOptions[0]['caption'] = "Error: SEC_GetKeys function missing";
            }
        } else {
            $serverOptions[0]['caption'] = "Select Secrets Module and Apply first";
        }

        $remoteFound = false;
        $localFound = false;

        foreach ($serverOptions as $opt) {
            if ((string)$opt['value'] === $currentRemoteKey) $remoteFound = true;
            if ((string)$opt['value'] === $currentLocalKey) $localFound = true;
        }

        if ($currentRemoteKey !== '' && !$remoteFound) {
            $serverOptions[] = ['caption' => "$currentRemoteKey (Not found)", 'value' => $currentRemoteKey];
        }
        if ($currentLocalKey !== '' && !$localFound && $currentLocalKey !== $currentRemoteKey) {
            $serverOptions[] = ['caption' => "$currentLocalKey (Not found)", 'value' => $currentLocalKey];
        }

        foreach ($form['elements'] as &$element) {
            if (isset($element['name'])) {
                if ($element['name'] == 'RemoteServerKey' || $element['name'] == 'LocalServerKey') {
                    $element['options'] = $serverOptions;
                }
            }
        }

        // Safe property reading for list
        try {
            $rootID = $this->ReadPropertyInteger('LocalRootID');
            $savedListJSON = $this->ReadPropertyString('SyncList');
        } catch (Exception $e) {
            $rootID = 0;
            $savedListJSON = '[]';
        }

        $savedList = json_decode($savedListJSON, true);

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

        $this->rpcClient = null;
        $this->LogDebug("ApplyChanges Triggered. DebugMode is ACTIVE.");

        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // Defensive Property Reading
        try {
            $syncListStr = $this->ReadPropertyString('SyncList');
            if (!is_string($syncListStr)) throw new Exception("SyncList invalid");
            
            $syncList = json_decode($syncListStr, true);
            $localRoot = $this->ReadPropertyInteger('LocalRootID');
            $secID = $this->ReadPropertyInteger('LocalPasswordModuleID');
            $remoteKey = $this->ReadPropertyString('RemoteServerKey');
        } catch (Exception $e) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        
        $activeCount = 0;
        $continueInitialSync = true;

        if ($localRoot == 0 || !IPS_ObjectExists($localRoot)) {
            $this->LogDebug("Config Error: Local Root ID invalid.");
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        
        if ($secID == 0 || !IPS_InstanceExists($secID)) {
            $this->LogDebug("Config Error: Secrets Module ID invalid.");
            $this->SetStatus(IS_INACTIVE); 
            return;
        }

        if ($remoteKey === '') {
            $this->LogDebug("Config Error: Remote Server Key empty.");
            $this->SetStatus(IS_INACTIVE); 
            return;
        }

        if (is_array($syncList)) {
            foreach ($syncList as $item) {
                $objID = $item['ObjectID'];
                
                if (empty($item['Active']) && !empty($item['Delete'])) {
                    if (IPS_ObjectExists($objID)) {
                        $this->LogDebug("Processing Deletion: $objID");
                        if ($this->InitConnection()) {
                            $remoteID = $this->ResolveRemoteID($objID, false);
                            if ($remoteID > 0) {
                                $this->DeleteRemoteObject($remoteID);
                            }
                        }
                    }
                    continue; 
                }

                if (empty($item['Active'])) continue;
                if (!IPS_ObjectExists($objID)) continue;

                $this->RegisterMessage($objID, VM_UPDATE);
                $activeCount++;
                
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

        $this->LogDebug("ApplyChanges Complete. Active Sync Count: " . $activeCount);

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

    private function SyncVariable(int $localID, $value): bool
    {
        if (!$this->InitConnection()) {
            $this->LogDebug("SyncVariable: Connection Init failed.");
            return false;
        }
        
        $remoteID = $this->ResolveRemoteID($localID, true);

        if ($remoteID > 0) {
            try {
                $this->LogDebug("Pushing Value to Remote ID $remoteID: " . json_encode($value));
                $this->rpcClient->SetValue($remoteID, $value);
                $this->EnsureRemoteAction($localID, $remoteID);
                return true;
            } catch (Exception $e) {
                $this->LogDebug("RPC Error in SetValue: " . $e->getMessage());
                unset($this->idMappingCache[$localID]);
                return false;
            }
        } else {
            $this->LogDebug("SyncVariable: Could not resolve Remote ID for Local ID $localID");
        }
        return false;
    }

    private function ResolveRemoteID(int $localID, bool $createIfMissing = true): int
    {
        if (isset($this->idMappingCache[$localID])) return $this->idMappingCache[$localID];

        // Safe property access
        try {
            $localRoot = $this->ReadPropertyInteger('LocalRootID');
            $remoteRoot = $this->ReadPropertyInteger('RemoteRootID');
            $autoCreate = $this->ReadPropertyBoolean('AutoCreate');
        } catch (Exception $e) { return 0; }

        $pathStack = [];
        $currentID = $localID;
        while ($currentID != $localRoot) {
            if ($currentID == 0) break;
            array_unshift($pathStack, IPS_GetName($currentID));
            $currentID = IPS_GetParent($currentID);
        }

        $currentRemoteID = $remoteRoot;
        $autoCreate = $autoCreate && $createIfMissing;

        foreach ($pathStack as $index => $nodeName) {
            if(!$this->rpcClient) return 0;
            
            try {
                $childID = @$this->rpcClient->IPS_GetObjectIDByName($nodeName, $currentRemoteID);
            } catch (Exception $e) { 
                $this->LogDebug("Lookup failed for '$nodeName': " . $e->getMessage());
                $childID = 0; 
            }

            if ($childID == 0) {
                if ($autoCreate) {
                    $this->LogDebug("Auto-Creating: $nodeName");
                    
                    if ($index === count($pathStack) - 1) {
                        $localObj = IPS_GetVariable($localID);
                        $type = $localObj['VariableType'];
                        try {
                            $childID = $this->rpcClient->IPS_CreateVariable($type);
                        } catch (Exception $e) {
                             $this->LogDebug("Create Variable Failed: " . $e->getMessage());
                             return 0;
                        }
                    } else {
                        try {
                            $childID = $this->rpcClient->IPS_CreateInstance("{485D0419-BE97-4548-AA9C-C083EB82E61E}");
                        } catch (Exception $e) {
                             $this->LogDebug("Create Instance Failed: " . $e->getMessage());
                             return 0;
                        }
                    }

                    try {
                        $this->rpcClient->IPS_SetParent($childID, $currentRemoteID);
                        $this->rpcClient->IPS_SetName($childID, $nodeName);
                    } catch (Exception $e) { 
                        $this->LogDebug("SetParent/SetName Failed: " . $e->getMessage());
                        return 0; 
                    }
                } else {
                    $this->LogDebug("Object '$nodeName' missing and AutoCreate is OFF.");
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

    private function InitConnection()
    {
        if ($this->rpcClient !== null) return true;

        try {
            $secID = $this->ReadPropertyInteger('LocalPasswordModuleID');
            $key = $this->ReadPropertyString('RemoteServerKey'); 
        } catch (Exception $e) {
            return false;
        }

        if ($secID == 0 || $key === '' || !IPS_InstanceExists($secID)) {
             $this->LogDebug("InitConnection: Invalid Config.");
             return false;
        }

        // $this->LogDebug("InitConnection: Requesting secret for Key: [" . $key . "]");

        try {
            if (!function_exists('SEC_GetSecret')) {
                $this->LogDebug("InitConnection: SEC_GetSecret missing.");
                return false;
            }
            
            $json = SEC_GetSecret($secID, $key);
            $config = json_decode($json, true);
            
            if (!is_array($config)) {
                $this->LogDebug("InitConnection: JSON Decode returned non-array.");
                return false;
            }

            $url = $config['URL'] ?? $config['url'] ?? $config['Url'] ?? null;
            $user = $config['User'] ?? $config['user'] ?? $config['Username'] ?? null;
            $pw = $config['PW'] ?? $config['pw'] ?? $config['Password'] ?? null;

            if (!$url || !$user || !$pw) {
                $this->LogDebug("InitConnection: Config Missing Fields.");
                return false;
            }
            
            $userEnc = urlencode($user);
            $pwEnc = urlencode($pw);
            
            $connectionUrl = 'https://'.$userEnc.":".$pwEnc."@".$url."/api/";
            $this->rpcClient = new SimpleJSONRPC($connectionUrl);
            return true;

        } catch (Exception $e) {
            $this->LogDebug("InitConnection Exception: " . $e->getMessage());
            return false;
        }
    }

    private function DeleteRemoteObject(int $remoteID)
    {
        try {
            $children = @$this->rpcClient->IPS_GetChildrenIDs($remoteID);
            if (is_array($children)) {
                foreach ($children as $childID) {
                    $this->SafeDeleteRemote($childID);
                }
            }
            $this->SafeDeleteRemote($remoteID);
            if (($key = array_search($remoteID, $this->idMappingCache)) !== false) {
                unset($this->idMappingCache[$key]);
            }
        } catch (Exception $e) { }
    }

    private function SafeDeleteRemote(int $id)
    {
        try {
            $this->rpcClient->IPS_DeleteObject($id);
            $this->LogDebug("Success: IPS_DeleteObject($id)");
        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'found') !== false || stripos($msg, 'valid') !== false) {
                try {
                    $obj = $this->rpcClient->IPS_GetObject($id);
                    $type = $obj['ObjectType'];
                    switch($type) {
                        case 0: $this->rpcClient->IPS_DeleteCategory($id); break;
                        case 1: $this->rpcClient->IPS_DeleteInstance($id); break;
                        case 2: $this->rpcClient->IPS_DeleteVariable($id); break;
                        case 3: $this->rpcClient->IPS_DeleteScript($id, true); break;
                        case 4: $this->rpcClient->IPS_DeleteEvent($id); break;
                        case 5: 
                            try { $this->rpcClient->IPS_DeleteMedia($id, true); } 
                            catch(Exception $ex) { $this->rpcClient->IPS_DeleteMedia($id); }
                            break;
                        case 6: $this->rpcClient->IPS_DeleteLink($id); break;
                    }
                    $this->LogDebug("Success: Legacy Delete on ID $id");
                } catch (Exception $e2) {
                    $this->LogDebug("Fallback Delete Failed: " . $e2->getMessage());
                }
            } else {
                $this->LogDebug("Delete Error for ID $id: " . $msg);
            }
        }
    }

    private function EnsureRemoteProfile(int $localID, int $remoteID)
    {
        $localVar = IPS_GetVariable($localID);
        $profileName = $localVar['VariableCustomProfile'];
        if ($profileName == "") $profileName = $localVar['VariableProfile'];
        if ($profileName == "") return; 

        $this->SyncProfileToRemote($profileName, $localVar['VariableType']);

        try {
            @$this->rpcClient->IPS_SetVariableCustomProfile($remoteID, $profileName);
        } catch (Exception $e) { }
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
        } catch (Exception $e) { }
    }

    private function EnsureRemoteAction(int $localID, int $remoteID)
    {
        // FIX: Wrapped in Try/Catch and separated decoding
        try {
            $remSecID = $this->ReadPropertyInteger('RemotePasswordModuleID');
            $locKey = $this->ReadPropertyString('LocalServerKey'); 
            
            $rawList = $this->ReadPropertyString('SyncList');
            if (!is_string($rawList)) return;
            $syncList = json_decode($rawList, true);
        } catch (Exception $e) { return; }

        $actionEnabled = false;
        
        if (is_array($syncList)) {
            foreach ($syncList as $item) {
                if ($item['ObjectID'] == $localID) {
                    $actionEnabled = $item['Action'] ?? false;
                    break;
                }
            }
        }

        if (!$actionEnabled || $remSecID == 0 || $locKey === '') {
            try { @$this->rpcClient->IPS_SetVariableCustomAction($remoteID, 0); } catch (Exception $e) { }
            return;
        }

        try {
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
                $scriptID = $this->rpcClient->IPS_CreateScript(0); 
                $this->rpcClient->IPS_SetParent($scriptID, $remoteID);
                $this->rpcClient->IPS_SetName($scriptID, "ActionScript");
                $this->rpcClient->IPS_SetHidden($scriptID, true); 
            }
            
            $code = $this->GenerateActionScriptCode($localID, $remSecID, $locKey);
            $this->rpcClient->IPS_SetScriptContent($scriptID, $code);

            $this->rpcClient->IPS_SetVariableCustomAction($remoteID, $scriptID);
        } catch (Exception $e) { }
    }

    private function GenerateActionScriptCode($localID, $remSecID, $locKey)
    {
        $locKeySafe = str_replace("'", "\\'", $locKey);
        
        return "<?php
/* Auto-Generated by RemoteSync Module (Try/Fallback + Encoded) */
\$targetID = $localID;
\$secID = $remSecID;
\$key = '$locKeySafe'; 

if (!function_exists('SEC_GetSecret')) die('SEC Module missing');

\$json = SEC_GetSecret(\$secID, \$key);
\$creds = json_decode(\$json, true);

if (!\$creds || !isset(\$creds['URL'])) die('Credentials not found or Invalid JSON');

\$url = \$creds['URL'] ?? \$creds['url'] ?? \$creds['Url'] ?? null;
\$user = \$creds['User'] ?? \$creds['user'] ?? \$creds['Username'] ?? null;
\$pw = \$creds['PW'] ?? \$creds['pw'] ?? \$creds['Password'] ?? null;

if (!\$url) die('Invalid config structure');

\$userEnc = urlencode(\$user);
\$pwEnc = urlencode(\$pw);

\$connUrl = 'https://'.\$userEnc.':'.\$pwEnc.'@'.\$url.'/api/';

class MiniRPC {
    private \$url;
    public function __construct(\$url) { \$this->url = \$url; }
    public function __call(\$method, \$params) {
        \$payload = json_encode(['jsonrpc'=>'2.0', 'method'=>\$method, 'params'=>\$params, 'id'=>time()]);
        \$opts = ['http'=>['method'=>'POST', 'header'=>'Content-Type: application/json', 'content'=>\$payload, 'timeout'=>5], 'ssl'=>['verify_peer'=>false, 'verify_peer_name'=>false]];
        \$ctx = stream_context_create(\$opts);
        \$result = file_get_contents(\$this->url, false, \$ctx);
        if (\$result === false) throw new Exception('Connection failed');
        \$response = json_decode(\$result, true);
        if (isset(\$response['error'])) throw new Exception(\$response['error']['message'], \$response['error']['code']);
        return \$response['result'] ?? null;
    }
}

\$rpc = new MiniRPC(\$connUrl);

try {
    \$rpc->RequestAction(\$targetID, \$_IPS['VALUE']);
} catch (Exception \$e) {
    if (\$e->getCode() == -32603) {
        \$rpc->SetValue(\$targetID, \$_IPS['VALUE']);
    } else {
        IPS_LogMessage('RemoteSync_Action', 'Error: ' . \$e->getMessage());
    }
}

SetValue(\$_IPS['VARIABLE'], \$_IPS['VALUE']);
?>";
    }

    private function GetRecursiveVariables($parentID)
    {
        $result = [];
        $children = IPS_GetChildrenIDs($parentID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] == 2) { 
                $result[] = $childID;
            }
            if ($obj['HasChildren']) { 
                $result = array_merge($result, $this->GetRecursiveVariables($childID));
            }
        }
        return $result;
    }

    private function LogDebug($msg)
    {
        // Suppress warnings in LogDebug to prevent cascading errors if instance is broken
        try {
            if (@$this->ReadPropertyBoolean('DebugMode')) {
                IPS_LogMessage('RemoteSync', $msg);
            }
        } catch (Exception $e) { /* Ignore */ }
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