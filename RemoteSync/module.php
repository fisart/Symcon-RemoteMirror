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
        
        // Local Auth (Secrets Module)
        $this->RegisterPropertyInteger('LocalPasswordModuleID', 0);
        $this->RegisterPropertyString('RemoteServerKey', ''); 
        
        // Remote Auth (Secrets Module on Remote System)
        $this->RegisterPropertyInteger('RemotePasswordModuleID', 0);
        $this->RegisterPropertyString('LocalServerKey', '');

        // Mirror Settings
        $this->RegisterPropertyInteger('LocalRootID', 0);
        $this->RegisterPropertyInteger('RemoteRootID', 0);
        $this->RegisterPropertyString('SyncList', '[]');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // --- Populate Server Keys from Secrets Module ---
        $secID = $this->ReadPropertyInteger('LocalPasswordModuleID');
        $serverOptions = [];

        if ($secID > 0 && IPS_InstanceExists($secID)) {
            // Check if function exists to avoid crashes during development/updates
            if (function_exists('SEC_GetKeys')) {
                try {
                    // Call SEC_GetKeys -> Returns JSON String
                    $jsonKeys = SEC_GetKeys($secID);
                    $keys = json_decode($jsonKeys, true);
                    
                    if (is_array($keys)) {
                        foreach ($keys as $k) {
                            $serverOptions[] = [
                                'caption' => $k,
                                'value'   => $k
                            ];
                        }
                    }
                } catch (Exception $e) {
                    // Fail silently in form generation
                }
            } else {
                $serverOptions[] = ['caption' => "Function SEC_GetKeys not found", 'value' => ""];
            }
        } else {
            $serverOptions[] = ['caption' => "Select Secrets Module and Apply", 'value' => ""];
        }

        // Inject options into BOTH Select fields (Remote Target & Local Name)
        foreach ($form['elements'] as &$element) {
            if (isset($element['name'])) {
                if ($element['name'] == 'RemoteServerKey' || $element['name'] == 'LocalServerKey') {
                    $element['options'] = $serverOptions;
                }
            }
        }

        // --- Populate Sync List ---
        $rootID = $this->ReadPropertyInteger('LocalRootID');
        $savedListJSON = $this->ReadPropertyString('SyncList');
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

        // 1. Clean up
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // 2. Load Config
        $syncList = json_decode($this->ReadPropertyString('SyncList'), true);
        $localRoot = $this->ReadPropertyInteger('LocalRootID');
        $secID = $this->ReadPropertyInteger('LocalPasswordModuleID');
        $remoteKey = $this->ReadPropertyString('RemoteServerKey');
        
        $activeCount = 0;
        $continueInitialSync = true;

        if ($localRoot == 0 || !IPS_ObjectExists($localRoot)) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        
        if ($secID == 0 || !IPS_InstanceExists($secID) || $remoteKey === '') {
            $this->SetStatus(IS_INACTIVE); 
            return;
        }

        // 3. Process Sync List
        if (is_array($syncList)) {
            foreach ($syncList as $item) {
                $objID = $item['ObjectID'];
                
                // --- CASE 1: DELETION ---
                if (empty($item['Active']) && !empty($item['Delete'])) {
                    if (IPS_ObjectExists($objID)) {
                        $this->LogDebug("Processing Deletion for Local ID: $objID");
                        if ($this->InitConnection()) {
                            $remoteID = $this->ResolveRemoteID($objID, false);
                            if ($remoteID > 0) {
                                $this->DeleteRemoteObject($remoteID);
                            }
                        }
                    }
                    continue; 
                }

                // --- CASE 2: NORMAL SYNC ---
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
        
        $remoteID = $this->ResolveRemoteID($localID, true);

        if ($remoteID > 0) {
            try {
                $this->LogDebug("Pushing Value: " . json_encode($value) . " to Remote ID: $remoteID");
                $this->rpcClient->SetValue($remoteID, $value);
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

    private function DeleteRemoteObject(int $remoteID)
    {
        try {
            $children = @$this->rpcClient->IPS_GetChildrenIDs($remoteID);
            if (is_array($children)) {
                foreach ($children as $childID) {
                    $this->rpcClient->IPS_DeleteObject($childID);
                }
            }
            $this->rpcClient->IPS_DeleteObject($remoteID);
            if (($key = array_search($remoteID, $this->idMappingCache)) !== false) {
                unset($this->idMappingCache[$key]);
            }
        } catch (Exception $e) { }
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

    // --- Reverse Control (Delegated to Remote Secrets Module) ---

    private function EnsureRemoteAction(int $localID, int $remoteID)
    {
        $remSecID = $this->ReadPropertyInteger('RemotePasswordModuleID');
        $locKey = $this->ReadPropertyString('LocalServerKey'); // String
        
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

        if ($scriptID == 0) {
            try {
                $scriptID = $this->rpcClient->IPS_CreateScript(0); 
                $this->rpcClient->IPS_SetParent($scriptID, $remoteID);
                $this->rpcClient->IPS_SetName($scriptID, "ActionScript");
                $this->rpcClient->IPS_SetHidden($scriptID, true); 
                
                // Inject Clean Code using Remote SEC Module + JSON Decode
                $code = $this->GenerateActionScriptCode($localID, $remSecID, $locKey);
                $this->rpcClient->IPS_SetScriptContent($scriptID, $code);

                $this->rpcClient->IPS_SetVariableCustomAction($remoteID, $scriptID);
            } catch (Exception $e) { }
        }
    }

    private function GenerateActionScriptCode($localID, $remSecID, $locKey)
    {
        // Quote the string key safely
        $locKeySafe = str_replace("'", "\\'", $locKey);
        
        // Note: We perform json_decode on the remote side
        return "<?php
/* Auto-Generated by RemoteSync Module */
\$targetID = $localID;
\$secID = $remSecID;
\$key = '$locKeySafe'; 

if (!function_exists('SEC_GetSecret')) die('SEC Module missing');

\$json = SEC_GetSecret(\$secID, \$key);
\$creds = json_decode(\$json, true);

if (!\$creds || !isset(\$creds['URL'])) die('Credentials not found or Invalid JSON');

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

    // --- Connection Helper (Delegated to Local Secrets Module) ---

    private function InitConnection()
    {
        if ($this->rpcClient !== null) return true;

        $secID = $this->ReadPropertyInteger('LocalPasswordModuleID');
        $key = $this->ReadPropertyString('RemoteServerKey'); // String

        if ($secID == 0 || $key === '' || !IPS_InstanceExists($secID)) return false;

        // Call the Secrets Module
        try {
            if (!function_exists('SEC_GetSecret')) return false;
            
            // Get JSON String
            $json = SEC_GetSecret($secID, $key);
            // Decode
            $serverConfig = json_decode($json, true);

        } catch (Exception $e) {
            $this->LogDebug("Failed to call SEC_GetSecret: " . $e->getMessage());
            return false;
        }

        if (!$serverConfig || !isset($serverConfig['URL'])) {
            $this->LogDebug("No valid credentials returned for Key $key");
            return false;
        }
        
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