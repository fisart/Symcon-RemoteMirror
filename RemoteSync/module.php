<?php

declare(strict_types=1);

class RemoteSync extends IPSModule
{
    private $rpcClient = null;
    private $idMappingCache = [];
    private $profileCache = []; 
    
    // Runtime Cache
    private $config = [];
    private $isInitializing = false;

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
        
        // CACHE ATTRIBUTE for Form Performance
        $this->RegisterAttributeString('_SyncListCache', '[]');
        
        // Timer for Deferred Sync
        $this->RegisterTimer('StartSyncTimer', 0, 'RS_ProcessSync($_IPS[\'TARGET\']);');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // 1. Populate Server Keys
        try {
            $secID = @$this->ReadPropertyInteger('LocalPasswordModuleID');
            $currentRemoteKey = @$this->ReadPropertyString('RemoteServerKey');
            $currentLocalKey = @$this->ReadPropertyString('LocalServerKey');
        } catch (Exception $e) {
            $secID = 0; $currentRemoteKey = ''; $currentLocalKey = '';
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
                } catch (Exception $e) { }
            } else {
                $serverOptions[0]['caption'] = "Error: SEC_GetKeys function missing";
            }
        } else {
            $serverOptions[0]['caption'] = "Select Secrets Module and Apply first";
        }

        // Safety Net for Keys
        $remoteFound = false; $localFound = false;
        foreach ($serverOptions as $opt) {
            if ((string)$opt['value'] === $currentRemoteKey) $remoteFound = true;
            if ((string)$opt['value'] === $currentLocalKey) $localFound = true;
        }
        if ($currentRemoteKey !== '' && !$remoteFound) $serverOptions[] = ['caption' => "$currentRemoteKey (Not found)", 'value' => $currentRemoteKey];
        if ($currentLocalKey !== '' && !$localFound && $currentLocalKey !== $currentRemoteKey) $serverOptions[] = ['caption' => "$currentLocalKey (Not found)", 'value' => $currentLocalKey];

        foreach ($form['elements'] as &$element) {
            if (isset($element['name'])) {
                if ($element['name'] == 'RemoteServerKey' || $element['name'] == 'LocalServerKey') {
                    $element['options'] = $serverOptions;
                }
            }
        }

        // 2. Build Sync List (SCANNING HAPPENS HERE)
        $listValues = $this->BuildSyncListAndCache();

        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] == 'SyncList') {
                $element['values'] = $listValues;
                break;
            }
        }

        return json_encode($form);
    }

    /**
     * UI Callback: Toggles a column for all items.
     * Uses CACHE instead of scanning, making it instant.
     */
    public function ToggleAll(string $Column, bool $State)
    {
        // Rebuild list with override
        $newValues = $this->BuildSyncListAndCache($Column, $State);
        
        // Push update to UI
        $this->UpdateFormField('SyncList', 'values', json_encode($newValues));
    }

    /**
     * Helper to build the list array.
     * Optional parameters allow forcing a column to a specific state.
     */
    private function BuildSyncListAndCache($OverrideColumn = null, $OverrideState = null)
    {
        try {
            $rootID = @$this->ReadPropertyInteger('LocalRootID');
            $rawList = @$this->ReadPropertyString('SyncList');
            $savedListJSON = is_string($rawList) ? $rawList : '[]';
        } catch (Exception $e) {
            $rootID = 0;
            $savedListJSON = '[]';
        }

        $savedList = json_decode($savedListJSON, true);

        // Map saved states
        $activeMap = []; $actionMap = []; $deleteMap = [];
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
        
        // If Override is used (ToggleAll), we rely on Cache to avoid re-scan
        if ($OverrideColumn !== null) {
             $cachedIDs = json_decode($this->ReadAttributeString('_SyncListCache'), true);
             if (!is_array($cachedIDs)) $cachedIDs = []; // Fallback to empty if cache missing
             $scannedIDs = $cachedIDs;
        } else {
             // Normal Load: Full Scan
             $scannedIDs = [];
             if ($rootID > 0 && IPS_ObjectExists($rootID)) {
                 $this->GetRecursiveVariables($rootID, $scannedIDs);
                 // Update Cache
                 $this->WriteAttributeString('_SyncListCache', json_encode($scannedIDs));
             }
        }

        foreach ($scannedIDs as $varID) {
            // Check if object still exists (if reading from cache)
            if (!IPS_ObjectExists($varID)) continue;

            $isActive = $activeMap[$varID] ?? false;
            $isAction = $actionMap[$varID] ?? false;
            $isDelete = $deleteMap[$varID] ?? false;

            // Apply Override
            if ($OverrideColumn === 'Active') $isActive = $OverrideState;
            if ($OverrideColumn === 'Action') $isAction = $OverrideState;
            if ($OverrideColumn === 'Delete') $isDelete = $OverrideState;

            $values[] = [
                'ObjectID' => $varID,
                'Name'     => IPS_GetName($varID),
                'Active'   => $isActive,
                'Action'   => $isAction,
                'Delete'   => $isDelete
            ];
        }
        
        return $values;
    }

    /**
     * OPTIMIZED RECURSION (Pass by Reference)
     */
    private function GetRecursiveVariables($parentID, &$result)
    {
        $children = IPS_GetChildrenIDs($parentID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            
            // Collect Variables
            if ($obj['ObjectType'] == 2) { 
                $result[] = $childID;
            }
            
            // Recurse deeply
            if ($obj['HasChildren']) { 
                $this->GetRecursiveVariables($childID, $result);
            }
        }
    }

    public function ApplyChanges()
    {
        // 1. Lock Runtime
        $this->isInitializing = true;
        parent::ApplyChanges();

        $this->rpcClient = null;
        $this->LogDebug("ApplyChanges Triggered. DebugMode is ACTIVE.");

        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // 2. Load Config to Cache
        if (!$this->LoadConfig()) {
            $this->SetStatus(IS_INACTIVE);
            $this->isInitializing = false;
            return;
        }

        // 3. Register Messages
        $registerCount = 0;
        if (is_array($this->config['SyncList'])) {
            foreach ($this->config['SyncList'] as $item) {
                if (!empty($item['Active']) && IPS_ObjectExists($item['ObjectID'])) {
                    $this->RegisterMessage($item['ObjectID'], VM_UPDATE);
                    $registerCount++;
                }
            }
        }

        // 4. Set Status & Start Deferred Sync
        $localRoot = $this->config['LocalRootID'];
        $secID = $this->config['LocalPasswordModuleID'];
        $remoteKey = $this->config['RemoteServerKey'];
        
        if ($localRoot == 0 || $secID == 0 || $remoteKey === '') {
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->SetStatus(IS_ACTIVE);
            $this->SetTimerInterval('StartSyncTimer', 250); // Run in 250ms
            $this->LogDebug("ApplyChanges: Registered $registerCount variables. Scheduled background sync.");
        }
        
        $this->isInitializing = false;
    }

    private function LoadConfig()
    {
        try {
            $this->config = [
                'DebugMode'             => @$this->ReadPropertyBoolean('DebugMode'),
                'AutoCreate'            => @$this->ReadPropertyBoolean('AutoCreate'),
                'LocalPasswordModuleID' => @$this->ReadPropertyInteger('LocalPasswordModuleID'),
                'RemoteServerKey'       => @$this->ReadPropertyString('RemoteServerKey'),
                'RemotePasswordModuleID'=> @$this->ReadPropertyInteger('RemotePasswordModuleID'),
                'LocalServerKey'        => @$this->ReadPropertyString('LocalServerKey'),
                'LocalRootID'           => @$this->ReadPropertyInteger('LocalRootID'),
                'RemoteRootID'          => @$this->ReadPropertyInteger('RemoteRootID'),
                'SyncListRaw'           => @$this->ReadPropertyString('SyncList')
            ];
            
            if (!is_string($this->config['SyncListRaw'])) return false;
            
            $this->config['SyncList'] = json_decode($this->config['SyncListRaw'], true);
            if (!is_array($this->config['SyncList'])) $this->config['SyncList'] = [];
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function ProcessSync()
    {
        $this->SetTimerInterval('StartSyncTimer', 0);
        $this->LogDebug("Background Sync Started...");
        
        // Ensure config is loaded if this runs disconnected from ApplyChanges
        if (empty($this->config)) {
            if (!$this->LoadConfig()) return;
        }

        if (!$this->InitConnection()) {
            $this->LogDebug("Background Sync Aborted: Could not connect.");
            return;
        }

        $count = 0;
        foreach ($this->config['SyncList'] as $item) {
            $objID = $item['ObjectID'];
            
            // DELETION
            if (empty($item['Active']) && !empty($item['Delete'])) {
                if (IPS_ObjectExists($objID)) {
                    $remoteID = $this->ResolveRemoteID($objID, false);
                    if ($remoteID > 0) {
                        $this->LogDebug("Deleting: $objID");
                        $this->DeleteRemoteObject($remoteID);
                    }
                }
                continue; 
            }

            // SYNC
            if (!empty($item['Active']) && IPS_ObjectExists($objID)) {
                $currentValue = GetValue($objID);
                $this->SyncVariableInternal($objID, $currentValue);
                $count++;
            }
        }
        
        $this->LogDebug("Background Sync Finished. Processed $count items.");
        $this->idMappingCache = [];
        $this->profileCache = [];
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($this->isInitializing) return;

        if ($Message == VM_UPDATE) {
            if (empty($this->config)) {
                if (!$this->LoadConfig()) return;
            }
            $this->SyncVariable($SenderID, $Data[0]);
        }
    }

    private function SyncVariable(int $localID, $value): bool
    {
        if (!$this->InitConnection()) return false;
        return $this->SyncVariableInternal($localID, $value);
    }

    private function SyncVariableInternal(int $localID, $value): bool
    {
        $remoteID = $this->ResolveRemoteID($localID, true);

        if ($remoteID > 0) {
            try {
                $this->rpcClient->SetValue($remoteID, $value);
                $this->EnsureRemoteAction($localID, $remoteID);
                return true;
            } catch (Exception $e) {
                $this->LogDebug("RPC Error in SetValue ($localID): " . $e->getMessage());
                unset($this->idMappingCache[$localID]);
                return false;
            }
        }
        return false;
    }

    private function ResolveRemoteID(int $localID, bool $createIfMissing = true): int
    {
        if (isset($this->idMappingCache[$localID])) return $this->idMappingCache[$localID];

        $localRoot = $this->config['LocalRootID'];
        $remoteRoot = $this->config['RemoteRootID'];
        $autoCreate = $this->config['AutoCreate'] && $createIfMissing;

        $pathStack = [];
        $currentID = $localID;
        while ($currentID != $localRoot) {
            if ($currentID == 0) break;
            array_unshift($pathStack, IPS_GetName($currentID));
            $currentID = IPS_GetParent($currentID);
        }

        $currentRemoteID = $remoteRoot;

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
                    if ($index === count($pathStack) - 1) {
                        $localObj = IPS_GetVariable($localID);
                        $type = $localObj['VariableType'];
                        try {
                            $childID = $this->rpcClient->IPS_CreateVariable($type);
                        } catch (Exception $e) { return 0; }
                    } else {
                        try {
                            $childID = $this->rpcClient->IPS_CreateInstance("{485D0419-BE97-4548-AA9C-C083EB82E61E}");
                        } catch (Exception $e) { return 0; }
                    }

                    try {
                        $this->rpcClient->IPS_SetParent($childID, $currentRemoteID);
                        $this->rpcClient->IPS_SetName($childID, $nodeName);
                    } catch (Exception $e) { return 0; }
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

    private function InitConnection()
    {
        if ($this->rpcClient !== null) return true;

        $secID = $this->config['LocalPasswordModuleID'];
        $key = $this->config['RemoteServerKey'];

        if ($secID == 0 || $key === '' || !IPS_InstanceExists($secID)) return false;

        try {
            if (!function_exists('SEC_GetSecret')) return false;
            
            $json = SEC_GetSecret($secID, $key);
            $config = json_decode($json, true);
            
            if (!is_array($config)) return false;

            $url = $config['URL'] ?? $config['url'] ?? $config['Url'] ?? null;
            $user = $config['User'] ?? $config['user'] ?? $config['Username'] ?? null;
            $pw = $config['PW'] ?? $config['pw'] ?? $config['Password'] ?? null;

            if (!$url || !$user || !$pw) return false;
            
            $userEnc = urlencode($user);
            $pwEnc = urlencode($pw);
            
            $connectionUrl = 'https://'.$userEnc.":".$pwEnc."@".$url."/api/";
            $this->rpcClient = new RemoteSync_RPCClient($connectionUrl);
            return true;

        } catch (Exception $e) { return false; }
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
        } catch (Exception $e) { 
            $this->LogDebug("Delete process failed: " . $e->getMessage());
        }
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
        try {
            $remSecID = $this->config['RemotePasswordModuleID'] ?? 0;
            $locKey = $this->config['LocalServerKey'] ?? ''; 
            $syncList = $this->config['SyncList'] ?? [];
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

        try {
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

    private function LogDebug($msg)
    {
        if (isset($this->config['DebugMode'])) {
            if ($this->config['DebugMode']) IPS_LogMessage('RemoteSync', $msg);
        } else {
            try {
                if (@$this->ReadPropertyBoolean('DebugMode')) {
                    IPS_LogMessage('RemoteSync', $msg);
                }
            } catch (Exception $e) { /* Ignore */ }
        }
    }
}

class RemoteSync_RPCClient {
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