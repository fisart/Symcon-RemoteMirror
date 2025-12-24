<?php

declare(strict_types=1);

class RemoteSync extends IPSModule
{
    private $rpcClient = null;
    private $config = [];
    private $buffer = []; 
    private $isInitializing = false;

    public function Create()
    {
        parent::Create();

        // Initialize properties
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
        $this->RegisterPropertyInteger('RemoteScriptRootID', 0); // New Shared Home
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

        // Safety Net
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

    // --- INSTALLATION (SHARED SCRIPTS) ---

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

        // Use the SHARED Script Root
        $scriptRoot = $this->config['RemoteScriptRootID'];
        if ($scriptRoot == 0) {
            echo "Error: Remote Script Home ID is 0. Please configure a shared folder on the remote server.";
            return;
        }

        try {
            // 0. Scan Shared Folder
            $existingScripts = [];
            try {
                $children = $this->rpcClient->IPS_GetChildrenIDs($scriptRoot);
                if (is_array($children)) {
                    foreach ($children as $childID) {
                        $obj = $this->rpcClient->IPS_GetObject($childID);
                        if ($obj['ObjectType'] == 3) {
                            $name = $obj['ObjectName'];
                            $existingScripts[$name] = $childID;
                        }
                    }
                }
            } catch (Exception $e) {
                $this->LogDebug("Warning: Could not scan script folder.");
            }

            // 1. Install/Reuse Gateway
            $gatewayName = "RemoteSync_Gateway";
            $gatewayID = 0;

            if (isset($existingScripts[$gatewayName])) {
                $gatewayID = $existingScripts[$gatewayName];
                $this->LogDebug("Reusing shared Gateway Script at ID $gatewayID.");
            } else {
                $gatewayID = $this->rpcClient->IPS_CreateScript(0);
                $this->rpcClient->IPS_SetParent($gatewayID, $scriptRoot);
                $this->rpcClient->IPS_SetName($gatewayID, $gatewayName);
                $this->rpcClient->IPS_SetHidden($gatewayID, true);
                $this->LogDebug("Created shared Gateway Script at ID $gatewayID.");
            }
            
            $gatewayCode = $this->GenerateGatewayCode();
            $this->rpcClient->IPS_SetScriptContent($gatewayID, $gatewayCode);
            $this->WriteAttributeInteger('_RemoteGatewayID', $gatewayID);


            // 2. Install/Reuse Receiver
            $receiverName = "RemoteSync_Receiver";
            $receiverID = 0;

            if (isset($existingScripts[$receiverName])) {
                $receiverID = $existingScripts[$receiverName];
                $this->LogDebug("Reusing shared Receiver Script at ID $receiverID.");
            } else {
                $receiverID = $this->rpcClient->IPS_CreateScript(0);
                $this->rpcClient->IPS_SetParent($receiverID, $scriptRoot);
                $this->rpcClient->IPS_SetName($receiverID, $receiverName);
                $this->rpcClient->IPS_SetHidden($receiverID, true);
                $this->LogDebug("Created shared Receiver Script at ID $receiverID.");
            }

            // Note: Receiver doesn't need Gateway ID hardcoded, it receives it dynamically or links it.
            // Actually, in the code generator below, I bind the Gateway ID. 
            // Since this script is shared, the Gateway ID must be the one *in the same folder*.
            $receiverCode = $this->GenerateReceiverCode($gatewayID);
            $this->rpcClient->IPS_SetScriptContent($receiverID, $receiverCode);
            $this->WriteAttributeInteger('_RemoteReceiverID', $receiverID);

            echo "Success: Shared Scripts updated in Category $scriptRoot.";

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
            $this->LogDebug("Install Error: " . $e->getMessage());
        }
    }

    // --- RUNTIME ---

    public function ApplyChanges()
    {
        $this->isInitializing = true;
        parent::ApplyChanges();
        
        $this->rpcClient = null;
        $this->buffer = [];

        $this->SetTimerInterval('BufferTimer', 0);
        $this->SetTimerInterval('StartSyncTimer', 0);

        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) $this->UnregisterMessage($senderID, VM_UPDATE);

        if (!$this->LoadConfig()) {
            $this->SetStatus(IS_INACTIVE);
            $this->isInitializing = false;
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
        }
        
        $this->isInitializing = false;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
        if ($this->isInitializing) return;

        $this->AddToBuffer($SenderID);
    }

    public function ProcessSync()
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
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
        if (empty($this->config)) $this->LoadConfig();

        $itemConfig = null;
        foreach ($this->config['SyncList'] as $item) {
            if ($item['ObjectID'] == $localID) {
                $itemConfig = $item;
                break;
            }
        }

        if (!$itemConfig) return;

        $payload = [
            'LocalID' => $localID,
            'Delete'  => !empty($itemConfig['Delete']) && empty($itemConfig['Active']),
            'Action'  => !empty($itemConfig['Action'])
        ];

        if (!$payload['Delete']) {
            if (!IPS_ObjectExists($localID)) return;
            
            $var = IPS_GetVariable($localID);
            $payload['Value']   = GetValue($localID);
            $payload['Type']    = $var['VariableType'];
            $payload['Profile'] = $var['VariableCustomProfile'] ?: $var['VariableProfile'];
            $payload['Name']    = IPS_GetName($localID);
            
            $pathStack = [];
            $currentID = $localID;
            $rootID = $this->config['LocalRootID'];
            
            while ($currentID != $rootID) {
                if ($currentID == 0) break;
                array_unshift($pathStack, IPS_GetName($currentID));
                $currentID = IPS_GetParent($currentID);
            }
            $payload['Path'] = $pathStack;
        }

        $this->buffer[$localID] = $payload;
        $this->SetTimerInterval('BufferTimer', 200);
    }

    public function FlushBuffer()
    {
        $this->SetTimerInterval('BufferTimer', 0);
        
        if (empty($this->buffer)) return;
        
        $receiverID = $this->ReadAttributeInteger('_RemoteReceiverID');
        if ($receiverID == 0) {
            $this->LogDebug("Error: Remote Scripts not installed.");
            return;
        }

        if (!$this->InitConnection()) return;

        $batch = array_values($this->buffer); 
        $this->buffer = []; 

        $this->LogDebug("Sending batch of " . count($batch) . " items...");

        try {
            // NEW PAYLOAD STRUCTURE: Include Target ID
            $packet = [
                'TargetID' => $this->config['RemoteRootID'],
                'Batch'    => $batch
            ];
            $json = json_encode($packet);
            
            $this->rpcClient->IPS_RunScriptWaitEx($receiverID, ['DATA' => $json]);
            $this->LogDebug("Batch Sent Successfully.");
        } catch (Exception $e) {
            $this->LogDebug("Batch Send Failed: " . $e->getMessage());
        }
    }

    // --- CODE GENERATORS (UPDATED) ---

    private function GenerateReceiverCode($gatewayID)
    {
        return "<?php
/* RemoteSync Receiver - Shared Script */
\$data = \$_IPS['DATA'];
\$input = json_decode(\$data, true);

// Extract Target Root and Batch
\$targetRoot = \$input['TargetID'] ?? 0;
\$batch = \$input['Batch'] ?? [];
\$gatewayID = $gatewayID;

if (!is_array(\$batch) || \$targetRoot == 0) return;

foreach (\$batch as \$item) {
    \$localID = \$item['LocalID'];
    
    // DELETE
    if (!empty(\$item['Delete'])) {
        \$oldID = @IPS_GetObjectIDByIdent((string)\$localID, \$targetRoot);
        if (\$oldID) {
            \$children = IPS_GetChildrenIDs(\$oldID);
            foreach(\$children as \$c) IPS_DeleteObject(\$c);
            IPS_DeleteObject(\$oldID);
            IPS_LogMessage('RemoteSync_RX', 'Deleted ID ' . \$oldID);
        }
        continue;
    }

    // FIND / CREATE
    \$remoteID = @IPS_GetObjectIDByIdent((string)\$localID, \$targetRoot);
    
    if (!\$remoteID) {
        // Migration Check (Path)
        \$currentParent = \$targetRoot;
        \$foundPath = true;
        foreach (\$item['Path'] as \$nodeName) {
            \$childID = @IPS_GetObjectIDByName(\$nodeName, \$currentParent);
            if (!\$childID) { \$foundPath = false; break; }
            \$currentParent = \$childID;
        }
        
        if (\$foundPath && \$currentParent != \$targetRoot) {
            \$remoteID = \$currentParent;
            IPS_SetIdent(\$remoteID, (string)\$localID);
            IPS_LogMessage('RemoteSync_RX', 'Migrated Variable ' . \$nodeName . ' to Ident ' . \$localID);
        }
    }

    if (!\$remoteID) {
        // Create New
        \$currentParent = \$targetRoot;
        foreach (\$item['Path'] as \$index => \$nodeName) {
            \$childID = @IPS_GetObjectIDByName(\$nodeName, \$currentParent);
            if (!\$childID) {
                if (\$index === count(\$item['Path']) - 1) {
                    \$childID = IPS_CreateVariable(\$item['Type']);
                    IPS_SetIdent(\$childID, (string)\$localID);
                } else {
                    \$childID = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
                }
                IPS_SetParent(\$childID, \$currentParent);
                IPS_SetName(\$childID, \$nodeName);
            }
            \$currentParent = \$childID;
        }
        \$remoteID = \$currentParent;
    }

    // UPDATE
    if (\$remoteID) {
        SetValue(\$remoteID, \$item['Value']);
        
        if (!empty(\$item['Profile'])) {
             if (IPS_VariableProfileExists(\$item['Profile'])) {
                 IPS_SetVariableCustomProfile(\$remoteID, \$item['Profile']);
             }
        }

        if (!empty(\$item['Action'])) {
            IPS_SetVariableCustomAction(\$remoteID, \$gatewayID);
        } else {
            IPS_SetVariableCustomAction(\$remoteID, 0);
        }
    }
}
?>";
    }

    private function GenerateGatewayCode()
    {
        $remSecID = $this->config['RemotePasswordModuleID'];
        $locKey = str_replace("'", "\\'", $this->config['LocalServerKey']);

        return "<?php
/* RemoteSync Gateway - Shared Script */
\$remoteVarID = \$_IPS['VARIABLE'];
\$targetID = (int)IPS_GetObject(\$remoteVarID)['ObjectIdent'];

\$secID = $remSecID;
\$key = '$locKey';

if (\$targetID == 0) die('Error: Ident invalid');
if (!function_exists('SEC_GetSecret')) die('SEC Module missing');

\$json = SEC_GetSecret(\$secID, \$key);
\$creds = json_decode(\$json, true);
\$url = \$creds['URL'] ?? \$creds['url'] ?? \$creds['Url'] ?? null;
\$user = \$creds['User'] ?? \$creds['user'] ?? \$creds['Username'] ?? null;
\$pw = \$creds['PW'] ?? \$creds['pw'] ?? \$creds['Password'] ?? null;

if (!\$url) die('Invalid config');

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
                'RemoteScriptRootID'    => @$this->ReadPropertyInteger('RemoteScriptRootID'),
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