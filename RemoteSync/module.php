<?php

declare(strict_types=1);

class RemoteSync extends IPSModule
{
    private $rpcClient = null;
    private $config = [];
    private $buffer = []; 
    // We rely on attribute locking for state management
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
        $this->RegisterPropertyInteger('RemoteScriptRootID', 0); // New Property
        $this->RegisterPropertyString('SyncList', '[]');
        
        $this->RegisterAttributeString('_SyncListCache', '[]');
        $this->RegisterAttributeInteger('_RemoteReceiverID', 0);
        $this->RegisterAttributeInteger('_RemoteGatewayID', 0);
        
        $this->RegisterAttributeString('_BatchBuffer', '[]');
        $this->RegisterAttributeBoolean('_IsSending', false);
        
        $this->RegisterTimer('StartSyncTimer', 0, 'RS_ProcessSync($_IPS[\'TARGET\']);');
        $this->RegisterTimer('BufferTimer', 0, 'RS_FlushBuffer($_IPS[\'TARGET\']);');

        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyBoolean('AutoCreate', true);
        $this->RegisterPropertyBoolean('ReplicateProfiles', true); // NEW

    }

    // --- FORM & UI ---
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

    // --- INSTALLATION ---
    public function InstallRemoteScripts()
    {
        if (!$this->LoadConfig()) return;
        if (!$this->InitConnection()) return;

        $remoteRoot = $this->config['RemoteRootID'];
        $scriptRoot = $this->config['RemoteScriptRootID']; // Use Shared Folder

        if ($remoteRoot == 0) {
            echo "Error: Remote Data Target ID is 0.";
            return;
        }
        if ($scriptRoot == 0) {
            echo "Error: Remote Script Home ID is 0. Please select a folder on the remote server for scripts.";
            return;
        }

        try {
            // Install into Shared Script Root
            $gatewayID = $this->FindRemoteScript($scriptRoot, "RemoteSync_Gateway");
            $gatewayCode = $this->GenerateGatewayCode();
            $this->rpcClient->IPS_SetScriptContent($gatewayID, $gatewayCode);
            $this->WriteAttributeInteger('_RemoteGatewayID', $gatewayID);
            $this->LogDebug("Remote Gateway Script installed/updated at ID $gatewayID");

            $receiverID = $this->FindRemoteScript($scriptRoot, "RemoteSync_Receiver");
            $receiverCode = $this->GenerateReceiverCode($gatewayID); 
            $this->rpcClient->IPS_SetScriptContent($receiverID, $receiverCode);
            $this->WriteAttributeInteger('_RemoteReceiverID', $receiverID);
            $this->LogDebug("Remote Receiver Script installed/updated at ID $receiverID");

            echo "Success: Shared Scripts installed.";

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }

    private function FindRemoteScript($parentID, $name) {
        $children = @$this->rpcClient->IPS_GetChildrenIDs($parentID);
        if (is_array($children)) {
            foreach ($children as $cID) {
                $obj = $this->rpcClient->IPS_GetObject($cID);
                if ($obj['ObjectType'] == 3 && $obj['ObjectName'] == $name) return $cID;
            }
        }
        $id = $this->rpcClient->IPS_CreateScript(0);
        $this->rpcClient->IPS_SetParent($id, $parentID);
        $this->rpcClient->IPS_SetName($id, $name);
        // We do not hide scripts in the shared folder so the user can find them easily
        return $id;
    }

    // --- RUNTIME ---

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        
        $this->rpcClient = null;
        
        // Reset Persistent States
        $this->WriteAttributeBoolean('_IsSending', false); 
        $this->WriteAttributeString('_BatchBuffer', '[]'); 

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
        $this->LogDebug("Sink: Triggered by ID $SenderID");
        $this->AddToBuffer($SenderID);
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
                $this->LogDebug("Buffer Error: LoadConfig() failed.");
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
            $this->LogDebug("Buffer Error: ID $localID not found in active SyncList.");
            return;
        }

        // 1. READ BUFFER from Attribute
        $rawBuffer = $this->ReadAttributeString('_BatchBuffer');
        $buffer = json_decode($rawBuffer, true);
        if (!is_array($buffer)) $buffer = [];

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
                array_unshift($pathStack, IPS_GetName($currentID));
                $currentID = IPS_GetParent($currentID);
            }
            $payload['Path'] = $pathStack;
        }

        // 2. UPDATE BUFFER
        $buffer[$localID] = $payload; 

        // 3. WRITE BUFFER
        $this->WriteAttributeString('_BatchBuffer', json_encode($buffer));

        $this->LogDebug("Buffer: Added ID $localID. Timer set.");
        $this->SetTimerInterval('BufferTimer', 200); 
    }

    public function FlushBuffer()
    {
        // 1. Check Lock (Attribute)
        if ($this->ReadAttributeBoolean('_IsSending')) {
            $this->LogDebug("Flush: Skipped (Busy)");
            return;
        }
        
        $this->SetTimerInterval('BufferTimer', 0);
        
        // 2. Read Buffer
        $rawBuffer = $this->ReadAttributeString('_BatchBuffer');
        $buffer = json_decode($rawBuffer, true);

        if (empty($buffer)) return;

        // 3. Set Lock
        $this->WriteAttributeBoolean('_IsSending', true);

        try {
            // RELOAD CONFIG - Important for Timer context
            if (empty($this->config)) {
                if (!$this->LoadConfig()) throw new Exception("Config Reload Failed");
            }

            $receiverID = $this->ReadAttributeInteger('_RemoteReceiverID');
            if ($receiverID == 0) {
                $this->LogDebug("Error: Remote Scripts not installed.");
                // Clear buffer to prevent endless loops
                $this->WriteAttributeString('_BatchBuffer', '[]'); 
                return;
            }

            if (!$this->InitConnection()) throw new Exception("Connection Init failed");

            // Extract values and CLEAR attribute immediately
            $batch = array_values($buffer);

            // Build profile definitions (optional)
            $profiles = [];
            if (!empty($this->config['ReplicateProfiles'])) {
                foreach ($batch as $item) {
                    if (empty($item['Profile'])) {
                        continue;
                    }
                    $profileName = $item['Profile'];
                    if (isset($profiles[$profileName])) {
                        continue;
                    }
                    // Only collect if the profile exists locally
                    if (@IPS_VariableProfileExists($profileName)) {
                        $def = @IPS_GetVariableProfile($profileName);
                        if (is_array($def)) {
                            // Send raw definition as returned by IPS_GetVariableProfile
                            $profiles[$profileName] = $def;
                        }
                    }
                }
            }

            // Clear buffer attribute now that we have the batch
            $this->WriteAttributeString('_BatchBuffer', '[]'); 

            $this->LogDebug("Sending batch of " . count($batch) . " items...");

            // New Packet Structure with Profiles
            $packet = [
                'TargetID'   => $this->config['RemoteRootID'],
                'Batch'      => $batch,
                'AutoCreate' => (bool)$this->config['AutoCreate'],
                'Profiles'   => $profiles
            ];

            $json = json_encode($packet);
            
            if ($json === false) throw new Exception("JSON Encode Failed");

            $result = $this->rpcClient->IPS_RunScriptWaitEx($receiverID, ['DATA' => $json]);
            
            if (!empty($result)) {
               $this->LogDebug("Remote Result: " . $result);
            }
            
        } catch (Exception $e) {
            $this->LogDebug("Batch Send Failed: " . $e->getMessage());
        } finally {
            // 4. Release Lock
            $this->WriteAttributeBoolean('_IsSending', false);
            
            // 5. Check if new data arrived while sending
            $currentBuffer = $this->ReadAttributeString('_BatchBuffer');
            if ($currentBuffer !== '[]' && $currentBuffer !== '') {
                $this->SetTimerInterval('BufferTimer', 100); 
            }
        }
    }

    // --- CODE GENERATORS ---

private function GenerateReceiverCode($gatewayID)
{
    $gwID = (int)$gatewayID;

    return "<?php
/* RemoteSync Receiver */

\$data   = \$_IPS['DATA'] ?? '';
\$packet = json_decode(\$data, true);

if (!is_array(\$packet)) {
    return;
}

// Extract Packet Info
\$batch      = \$packet['Batch'] ?? [];
\$rootID     = \$packet['TargetID'] ?? 0;
\$autoCreate = !empty(\$packet['AutoCreate']); 
\$gatewayID  = $gwID;

// Optional profile definitions from local
\$profiles = \$packet['Profiles'] ?? [];

if (!is_array(\$batch) || \$rootID == 0) {
    return;
}

// --- Create missing profiles (if provided) ---
if (is_array(\$profiles)) {
    foreach (\$profiles as \$pName => \$pDef) {
        if (!is_string(\$pName) || \$pName === '') {
            continue;
        }
        if (IPS_VariableProfileExists(\$pName) || !is_array(\$pDef)) {
            continue;
        }

        // IPS_GetVariableProfile() structure (local side) typically:
        // ProfileName, ProfileType, Icon, Prefix, Suffix,
        // MinValue, MaxValue, StepSize, Digits, Associations[]
        \$type = \$pDef['ProfileType'] ?? 0;

        @IPS_CreateVariableProfile(\$pName, \$type);

        if (isset(\$pDef['Icon'])) {
            @IPS_SetVariableProfileIcon(\$pName, \$pDef['Icon']);
        }

        \$prefix = \$pDef['Prefix'] ?? '';
        \$suffix = \$pDef['Suffix'] ?? '';
        @IPS_SetVariableProfileText(\$pName, \$prefix, \$suffix);

        \$min  = \$pDef['MinValue'] ?? 0;
        \$max  = \$pDef['MaxValue'] ?? 0;
        \$step = \$pDef['StepSize'] ?? 0;
        @IPS_SetVariableProfileValues(\$pName, \$min, \$max, \$step);

        if (isset(\$pDef['Digits'])) {
            @IPS_SetVariableProfileDigits(\$pName, \$pDef['Digits']);
        }

        if (!empty(\$pDef['Associations']) && is_array(\$pDef['Associations'])) {
            foreach (\$pDef['Associations'] as \$assoc) {
                if (!is_array(\$assoc)) {
                    continue;
                }
                if (!array_key_exists('Value', \$assoc)) {
                    continue;
                }
                \$value = \$assoc['Value'];
                \$name  = \$assoc['Name']  ?? '';
                \$icon  = \$assoc['Icon']  ?? '';
                \$color = \$assoc['Color'] ?? 0;
                @IPS_SetVariableProfileAssociation(\$pName, \$value, \$name, \$icon, \$color);
            }
        }

        IPS_LogMessage('RemoteSync_RX', 'Created profile ' . \$pName);
    }
}

// --- HELPER FOR TYPE-SAFE DELETION ---
if (!function_exists('RS_RX_DeleteRecursive')) {
    function RS_RX_DeleteRecursive(\$id) {
        if (!IPS_ObjectExists(\$id)) return;
        
        // Delete children first
        foreach (IPS_GetChildrenIDs(\$id) as \$childID) {
            RS_RX_DeleteRecursive(\$childID);
        }
        
        // Delete self based on type
        \$type = IPS_GetObject(\$id)['ObjectType'];
        switch (\$type) {
            case 0: @IPS_DeleteCategory(\$id); break;
            case 1: @IPS_DeleteInstance(\$id); break;
            case 2: @IPS_DeleteVariable(\$id); break;
            case 3: @IPS_DeleteScript(\$id, true); break;
            case 4: @IPS_DeleteEvent(\$id); break;
            case 5: @IPS_DeleteMedia(\$id, true); break;
            case 6: @IPS_DeleteLink(\$id); break;
        }
    }
}

foreach (\$batch as \$item) {
    try {
        \$localID   = \$item['LocalID'];
        \$serverKey = \$item['Key'];
        \$safeIdent = 'Rem_' . \$localID;
        \$refString = 'RS_REF:' . \$serverKey . ':' . \$localID;

        // 1. FIND
        \$remoteID = @IPS_GetObjectIDByIdent(\$safeIdent, \$rootID);

        // Migration Fallback
        if (!\$remoteID && !empty(\$item['Path']) && is_array(\$item['Path'])) {
            \$currentParent = \$rootID;
            \$foundPath     = true;
            foreach (\$item['Path'] as \$nodeName) {
                \$childID = @IPS_GetObjectIDByName(\$nodeName, \$currentParent);
                if (!\$childID) {
                    \$foundPath = false;
                    break;
                }
                \$currentParent = \$childID;
            }
            if (\$foundPath && \$currentParent != \$rootID) {
                \$remoteID = \$currentParent;
                IPS_SetIdent(\$remoteID, \$safeIdent);
            }
        }

        // 2. DELETE (Type-Safe Fix Applied)
        if (!empty(\$item['Delete'])) {
            if (\$remoteID > 0) {
                \$info = IPS_GetObject(\$remoteID)['ObjectInfo'];
                if (\$info === \$refString) {
                    RS_RX_DeleteRecursive(\$remoteID);
                    IPS_LogMessage('RemoteSync_RX', 'Deleted ID ' . \$remoteID);
                }
            }
            continue;
        }

        // 3. CREATE
        if (!\$remoteID) {
            if (!\$autoCreate) continue;

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
                if (\$obj['ObjectType'] == 3) {
                    IPS_DeleteScript(\$childID, true);
                }
            }

            if (!empty(\$item['Action'])) {
                IPS_SetVariableCustomAction(\$remoteID, \$gatewayID);
            } else {
                IPS_SetVariableCustomAction(\$remoteID, 0);
            }
        }
    } catch (Exception \$e) {
        IPS_LogMessage('RemoteSync_RX', 'Error Item ' . (\$item['LocalID'] ?? 'unknown') . ': ' . \$e->getMessage());
    }
}
?>";
}


private function GenerateGatewayCode()
{
    $remSecID = (int)$this->config['RemotePasswordModuleID'];

    return "<?php
/* RemoteSync Gateway */

if (!isset(\$_IPS['VARIABLE']) || !array_key_exists('VALUE', \$_IPS)) {
    return;
}

\$remoteVarID = \$_IPS['VARIABLE'];
\$info       = IPS_GetObject(\$remoteVarID)['ObjectInfo'];

// Expected format: RS_REF:ServerKey:LocalID
\$parts = explode(':', \$info);
if (count(\$parts) < 3 || \$parts[0] !== 'RS_REF') {
    IPS_LogMessage('RemoteSync_Gateway', 'Invalid ObjectInfo: ' . \$info);
    return;
}

\$targetKey = \$parts[1];
\$targetID  = (int)\$parts[2];

\$secID = $remSecID;

if (!function_exists('SEC_GetSecret')) {
    IPS_LogMessage('RemoteSync_Gateway', 'SEC Module missing');
    return;
}

\$json  = SEC_GetSecret(\$secID, \$targetKey);
\$creds = json_decode(\$json, true);

\$url  = \$creds['URL'] ?? \$creds['url'] ?? \$creds['Url'] ?? null;
\$user = \$creds['User'] ?? \$creds['user'] ?? \$creds['Username'] ?? null;
\$pw   = \$creds['PW'] ?? \$creds['pw'] ?? \$creds['Password'] ?? null;

if (!\$url || !\$user || !\$pw) {
    IPS_LogMessage('RemoteSync_Gateway', 'Invalid config for key ' . \$targetKey);
    return;
}

\$connUrl = 'https://' . urlencode(\$user) . ':' . urlencode(\$pw) . '@' . \$url . '/api/';

class RemoteSync_MiniRPC
{
    private string \$url;

    public function __construct(string \$url)
    {
        \$this->url = \$url;
    }

    public function __call(string \$method, array \$params)
    {
        \$payload = json_encode([
            'jsonrpc' => '2.0',
            'method'  => \$method,
            'params'  => \$params,
            'id'      => time()
        ]);

        \$opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/json',
                'content' => \$payload,
                'timeout' => 5
            ],
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false
            ]
        ];

        \$ctx    = stream_context_create(\$opts);
        \$result = @file_get_contents(\$this->url, false, \$ctx);

        if (\$result === false) {
            throw new Exception('Connect Fail');
        }

        \$response = json_decode(\$result, true);
        if (isset(\$response['error'])) {
            throw new Exception(\$response['error']['message'], \$response['error']['code'] ?? 0);
        }

        return \$response['result'] ?? null;
    }
}

\$rpc = new RemoteSync_MiniRPC(\$connUrl);

try {
    \$rpc->RequestAction(\$targetID, \$_IPS['VALUE']);
} catch (Exception \$e) {
    // -32603 typically means 'no action handler'
    if (\$e->getCode() == -32603) {
        try {
            \$rpc->SetValue(\$targetID, \$_IPS['VALUE']);
        } catch (Exception \$e2) {
            IPS_LogMessage('RemoteSync_Gateway', 'SetValue failed: ' . \$e2->getMessage());
        }
    } else {
        IPS_LogMessage('RemoteSync_Gateway', 'Error: ' . \$e->getMessage());
    }
}

// Mirror value locally as well
SetValue(\$remoteVarID, \$_IPS['VALUE']);
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
                'ReplicateProfiles'     => @$this->ReadPropertyBoolean('ReplicateProfiles'), // NEW
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