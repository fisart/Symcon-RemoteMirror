<?php

declare(strict_types=1);

class RemoteSync extends IPSModule
{
    private $rpcClient = null;
    private $config = [];
    private $buffer = []; 
    // Removed isInitializing flag - relying on attribute locking instead to prevent stuck states
    private $isSending = false;

    public function Create()
    {
        parent::Create();

        $this->rpcClient = null;
        $this->config = [];
        $this->buffer = [];

        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyBoolean('AutoCreate', true);
        
        $this->RegisterPropertyInteger('LocalPasswordModuleID', 0);
        $this->RegisterPropertyString('RemoteServerKey', ''); 
        
        $this->RegisterPropertyInteger('RemotePasswordModuleID', 0);
        $this->RegisterPropertyString('LocalServerKey', '');

        $this->RegisterPropertyInteger('LocalRootID', 0);
        $this->RegisterPropertyInteger('RemoteRootID', 0);
        $this->RegisterPropertyString('SyncList', '[]');
        
        $this->RegisterAttributeString('_SyncListCache', '[]');
        $this->RegisterAttributeInteger('_RemoteReceiverID', 0);
        $this->RegisterAttributeInteger('_RemoteGatewayID', 0);
        
        $this->RegisterTimer('StartSyncTimer', 0, 'RS_ProcessSync($_IPS[\'TARGET\']);');
        $this->RegisterTimer('BufferTimer', 0, 'RS_FlushBuffer($_IPS[\'TARGET\']);');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        try {
            $secID = @$this->ReadPropertyInteger('LocalPasswordModuleID');
            $currentRemoteKey = @$this->ReadPropertyString('RemoteServerKey');
            $currentLocalKey = @$this->ReadPropertyString('LocalServerKey');
        } catch (Exception $e) { $secID = 0; $currentRemoteKey = ''; $currentLocalKey = ''; }
        
        $serverOptions = [['caption' => "Please select...", 'value' => ""]];

        if ($secID > 0 && IPS_InstanceExists($secID) && function_exists('SEC_GetKeys')) {
            try {
                $keys = json_decode(SEC_GetKeys($secID), true);
                if (is_array($keys)) {
                    foreach ($keys as $k) $serverOptions[] = ['caption' => (string)$k, 'value' => (string)$k];
                }
            } catch (Exception $e) { }
        } else {
            $serverOptions[0]['caption'] = "Select Secrets Module and Apply first";
        }

        $remoteFound = false; $localFound = false;
        foreach ($serverOptions as $opt) {
            if ((string)$opt['value'] === $currentRemoteKey) $remoteFound = true;
            if ((string)$opt['value'] === $currentLocalKey) $localFound = true;
        }
        if ($currentRemoteKey !== '' && !$remoteFound) $serverOptions[] = ['caption' => "$currentRemoteKey (Not found)", 'value' => $currentRemoteKey];
        if ($currentLocalKey !== '' && !$localFound && $currentLocalKey !== $currentRemoteKey) $serverOptions[] = ['caption' => "$currentLocalKey (Not found)", 'value' => $currentLocalKey];

        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && ($element['name'] == 'RemoteServerKey' || $element['name'] == 'LocalServerKey')) {
                $element['options'] = $serverOptions;
            }
        }

        $listValues = $this->BuildSyncListAndCache();
        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] == 'SyncList') {
                $element['values'] = $listValues;
                break;
            }
        }

        return json_encode($form);
    }

    public function ToggleAll(string $Column, bool $State)
    {
        $newValues = $this->BuildSyncListAndCache($Column, $State);
        $this->UpdateFormField('SyncList', 'values', json_encode($newValues));
    }

    public function InstallRemoteScripts()
    {
        if (!$this->LoadConfig()) {
            echo "Error: Could not load configuration.";
            return;
        }
        if (!$this->InitConnection()) {
            echo "Error: Could not connect to remote server.";
            return;
        }

        $remoteRoot = $this->config['RemoteRootID'];
        if ($remoteRoot == 0) {
            echo "Error: Remote Root ID is 0.";
            return;
        }

        try {
            $systemCatID = 0;
            $children = @$this->rpcClient->IPS_GetChildrenIDs($remoteRoot);
            if (is_array($children)) {
                foreach ($children as $cID) {
                    $obj = $this->rpcClient->IPS_GetObject($cID);
                    if ($obj['ObjectType'] == 0 && $obj['ObjectName'] == 'RemoteSync System') {
                        $systemCatID = $cID;
                        break;
                    }
                }
            }

            if ($systemCatID == 0) {
                $systemCatID = $this->rpcClient->IPS_CreateCategory();
                $this->rpcClient->IPS_SetParent($systemCatID, $remoteRoot);
                $this->rpcClient->IPS_SetName($systemCatID, 'RemoteSync System');
                $this->rpcClient->IPS_SetIcon($systemCatID, 'Network');
            }

            $gatewayID = $this->FindRemoteScript($systemCatID, "RemoteSync_Gateway");
            $gatewayCode = $this->GenerateGatewayCode();
            $this->rpcClient->IPS_SetScriptContent($gatewayID, $gatewayCode);
            $this->WriteAttributeInteger('_RemoteGatewayID', $gatewayID);
            $this->LogDebug("Remote Gateway Script installed at ID $gatewayID");

            $receiverID = $this->FindRemoteScript($systemCatID, "RemoteSync_Receiver");
            $receiverCode = $this->GenerateReceiverCode($gatewayID); 
            $this->rpcClient->IPS_SetScriptContent($receiverID, $receiverCode);
            $this->WriteAttributeInteger('_RemoteReceiverID', $receiverID);
            $this->LogDebug("Remote Receiver Script installed at ID $receiverID");

            echo "Success: System initialized on remote server in 'RemoteSync System' folder.";

        } catch (Exception $e) {
            echo "Error installing scripts: " . $e->getMessage();
            $this->LogDebug("Install Error: " . $e->getMessage());
        }
    }

    private function FindRemoteScript($parentID, $name) {
        $children = @$this->rpcClient->IPS_GetChildrenIDs($parentID);
        if (is_array($children)) {
            foreach ($children as $cID) {
                $obj = $this->rpcClient->IPS_GetObject($cID);
                if ($obj['ObjectType'] == 3 && $obj['ObjectName'] == $name) {
                    return $cID;
                }
            }
        }
        $id = $this->rpcClient->IPS_CreateScript(0);
        $this->rpcClient->IPS_SetParent($id, $parentID);
        $this->rpcClient->IPS_SetName($id, $name);
        return $id;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        
        $this->rpcClient = null;
        $this->buffer = [];
        $this->isSending = false;

        $this->SetTimerInterval('BufferTimer', 0);
        $this->SetTimerInterval('StartSyncTimer', 0);

        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) $this->UnregisterMessage($senderID, VM_UPDATE);

        if (!$this->LoadConfig()) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $count = 0;
        if (is_array($this->config['SyncList'])) {
            foreach ($this->config['SyncList'] as $item) {
                if (!empty($item['Active']) && IPS_ObjectExists($item['ObjectID'])) {
                    $this->RegisterMessage($item['ObjectID'], VM_UPDATE);
                    $count++;
                }
            }
        }

        if ($this->config['LocalRootID'] == 0 || $this->config['LocalPasswordModuleID'] == 0 || $this->config['RemoteServerKey'] === '') {
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->SetStatus(IS_ACTIVE);
            $this->SetTimerInterval('StartSyncTimer', 250); 
            $this->LogDebug("ApplyChanges: Registered $count vars. Sync scheduled.");
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // DIAGNOSTIC LOG: Force output to see if this runs
        IPS_LogMessage("RemoteSync_DIAG", "Sink: Triggered by ID $SenderID");

        if ($Message == VM_UPDATE) {
            $this->AddToBuffer($SenderID);
        }
    }

    public function ProcessSync()
    {
        $this->SetTimerInterval('StartSyncTimer', 0);
        
        if (empty($this->config)) {
            if (!$this->LoadConfig()) return;
        }

        foreach ($this->config['SyncList'] as $item) {
            $this->AddToBuffer($item['ObjectID']);
        }
        
        $this->FlushBuffer();
    }

    private function AddToBuffer($localID)
    {
        if (empty($this->config)) {
            if (!$this->LoadConfig()) {
                IPS_LogMessage("RemoteSync_DIAG", "Buffer: Failed to load config.");
                return;
            }
        }

        $itemConfig = null;
        foreach ($this->config['SyncList'] as $item) {
            if ($item['ObjectID'] == $localID) {
                $itemConfig = $item;
                break;
            }
        }

        if (!$itemConfig) {
            IPS_LogMessage("RemoteSync_DIAG", "Buffer: ID $localID not found in active list.");
            return;
        }

        $payload = [
            'LocalID' => $localID,
            'Delete'  => !empty($itemConfig['Delete']) && empty($itemConfig['Active']),
            'Action'  => !empty($itemConfig['Action']),
            'Key'     => $this->config['LocalServerKey'] 
        ];

        if (!$payload['Delete']) {
            if (!IPS_ObjectExists($localID)) return;
            $var = IPS_GetVariable($localID);
            $payload['Value']   = GetValue($localID);
            $payload['Type']    = $var['VariableType'];
            $payload['Profile'] = $var['VariableCustomProfile'] ?: $var['VariableProfile'];
            $payload['Name']    = IPS_GetName($localID);
            $payload['Ident']   = IPS_GetObject($localID)['ObjectIdent']; 
            
            $pathStack = [];
            $currentID = $localID;
            $rootID = $this->config['LocalRootID'];
            while ($currentID != $rootID && $currentID > 0) {
                if ($currentID == 0) break;
                array_unshift($pathStack, IPS_GetName($currentID));
                $currentID = IPS_GetParent($currentID);
            }
            $payload['Path'] = $pathStack;
        }

        $this->buffer[$localID] = $payload;
        // IPS_LogMessage("RemoteSync_DIAG", "Buffer: Added ID $localID. Timer set.");
        $this->SetTimerInterval('BufferTimer', 200); 
    }

    public function FlushBuffer()
    {
        if ($this->isSending) return;
        
        $this->SetTimerInterval('BufferTimer', 0);
        if (empty($this->buffer)) return;

        $this->isSending = true;

        try {
            $receiverID = $this->ReadAttributeInteger('_RemoteReceiverID');
            if ($receiverID == 0) {
                $this->LogDebug("Error: Remote Scripts not installed.");
                return;
            }

            if (!$this->InitConnection()) throw new Exception("Connection Init failed");

            $batch = array_values($this->buffer);
            $this->buffer = []; 

            $this->LogDebug("Sending batch of " . count($batch) . " items...");
            $json = json_encode($batch);
            
            if ($json === false) throw new Exception("JSON Encode Failed: " . json_last_error_msg());

            $result = $this->rpcClient->IPS_RunScriptWaitEx($receiverID, ['DATA' => $json]);
            
            if (empty($result)) {
                $this->LogDebug("Warning: Remote script returned empty result. Check Remote Log.");
            } else {
                // $this->LogDebug("Remote Receiver Result: " . $result);
            }
            
        } catch (Exception $e) {
            $this->LogDebug("Batch Send Failed: " . $e->getMessage());
        } finally {
            $this->isSending = false;
            if (!empty($this->buffer)) {
                $this->FlushBuffer(); 
            }
        }
    }

    // --- CODE GENERATORS ---

    private function GenerateReceiverCode($gatewayID)
    {
        $gwID = (int)$gatewayID;

        return "<?php
/* RemoteSync Receiver */
\$data = \$_IPS['DATA'];

\$batch = json_decode(\$data, true);
\$gatewayID = $gwID;
\$rootID = IPS_GetParent(IPS_GetParent(\$_IPS['SELF'])); 

if (!is_array(\$batch)) {
    IPS_LogMessage('RemoteSync_RX', 'Error: Invalid JSON Batch');
    return;
}

foreach (\$batch as \$item) {
    try {
        \$localID = \$item['LocalID'];
        \$serverKey = \$item['Key'];
        \$safeIdent = \"Rem_\" . \$localID;
        \$refString = \"RS_REF:\" . \$serverKey . \":\" . \$localID;
        
        // 1. FIND
        \$remoteID = @IPS_GetObjectIDByIdent(\$safeIdent, \$rootID);
        
        // MIGRATION
        if (!\$remoteID) {
            \$currentParent = \$rootID;
            \$foundPath = true;
            foreach (\$item['Path'] as \$nodeName) {
                \$childID = @IPS_GetObjectIDByName(\$nodeName, \$currentParent);
                if (!\$childID) { \$foundPath = false; break; }
                \$currentParent = \$childID;
            }
            if (\$foundPath && \$currentParent != \$rootID) {
                \$remoteID = \$currentParent;
                IPS_SetIdent(\$remoteID, \$safeIdent);
                IPS_LogMessage('RemoteSync_RX', 'Migrated ' . \$nodeName);
            }
        }

        // 2. DELETE
        if (!empty(\$item['Delete'])) {
            if (\$remoteID > 0) {
                \$info = IPS_GetObject(\$remoteID)['ObjectInfo'];
                if (\$info === \$refString) {
                    \$children = IPS_GetChildrenIDs(\$remoteID);
                    foreach(\$children as \$c) IPS_DeleteObject(\$c);
                    IPS_DeleteObject(\$remoteID);
                    IPS_LogMessage('RemoteSync_RX', 'Deleted ID ' . \$remoteID);
                }
            }
            continue;
        }

        // 3. CREATE
        if (!\$remoteID) {
            \$currentParent = \$rootID;
            foreach (\$item['Path'] as \$index => \$nodeName) {
                \$childID = @IPS_GetObjectIDByName(\$nodeName, \$currentParent);
                if (!\$childID) {
                    if (\$index === count(\$item['Path']) - 1) {
                        \$childID = IPS_CreateVariable(\$item['Type']);
                        IPS_SetIdent(\$childID, \$safeIdent);
                    } else {
                        \$childID = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
                    }
                    IPS_SetParent(\$childID, \$currentParent);
                    IPS_SetName(\$childID, \$nodeName);
                }
                \$currentParent = \$childID;
            }
            \$remoteID = \$currentParent;
            IPS_LogMessage('RemoteSync_RX', 'Created New ID: ' . \$remoteID);
        }

        // 4. UPDATE
        if (\$remoteID) {
            IPS_SetInfo(\$remoteID, \$refString);
            SetValue(\$remoteID, \$item['Value']);
            
            if (!empty(\$item['Profile']) && IPS_VariableProfileExists(\$item['Profile'])) {
                 IPS_SetVariableCustomProfile(\$remoteID, \$item['Profile']);
            }

            \$children = IPS_GetChildrenIDs(\$remoteID);
            foreach (\$children as \$childID) {
                \$obj = IPS_GetObject(\$childID);
                if (\$obj['ObjectType'] == 3) IPS_DeleteScript(\$childID, true); 
            }

            if (!empty(\$item['Action'])) {
                IPS_SetVariableCustomAction(\$remoteID, \$gatewayID);
            } else {
                IPS_SetVariableCustomAction(\$remoteID, 0);
            }
        }
    } catch (Exception \$e) {
        IPS_LogMessage('RemoteSync_RX', 'Error Item ' . \$item['LocalID'] . ': ' . \$e->getMessage());
    }
}
echo 'Batch Complete';
?>";
    }

    private function GenerateGatewayCode()
    {
        $remSecID = $this->config['RemotePasswordModuleID'];
        $locKey = str_replace("'", "\\'", $this->config['LocalServerKey']);

        return "<?php
/* RemoteSync Gateway */
\$remoteVarID = \$_IPS['VARIABLE'];
\$info = IPS_GetObject(\$remoteVarID)['ObjectInfo']; 

\$parts = explode(':', \$info);
if (count(\$parts) < 3 || \$parts[0] !== 'RS_REF') die('Invalid Info');

\$targetKey = \$parts[1];
\$targetID = (int)\$parts[2];

\$secID = $remSecID;

if (!function_exists('SEC_GetSecret')) die('SEC Module missing');

\$json = SEC_GetSecret(\$secID, \$targetKey);
\$creds = json_decode(\$json, true);
\$url = \$creds['URL'] ?? \$creds['url'] ?? \$creds['Url'] ?? null;
\$user = \$creds['User'] ?? \$creds['user'] ?? \$creds['Username'] ?? null;
\$pw = \$creds['PW'] ?? \$creds['pw'] ?? \$creds['Password'] ?? null;

if (!\$url) die('Invalid Config');

\$connUrl = 'https://'.urlencode(\$user).':'.urlencode(\$pw).'@'.\$url.'/api/';

class MiniRPC {
    private \$url;
    public function __construct(\$url) { \$this->url = \$url; }
    public function __call(\$method, \$params) {
        \$payload = json_encode(['jsonrpc'=>'2.0', 'method'=>\$method, 'params'=>\$params, 'id'=>time()]);
        \$opts = ['http'=>['method'=>'POST', 'header'=>'Content-Type: application/json', 'content'=>\$payload, 'timeout'=>5], 'ssl'=>['verify_peer'=>false, 'verify_peer_name'=>false]];
        \$ctx = stream_context_create(\$opts);
        \$result = file_get_contents(\$this->url, false, \$ctx);
        if (\$result === false) throw new Exception('Connect Fail');
        \$response = json_decode(\$result, true);
        if (isset(\$response['error'])) throw new Exception(\$response['error']['message'], \$response['error']['code']);
        return \$response['result'];
    }
}

\$rpc = new MiniRPC(\$connUrl);

try {
    \$rpc->RequestAction(\$targetID, \$_IPS['VALUE']);
} catch (Exception \$e) {
    if (\$e->getCode() == -32603) {
        \$rpc->SetValue(\$targetID, \$_IPS['VALUE']);
    } else {
        IPS_LogMessage('RemoteSync_Gateway', 'Error: ' . \$e->getMessage());
    }
}
SetValue(\$_IPS['VARIABLE'], \$_IPS['VALUE']);
?>";
    }

    // --- HELPERS ---

    private function BuildSyncListAndCache($OverrideColumn = null, $OverrideState = null)
    {
        try {
            $rootID = @$this->ReadPropertyInteger('LocalRootID');
            $rawList = @$this->ReadPropertyString('SyncList');
            $savedListJSON = is_string($rawList) ? $rawList : '[]';
        } catch (Exception $e) { $rootID = 0; $savedListJSON = '[]'; }

        $savedList = json_decode($savedListJSON, true);
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
        if ($OverrideColumn !== null) {
             $cachedIDs = json_decode($this->ReadAttributeString('_SyncListCache'), true);
             if (!is_array($cachedIDs)) $cachedIDs = []; 
             $scannedIDs = $cachedIDs;
        } else {
             $scannedIDs = [];
             if ($rootID > 0 && IPS_ObjectExists($rootID)) {
                 $this->GetRecursiveVariables($rootID, $scannedIDs);
                 $this->WriteAttributeString('_SyncListCache', json_encode($scannedIDs));
             }
        }

        foreach ($scannedIDs as $varID) {
            if (!IPS_ObjectExists($varID)) continue;
            $isActive = $activeMap[$varID] ?? false;
            $isAction = $actionMap[$varID] ?? false;
            $isDelete = $deleteMap[$varID] ?? false;

            if ($OverrideColumn === 'Active') $isActive = $OverrideState;
            if ($OverrideColumn === 'Action') $isAction = $OverrideState;
            if ($OverrideColumn === 'Delete') $isDelete = $OverrideState;

            $values[] = ['ObjectID' => $varID, 'Name' => IPS_GetName($varID), 'Active' => $isActive, 'Action' => $isAction, 'Delete' => $isDelete];
        }
        return $values;
    }

    private function GetRecursiveVariables($parentID, &$result)
    {
        $children = IPS_GetChildrenIDs($parentID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] == 2) { $result[] = $childID; }
            if ($obj['HasChildren']) { $this->GetRecursiveVariables($childID, $result); }
        }
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
        } catch (Exception $e) { return false; }
    }

    private function InitConnection()
    {
        if ($this->rpcClient !== null) return true;
        $secID = $this->config['LocalPasswordModuleID'] ?? 0;
        $key = $this->config['RemoteServerKey'] ?? '';
        if ($secID == 0 || $key === '') return false;

        try {
            if (!function_exists('SEC_GetSecret')) return false;
            $json = SEC_GetSecret($secID, $key);
            $config = json_decode($json, true);
            if (!is_array($config)) return false;
            
            $url = $config['URL'] ?? $config['url'] ?? $config['Url'] ?? null;
            $user = $config['User'] ?? $config['user'] ?? $config['Username'] ?? null;
            $pw = $config['PW'] ?? $config['pw'] ?? $config['Password'] ?? null;
            
            if (!$url) return false;
            
            $connectionUrl = 'https://'.urlencode($user).":".urlencode($pw)."@".$url."/api/";
            $this->rpcClient = new RemoteSync_RPCClient($connectionUrl);
            return true;
        } catch (Exception $e) { return false; }
    }

    private function LogDebug($msg)
    {
        try {
            if (@$this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('RemoteSync', $msg);
        } catch (Exception $e) { }
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