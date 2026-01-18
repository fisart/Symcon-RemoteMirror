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

        // --- ORIGINAL PROPERTIES ---
        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyBoolean('AutoCreate', true);
        $this->RegisterPropertyInteger('LocalPasswordModuleID', 0);
        $this->RegisterPropertyString('RemoteServerKey', '');
        $this->RegisterPropertyInteger('RemotePasswordModuleID', 0);
        $this->RegisterPropertyString('LocalServerKey', '');
        $this->RegisterPropertyInteger('LocalRootID', 0);
        $this->RegisterPropertyInteger('RemoteRootID', 0);
        $this->RegisterPropertyInteger('RemoteScriptRootID', 0);
        $this->RegisterPropertyString('SyncList', '[]');
        $this->RegisterPropertyBoolean('ReplicateProfiles', true);

        // --- NEU: MANAGER PROPERTIES ---
        $this->RegisterPropertyString("Targets", "[]");
        $this->RegisterPropertyString("Roots", "[]");

        // --- ORIGINAL ATTRIBUTES ---
        $this->RegisterAttributeString('_SyncListCache', '[]');
        $this->RegisterAttributeInteger('_RemoteReceiverID', 0);
        $this->RegisterAttributeInteger('_RemoteGatewayID', 0);
        $this->RegisterAttributeString('_BatchBuffer', '[]');
        $this->RegisterAttributeBoolean('_IsSending', false);

        // --- NEU: MANAGER ATTRIBUTES (Blueprint-Speicher) ---
        $this->RegisterAttributeString("SyncListCache", "[]");

        // --- TIMERS ---
        $this->RegisterTimer('StartSyncTimer', 0, 'RS_ProcessSync($_IPS[\'TARGET\']);');
        $this->RegisterTimer('BufferTimer', 0, 'RS_FlushBuffer($_IPS[\'TARGET\']);');
    }

    // --- FORM & UI ---
    public function GetConfigurationForm()
    {
        // Sicherstellen, dass der RAM-Arbeitsspeicher beim Start gefüllt ist
        if ($this->ReadAttributeString("SyncListCache") === "[]") {
            $this->WriteAttributeString("SyncListCache", $this->ReadPropertyString("SyncList"));
        }

        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Statische Footer-Buttons (z.B. Refresh) aus der Datei zwischenspeichern
        $staticFooter = $form['actions'];
        $form['actions'] = [];

        $secID = $this->ReadPropertyInteger('LocalPasswordModuleID');
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $savedSync = json_decode($this->ReadAttributeString("SyncListCache"), true);

        // 1. SEC-Keys für Dropdowns abrufen
        $serverOptions = [['caption' => "Please select...", 'value' => ""]];
        if ($secID > 0 && IPS_InstanceExists($secID) && function_exists('SEC_GetKeys')) {
            $keys = json_decode(SEC_GetKeys($secID), true);
            if (is_array($keys)) foreach ($keys as $k) $serverOptions[] = ['caption' => (string)$k, 'value' => (string)$k];
        }

        // 2. Folder-Namen für Dropdowns abrufen
        $folderOptions = [['caption' => "Select Target Folder...", 'value' => ""]];
        foreach ($targets as $t) if (!empty($t['Name'])) $folderOptions[] = ['caption' => $t['Name'], 'value' => $t['Name']];

        // 3. Statische Elemente (Schritt 1 & 2) mit Dropdowns befüllen
        $this->UpdateStaticFormElements($form['elements'], $serverOptions, $folderOptions);

        // 4. Cache-Mapping für schnellen Zugriff auf Checkbox-Zustände
        $stateCache = [];
        if (is_array($savedSync)) {
            foreach ($savedSync as $item) {
                if (isset($item['Folder'], $item['ObjectID'])) {
                    $stateCache[$item['Folder'] . '_' . $item['ObjectID']] = $item;
                }
            }
        }

        // 5. Dynamische Generierung der Sektion 'actions' (Schritt 3)
        foreach ($targets as $target) {
            if (empty($target['Name'])) continue;
            $folderName = $target['Name'];
            $syncValues = [];

            // Alle Variablen finden, die diesem Folder über Schritt 2 zugeordnet sind
            foreach ($roots as $root) {
                if (isset($root['TargetFolder']) && $root['TargetFolder'] === $folderName && isset($root['LocalRootID']) && $root['LocalRootID'] > 0 && IPS_ObjectExists($root['LocalRootID'])) {
                    $foundVars = [];
                    $this->GetRecursiveVariables($root['LocalRootID'], $foundVars);
                    foreach ($foundVars as $vID) {
                        $key = $folderName . '_' . $vID;
                        $syncValues[] = [
                            "Folder"   => $folderName,
                            "ObjectID" => $vID,
                            "Name"     => IPS_GetName($vID),
                            "Active"   => $stateCache[$key]['Active'] ?? false,
                            "Action"   => $stateCache[$key]['Action'] ?? false,
                            "Delete"   => $stateCache[$key]['Delete'] ?? false
                        ];
                    }
                }
            }

            $listName = "List_" . md5($folderName);
            // BLUEPRINT: Datenübertragung bei Einzelklick via RequestAction
            $onEdit = "IPS_RequestAction(\$id, 'UpdateRow', json_encode(\$$listName));";

            $form['actions'][] = [
                "type"    => "ExpansionPanel",
                "caption" => "TARGET SELECTION: " . strtoupper($folderName) . " (" . count($syncValues) . " Variables)",
                "items"   => [
                    [
                        "type" => "RowLayout",
                        "items" => [
                            ["type" => "Button", "caption" => "Sync ALL", "onClick" => "RS_ToggleAll(\$id, 'Active', true, '$folderName');", "width" => "90px"],
                            ["type" => "Button", "caption" => "Sync NONE", "onClick" => "RS_ToggleAll(\$id, 'Active', false, '$folderName');", "width" => "90px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],
                            ["type" => "Button", "caption" => "Action ALL", "onClick" => "RS_ToggleAll(\$id, 'Action', true, '$folderName');", "width" => "90px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],
                            ["type" => "Button", "caption" => "💾 SAVE ALL SETS", "onClick" => "RS_SaveSelections(\$id);", "width" => "130px", "confirm" => "Save all pending changes for all targets?"],
                            ["type" => "Button", "caption" => "INSTALL REMOTE", "onClick" => "RS_InstallRemoteScripts(\$id, '$folderName');"]
                        ]
                    ],
                    [
                        "type" => "List",
                        "name" => $listName,
                        "rowCount" => min(count($syncValues) + 1, 15),
                        "add" => false,
                        "delete" => false,
                        "onEdit" => $onEdit,
                        "columns" => [
                            ["name" => "ObjectID", "caption" => "ID", "width" => "70px"],
                            ["name" => "Name", "caption" => "Variable Name", "width" => "auto"],
                            ["name" => "Active", "caption" => "Sync", "width" => "60px", "edit" => ["type" => "CheckBox"]],
                            ["name" => "Action", "caption" => "R-Action", "width" => "70px", "edit" => ["type" => "CheckBox"]],
                            ["name" => "Delete", "caption" => "Del Rem.", "width" => "80px", "edit" => ["type" => "CheckBox"]]
                        ],
                        "values" => $syncValues
                    ]
                ]
            ];
        }

        // Globalen Footer wieder anhängen
        foreach ($staticFooter as $btn) {
            $form['actions'][] = $btn;
        }

        return json_encode($form);
    }

    // Hilfsfunktion zum rekursiven Befüllen der statischen Formular-Elemente
    private function UpdateStaticFormElements(&$elements, $serverOptions, $folderOptions): void
    {
        foreach ($elements as &$element) {
            if (isset($element['items'])) $this->UpdateStaticFormElements($element['items'], $serverOptions, $folderOptions);
            if (!isset($element['name'])) continue;
            if ($element['name'] === 'LocalServerKey') $element['options'] = $serverOptions;
            if ($element['name'] === 'Targets') {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'RemoteKey') $col['edit']['options'] = $serverOptions;
                }
            }
            if ($element['name'] === 'Roots') {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'TargetFolder') $col['edit']['options'] = $folderOptions;
                }
            }
        }
    }

    public function ToggleAll(string $Column, bool $State, string $Folder)
    {
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $data = json_decode($this->ReadAttributeString("SyncListCache"), true);
        if (!is_array($data)) $data = [];

        $map = [];
        foreach ($data as $item) {
            $map[$item['Folder'] . '_' . $item['ObjectID']] = $item;
        }

        $uiValues = [];
        foreach ($roots as $root) {
            if (($root['TargetFolder'] ?? '') === $Folder && ($root['LocalRootID'] ?? 0) > 0) {
                $foundVars = [];
                $this->GetRecursiveVariables((int)$root['LocalRootID'], $foundVars);
                foreach ($foundVars as $vID) {
                    $key = $Folder . '_' . $vID;
                    if (!isset($map[$key])) {
                        $map[$key] = [
                            "Folder" => $Folder,
                            "ObjectID" => $vID,
                            "Name" => IPS_GetName($vID),
                            "Active" => false,
                            "Action" => false,
                            "Delete" => false
                        ];
                    }
                    $map[$key][$Column] = $State;
                    $uiValues[] = $map[$key];
                }
            }
        }

        // RAM-Speicher aktualisieren
        $this->WriteAttributeString("SyncListCache", json_encode(array_values($map)));

        // UI der betroffenen Liste sofort aktualisieren
        $this->UpdateFormField("List_" . md5($Folder), "values", json_encode($uiValues));
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

    private function FindRemoteScript($parentID, $name)
    {
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

        // Zustände zurücksetzen (Original-Logik)
        $this->WriteAttributeBoolean('_IsSending', false);
        $this->WriteAttributeString('_BatchBuffer', '[]');

        // NEU: RAM-Cache für UI initialisieren (Blueprint)
        $this->WriteAttributeString("SyncListCache", $this->ReadPropertyString("SyncList"));

        $this->SetTimerInterval('BufferTimer', 0);
        $this->SetTimerInterval('StartSyncTimer', 0);

        // Alle alten Nachrichten-Registrierungen löschen (Original-Logik)
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) $this->UnregisterMessage($senderID, VM_UPDATE);

        // NEU: Variablen aus der konsolidierten Manager-Liste registrieren
        $syncList = json_decode($this->ReadPropertyString("SyncList"), true);
        $count = 0;
        if (is_array($syncList)) {
            foreach ($syncList as $item) {
                if (!empty($item['Active']) && isset($item['ObjectID']) && IPS_ObjectExists((int)$item['ObjectID'])) {
                    $this->RegisterMessage((int)$item['ObjectID'], VM_UPDATE);
                    $count++;
                }
            }
        }

        // Status-Management (Original-Logik angepasst auf count)
        if ($count === 0 && $this->ReadPropertyInteger('LocalPasswordModuleID') == 0) {
            $this->SetStatus(104); // Inaktiv
        } else {
            $this->SetStatus(102); // Aktiv
            if ($count > 0) $this->SetTimerInterval('StartSyncTimer', 250);
            $this->LogDebug("ApplyChanges: Registered $count variables across all targets.");
        }
    }
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "UpdateRow":
                $row = json_decode($Value, true);
                if (!$row || !isset($row['Folder'], $row['ObjectID'])) return;

                // Aktuellen RAM-Stand laden
                $cache = json_decode($this->ReadAttributeString("SyncListCache"), true);
                if (!is_array($cache)) $cache = [];

                $map = [];
                foreach ($cache as $item) {
                    $map[$item['Folder'] . '_' . $item['ObjectID']] = $item;
                }

                // Geänderte Zeile in die Map einfügen/aktualisieren
                $key = $row['Folder'] . '_' . $row['ObjectID'];
                $map[$key] = [
                    "Folder"   => $row['Folder'],
                    "ObjectID" => $row['ObjectID'],
                    "Name"     => $row['Name'],
                    "Active"   => $row['Active'],
                    "Action"   => $row['Action'],
                    "Delete"   => $row['Delete']
                ];

                // Zurück in den RAM-Speicher (Attribut) schreiben
                $this->WriteAttributeString("SyncListCache", json_encode(array_values($map)));
                break;
        }
    }

    public function SaveSelections()
    {
        // Den Stand aus dem RAM nehmen und permanent speichern
        $data = $this->ReadAttributeString("SyncListCache");
        IPS_SetProperty($this->InstanceID, "SyncList", $data);
        IPS_ApplyChanges($this->InstanceID);
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
    private function GetTargetConfig(string $FolderName)
    {
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        if (!is_array($targets)) return null;
        foreach ($targets as $target) {
            if (isset($target['Name']) && $target['Name'] === $FolderName) return $target;
        }
        return null;
    }

    private function InitConnectionForFolder(array $target): bool
    {
        $secID = $this->ReadPropertyInteger('LocalPasswordModuleID');
        $key = $target['RemoteKey'] ?? '';
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

            $connectionUrl = 'https://' . urlencode($user) . ":" . urlencode($pw) . "@" . $url . "/api/";
            $this->rpcClient = new RemoteSync_RPCClient($connectionUrl); // Hier Ihren Original-Klassennamen nutzen
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function FindRemoteScriptID(int $parentID, string $name): int
    {
        $children = @$this->rpcClient->IPS_GetChildrenIDs($parentID);
        if (is_array($children)) {
            foreach ($children as $cID) {
                $obj = $this->rpcClient->IPS_GetObject($cID);
                if ($obj['ObjectType'] == 3 && $obj['ObjectName'] == $name) return $cID;
            }
        }
        return 0;
    }
    private function AddToBuffer($localID)
    {
        $syncList = json_decode($this->ReadPropertyString("SyncList"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $rawBuffer = $this->ReadAttributeString('_BatchBuffer');
        $buffer = json_decode($rawBuffer, true);
        if (!is_array($buffer)) $buffer = [];

        // Wir gehen alle Einträge der SyncList durch, um alle Ziele dieser Variable zu finden
        foreach ($syncList as $item) {
            if ($item['ObjectID'] == $localID && !empty($item['Active'])) {
                $folderName = $item['Folder'];

                // Den passenden lokalen Root-Anker für diesen Folder finden (für Pfadberechnung)
                $localRootID = 0;
                foreach ($roots as $root) {
                    if ($root['TargetFolder'] === $folderName) {
                        $localRootID = (int)$root['LocalRootID'];
                        break;
                    }
                }

                if (!IPS_ObjectExists($localID)) continue;
                $var = IPS_GetVariable($localID);

                // PAYLOAD GENERIERUNG: Exakt identisch zum Original
                $payload = [
                    'LocalID' => $localID,
                    'Value'   => GetValue($localID),
                    'Type'    => $var['VariableType'],
                    'Profile' => $var['VariableCustomProfile'] ?: $var['VariableProfile'],
                    'Name'    => IPS_GetName($localID),
                    'Ident'   => IPS_GetObject($localID)['ObjectIdent'],
                    'Key'     => $this->ReadPropertyString("LocalServerKey"), // Globaler Key aus Create
                    'Action'  => !empty($item['Action']),
                    'Delete'  => !empty($item['Delete'])
                ];

                // Pfad-Berechnung relativ zum folder-spezifischen Root
                $pathStack = [];
                $currentID = $localID;
                while ($currentID != $localRootID && $currentID > 0) {
                    array_unshift($pathStack, IPS_GetName($currentID));
                    $currentID = IPS_GetParent($currentID);
                }
                $payload['Path'] = $pathStack;

                // Im Buffer unter dem Folder ablegen
                $buffer[$folderName][$localID] = $payload;
            }
        }

        $this->WriteAttributeString('_BatchBuffer', json_encode($buffer));
        $this->SetTimerInterval('BufferTimer', 200);
    }

    public function FlushBuffer()
    {
        if ($this->ReadAttributeBoolean('_IsSending')) return;
        $this->SetTimerInterval('BufferTimer', 0);

        $rawBuffer = $this->ReadAttributeString('_BatchBuffer');
        $fullBuffer = json_decode($rawBuffer, true);
        if (empty($fullBuffer)) return;

        $this->WriteAttributeBoolean('_IsSending', true);

        try {
            foreach ($fullBuffer as $folderName => $variables) {
                // 1. Ziel-Konfiguration für diesen Folder holen
                $target = $this->GetTargetConfig($folderName);
                if (!$target) continue;

                // 2. Verbindung zu DIESEM Ziel aufbauen
                if (!$this->InitConnectionForFolder($target)) continue;

                $batch = array_values($variables);

                // 3. Profile sammeln (Original-Logik)
                $profiles = [];
                if ($this->ReadPropertyBoolean('ReplicateProfiles')) {
                    foreach ($batch as $item) {
                        if (!empty($item['Profile']) && !isset($profiles[$item['Profile']])) {
                            if (@IPS_VariableProfileExists($item['Profile'])) {
                                $profiles[$item['Profile']] = IPS_GetVariableProfile($item['Profile']);
                            }
                        }
                    }
                }

                // 4. Paket schnüren
                $packet = [
                    'TargetID'   => (int)$target['RemoteRootID'],
                    'Batch'      => $batch,
                    'AutoCreate' => $this->ReadPropertyBoolean('AutoCreate'),
                    'Profiles'   => $profiles
                ];

                // 5. Empfänger-Script auf dem Remote-System finden
                $receiverID = $this->FindRemoteScriptID((int)$target['RemoteScriptRootID'], "RemoteSync_Receiver");

                if ($receiverID > 0) {
                    $this->rpcClient->IPS_RunScriptWaitEx($receiverID, ['DATA' => json_encode($packet)]);
                }
            }

            // Buffer erst leeren, wenn alle Folder abgearbeitet wurden
            $this->WriteAttributeString('_BatchBuffer', '[]');
        } catch (Exception $e) {
            $this->LogDebug("Flush Error: " . $e->getMessage());
        } finally {
            $this->WriteAttributeBoolean('_IsSending', false);
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
            if ($obj['ObjectType'] == 2) {
                $result[] = $childID;
            }
            if ($obj['HasChildren']) {
                $this->GetRecursiveVariables($childID, $result);
            }
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
                'RemotePasswordModuleID' => @$this->ReadPropertyInteger('RemotePasswordModuleID'),
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
        } catch (Exception $e) {
            return false;
        }
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

            $connectionUrl = 'https://' . urlencode($user) . ":" . urlencode($pw) . "@" . $url . "/api/";
            $this->rpcClient = new RemoteSync_RPCClient($connectionUrl);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function LogDebug($msg)
    {
        try {
            if (@$this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('RemoteSync', $msg);
        } catch (Exception $e) {
        }
    }
}



class RemoteSync_RPCClient
{
    private $url;
    public function __construct($url)
    {
        $this->url = $url;
    }
    public function __call($method, $params)
    {
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
