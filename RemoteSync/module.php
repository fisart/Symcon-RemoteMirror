<?php

declare(strict_types=1);

// Version 1.7.5

class RemoteSync extends IPSModule
{
    const VERSION = '1.5.5';
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

        // Wir behalten nur noch die globalen Steuerungswerte
        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyBoolean('AutoCreate', true);
        $this->RegisterPropertyBoolean('ReplicateProfiles', true);

        $this->RegisterPropertyInteger('LocalPasswordModuleID', 0);
        $this->RegisterPropertyString('LocalServerKey', '');


        // --- MANAGER PROPERTIES ---
        $this->RegisterPropertyString("Targets", "[]");
        $this->RegisterPropertyString("Roots", "[]");

        // --- ATTRIBUTES ---
        $this->RegisterAttributeInteger('_RemoteReceiverID', 0);
        $this->RegisterAttributeInteger('_RemoteGatewayID', 0);
        $this->RegisterAttributeString('_SyncState', '{"buffer":{},"events":{},"starts":{}}');
        $this->RegisterAttributeBoolean('_IsSending', false);
        $this->RegisterAttributeString("SyncListCache", "[]");

        // --- TIMERS ---


    }


    public function UpdateUI()
    {
        $this->ReloadForm();
    }

    // --- FORM & UI ---


    public function GetConfigurationForm()
    {

        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Statische Footer-Buttons (z.B. Refresh) aus der Datei zwischenspeichern
        $staticFooter = isset($form['actions']) ? $form['actions'] : [];
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
                // NEU: Key enthält jetzt zur Eindeutigkeit auch die LocalRootID
                $f = $item['Folder'] ?? '';
                $r = $item['LocalRootID'] ?? 0;
                $o = $item['ObjectID'] ?? 0;
                $stateCache[$f . '_' . $r . '_' . $o] = $item;
            }
        }

        // 5. Dynamische Generierung der Sektion 'actions' (Schritt 3) - GRUPPIERT NACH ROOTS (Step 2)
        foreach ($roots as $root) {
            if (!isset($root['LocalRootID']) || $root['LocalRootID'] == 0) continue;

            $localRootID = (int)$root['LocalRootID'];
            $folderName  = $root['TargetFolder'] ?? 'Unknown';

            // Name des lokalen Objekts für die Überschrift
            $localName = IPS_ObjectExists($localRootID) ? IPS_GetName($localRootID) : "ID " . $localRootID;

            $syncValues = [];
            $foundVars  = [];
            $this->GetRecursiveVariables($localRootID, $foundVars);

            foreach ($foundVars as $vID) {
                // Key-Abgleich mit dem stateCache
                $key = $folderName . '_' . $localRootID . '_' . $vID;

                $syncValues[] = [
                    "Folder"      => $folderName,
                    "LocalRootID" => $localRootID, // Muss für RequestAction mitgeführt werden
                    "ObjectID"    => $vID,
                    "Name"        => IPS_GetName($vID),
                    "Active"      => $stateCache[$key]['Active'] ?? false,
                    "FullHistory" => $stateCache[$key]['FullHistory'] ?? false, // NEU v1.6.2
                    "Action"      => $stateCache[$key]['Action'] ?? false,
                    "Delete"      => $stateCache[$key]['Delete'] ?? false
                ];
            }

            // Eindeutige ID für dieses Mapping (Folder + Root)
            $mappingID = md5($folderName . $localRootID);
            $listName  = "List_" . $mappingID;
            $onEdit    = "IPS_RequestAction(\$id, 'UpdateRow', json_encode(\$$listName));";

            $form['actions'][] = [
                "type"    => "ExpansionPanel",
                "caption" => "SOURCE: " . strtoupper($localName) . " (Target Folder: " . $folderName . " | " . count($syncValues) . " Variables)",
                "items"   => [
                    [
                        "type" => "RowLayout",
                        "items" => [
                            ["type" => "Label", "caption" => "Batch Tools:", "bold" => true, "width" => "90px"],

                            // Sync Gruppe
                            ["type" => "Button", "caption" => "Sync ALL", "onClick" => "RS_ToggleAll(\$id, 'Active', true, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Sync NONE", "onClick" => "RS_ToggleAll(\$id, 'Active', false, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],

                            // NEU v1.6.2: History Gruppe
                            ["type" => "Button", "caption" => "Hist ALL", "onClick" => "RS_ToggleAll(\$id, 'FullHistory', true, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Hist NONE", "onClick" => "RS_ToggleAll(\$id, 'FullHistory', false, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],

                            // Action Gruppe
                            ["type" => "Button", "caption" => "Action ALL", "onClick" => "RS_ToggleAll(\$id, 'Action', true, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Action NONE", "onClick" => "RS_ToggleAll(\$id, 'Action', false, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],

                            // Delete Gruppe
                            ["type" => "Button", "caption" => "Del ALL", "onClick" => "RS_ToggleAll(\$id, 'Delete', true, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Del NONE", "onClick" => "RS_ToggleAll(\$id, 'Delete', false, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],

                            // Management & Installation
                            ["type" => "Button", "caption" => "💾 SAVE ALL", "onClick" => "RS_SaveSelections(\$id);", "width" => "100px"]
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
                            ["name" => "LocalRootID", "caption" => "Root", "visible" => false], // Versteckt für interne Logik
                            ["name" => "Active", "caption" => "Sync", "width" => "60px", "edit" => ["type" => "CheckBox"]],
                            ["name" => "FullHistory", "caption" => "Full Hist.", "width" => "75px", "edit" => ["type" => "CheckBox"]], // NEU v1.6.2
                            ["name" => "Action", "caption" => "R-Action", "width" => "70px", "edit" => ["type" => "CheckBox"]],
                            ["name" => "Delete", "caption" => "Del Rem.", "width" => "80px", "edit" => ["type" => "CheckBox"]]
                        ],
                        "values" => $syncValues
                    ]
                ]
            ];
        }

        // --- NEU v1.7.6: Dynamische Setup-Buttons im Aktionsbereich ---
        $setupButtons = [];
        $localKey = $this->ReadPropertyString('LocalServerKey');

        foreach ($targets as $target) {
            $fName = $target['Name'] ?? '';
            $rKey  = $target['RemoteKey'] ?? '';
            if ($fName === '') continue;

            $wizData = [
                'URL' => '',
                'User' => '',
                'PW' => '',
                'Root' => (int)($target['RemoteScriptRootID'] ?? 10000),
                'Sec' => (int)($target['RemoteSecretsID'] ?? 10000)
            ];

            if ($secID > 0 && $rKey !== '' && IPS_InstanceExists($secID)) {
                $json = @SEC_GetSecret($secID, $rKey);
                if ($json) {
                    $vault = json_decode($json, true);
                    if (is_array($vault)) {
                        if (isset($vault['URL']))  $wizData['URL']  = $vault['URL'];
                        if (isset($vault['User'])) $wizData['User'] = $vault['User'];
                        if (isset($vault['PW']))   $wizData['PW']   = $vault['PW'];
                        if (isset($vault['SecretsID'])) $wizData['Sec'] = (int)$vault['SecretsID'];
                        if (isset($vault['ScriptRootID_' . $localKey])) $wizData['Root'] = (int)$vault['ScriptRootID_' . $localKey];
                        if (isset($vault['SecretsID_' . $localKey]))    $wizData['Sec']  = (int)$vault['SecretsID_' . $localKey];
                    }
                }
            }

            $setupButtons[] = [
                "type" => "Button",
                "caption" => "Setup: " . $fName,
                "popup" => [
                    "caption" => "Remote Setup Wizard: " . $fName,
                    "items" => [
                        ["type" => "Label", "caption" => "Verify connection for " . $fName, "bold" => true],
                        ["type" => "ValidationTextBox", "name" => "WizURL", "caption" => "Remote URL", "value" => $wizData['URL']],
                        ["type" => "ValidationTextBox", "name" => "WizUser", "caption" => "Username", "value" => $wizData['User']],
                        ["type" => "PasswordBox", "name" => "WizPW", "caption" => "Password", "value" => $wizData['PW']],
                        ["type" => "NumberSpinner", "name" => "WizScriptRoot", "caption" => "Script Root ID", "value" => $wizData['Root'], "minimum" => 10000, "maximum" => 99999],
                        ["type" => "NumberSpinner", "name" => "WizSecretsID", "caption" => "Remote SEC ID", "value" => $wizData['Sec'], "minimum" => 10000, "maximum" => 99999]
                    ],
                    "actions" => [
                        [
                            "type" => "Button",
                            "caption" => "🚀 START INSTALLATION",
                            "onClick" => "RS_ExecuteWizardInstallation(\$id, '$fName', \$WizURL, \$WizUser, \$WizPW, \$WizScriptRoot, \$WizSecretsID);"
                        ]
                    ]
                ]
            ];
        }

        if (count($setupButtons) > 0) {
            $form['actions'][] = [
                "type" => "ExpansionPanel",
                "caption" => "REMOTE INSTALLATION WIZARDS (Auto-filled from Vault)",
                "items" => [["type" => "RowLayout", "items" => $setupButtons]]
            ];
        }

        // Globalen Footer wieder anhängen
        foreach ($staticFooter as $btn) {
            $form['actions'][] = $btn;
        }

        return json_encode($form);
    }

    public function Destroy()
    {
        // Alle Timer sicher stoppen

        parent::Destroy();
    }


    // Hilfsfunktion zum rekursiven Befüllen der statischen Formular-Elemente
    private function UpdateStaticFormElements(&$elements, $serverOptions, $folderOptions): void
    {
        foreach ($elements as &$element) {
            // Rekursiver Aufruf mit den ursprünglichen 3 Parametern
            if (isset($element['items'])) $this->UpdateStaticFormElements($element['items'], $serverOptions, $folderOptions);
            if (!isset($element['name'])) continue;

            // Globaler Key (falls vorhanden) oder Key in der Targets-Liste
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

    public function ToggleAll(string $Column, bool $State, string $Folder, int $LocalRootID)
    {
        $data = json_decode($this->ReadAttributeString("SyncListCache"), true);
        if (!is_array($data)) $data = [];

        $map = [];
        foreach ($data as $item) {
            $key = ($item['Folder'] ?? '') . '_' . ($item['LocalRootID'] ?? 0) . '_' . ($item['ObjectID'] ?? 0);
            $map[$key] = $item;
        }

        $foundVars = [];
        $this->GetRecursiveVariables($LocalRootID, $foundVars);

        $uiValues = [];
        foreach ($foundVars as $vID) {
            $key = $Folder . '_' . $LocalRootID . '_' . $vID;
            if (!isset($map[$key])) {
                // Initialisierung eines neuen Eintrags mit dem Feld 'FullHistory'
                $map[$key] = [
                    "Folder"      => $Folder,
                    "LocalRootID" => $LocalRootID,
                    "ObjectID"    => $vID,
                    "Name"        => IPS_GetName($vID),
                    "Active"      => false,
                    "FullHistory" => false, // NEU v1.6.2
                    "Action"      => false,
                    "Delete"      => false
                ];
            }

            $map[$key][$Column] = $State;

            // Spezial-Logik: Wenn Delete auf TRUE, dann Sync und Action auf FALSE
            if ($Column === 'Delete' && $State === true) {
                $map[$key]['Active'] = false;
                $map[$key]['Action'] = false;
            }

            $uiValues[] = $map[$key];
        }

        $this->WriteAttributeString("SyncListCache", json_encode(array_values($map)));
        $this->UpdateFormField("List_" . md5($Folder . $LocalRootID), "values", json_encode($uiValues));
    }

    // --- INSTALLATION ---
    public function InstallRemoteScripts(string $Folder)
    {
        $this->SendDebug("RS_Install", "Button clicked for Folder: " . $Folder, 0);

        $target = $this->GetTargetConfig($Folder);
        if (!$target) {
            $this->SendDebug("RS_Error", "Could not find config for folder name: " . $Folder, 0);
            echo "Error: Configuration for '$Folder' not found.";
            return;
        }

        $this->SendDebug("RS_Install", "Target Config found. Key: " . $target['RemoteKey'], 0);

        if (!$this->InitConnectionForFolder($target)) {
            $this->SendDebug("RS_Error", "Connection establishment failed.", 0);
            echo "Error: Connection failed. Check SEC-Key and SEC-Instance.";
            return;
        }

        $this->SendDebug("RS_Install", "Connection established. Attempting to locate/create scripts...", 0);

        $scriptRoot = (int)$target['RemoteScriptRootID'];
        $remoteSecID = (int)$target['RemoteSecretsID'];

        try {
            // Wir nutzen jetzt die stabilen Idents RS_Gateway und RS_Receiver
            $gwID = $this->FindRemoteScript($scriptRoot, "RS_Gateway");
            $this->SendDebug("RS_Install", "Gateway Script located/created at ID: " . $gwID, 0);

            $gwCode = $this->GenerateGatewayCode($remoteSecID);
            $this->rpcClient->IPS_SetScriptContent($gwID, $gwCode);

            $rxID = $this->FindRemoteScript($scriptRoot, "RS_Receiver");
            $this->SendDebug("RS_Install", "Receiver Script located/created at ID: " . $rxID, 0);

            $this->rpcClient->IPS_SetScriptContent($rxID, $this->GenerateReceiverCode($gwID));

            $this->SendDebug("RS_Install", "Scripts successfully updated on Remote System.", 0);
            echo "Success: Scripts installed on $Folder (Ident-based)";
        } catch (Exception $e) {
            $this->SendDebug("RS_Error", "Exception during Install: " . $e->getMessage(), 0);
            echo "Error: " . $e->getMessage();
        }
    }

    private function FindRemoteScript(int $parentID, string $ident): int
    {
        // 1. ABSOLUTE SUCHE: Wer hat diesen Ident?
        try {
            $id = $this->rpcClient->IPS_GetObjectIDByIdent($ident, $parentID);
            if ($id > 0) {
                // PRÜFUNG: Ist es wirklich ein Skript? (ObjectType 3)
                $obj = $this->rpcClient->IPS_GetObject($id);
                if ($obj['ObjectType'] == 3) {
                    return $id;
                } else {
                    // Der Ident ist belegt, aber durch ein falsches Objekt!
                    $this->Log("Critical Error: Ident '$ident' is already used by a non-script object (Type: " . $obj['ObjectType'] . "). Please delete it on remote system.", KL_ERROR);
                    return 0;
                }
            }
        } catch (Exception $e) {
            // Nicht gefunden über Ident, wir machen weiter
        }

        // 2. NAMENS-FALLBACK (Heilung alter Installationen)
        try {
            $oldName = ($ident === 'RS_Gateway') ? 'RemoteSync_Gateway' : 'RemoteSync_Receiver';
            $children = $this->rpcClient->IPS_GetChildrenIDs($parentID);
            if (is_array($children)) {
                foreach ($children as $cID) {
                    $obj = $this->rpcClient->IPS_GetObject($cID);
                    // Hier prüfen wir ebenfalls auf ObjectType 3
                    if ($obj['ObjectType'] == 3 && $obj['ObjectName'] == $oldName) {
                        try {
                            $this->rpcClient->IPS_SetIdent($cID, $ident);
                            return $cID;
                        } catch (Exception $eIdent) {
                            $this->Log("Warning: Could not set Ident '$ident' on $cID. Error: " . $eIdent->getMessage(), KL_WARNING);
                            return $cID;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Fehler beim Durchlaufen der Kinder
        }

        // 3. NEUERSTELLUNG
        try {
            $id = $this->rpcClient->IPS_CreateScript(0);
            $this->rpcClient->IPS_SetParent($id, $parentID);
            $this->rpcClient->IPS_SetName($id, ($ident === 'RS_Gateway' ? 'RemoteSync Gateway' : 'RemoteSync Receiver'));

            try {
                $this->rpcClient->IPS_SetIdent($id, $ident);
            } catch (Exception $eIdent) {
                // Falls dieser Fehler hier auftritt, ist der Ident bereits auf dieser Ebene belegt
                $this->Log("Critical: Cannot set Ident '$ident'. It is blocked by another object at this level.", KL_ERROR);
            }

            return $id;
        } catch (Exception $e) {
            $this->Log("FindRemoteScript Final Failure: " . $e->getMessage(), KL_ERROR);
            return 0;
        }
    }
    // --- RUNTIME ---

    public function ApplyChanges()
    {
        // Best Practice: Warten bis der Kernel bereit ist
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        parent::ApplyChanges();

        // --- SICHERHEITS-RESET (Option A: Klare Verhältnisse) ---
        $this->rpcClient = null;
        $this->config = [];

        // --- NACHRICHTEN-CLEANUP ---
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // --- KONSISTENZ-PRÜFUNG DER KONFIGURATION ---
        $syncListRaw = $this->ReadAttributeString("SyncListCache");



        $syncList = json_decode($syncListRaw, true) ?: [];
        $roots = json_decode($this->ReadPropertyString("Roots"), true) ?: [];

        $cleanedSyncList = [];
        $uniqueCheck = [];
        $count = 0;
        $hasDeleteTask = false;

        foreach ($syncList as $item) {
            $vID = (int)($item['ObjectID'] ?? 0);
            $folder = $item['Folder'] ?? '';
            $rootID = (int)($item['LocalRootID'] ?? 0);

            // --- SONDE 1: Einstieg prüfen ---


            if ($vID === 0 || !IPS_ObjectExists($vID)) {
                $this->Log("Consistency Check: Removing ID $vID - Object no longer exists.", KL_WARNING);
                continue;
            }

            $mappingIsValid = false;
            foreach ($roots as $r) {
                if (($r['TargetFolder'] ?? '') === $folder && (int)($r['LocalRootID'] ?? 0) === $rootID) {
                    if ($this->IsChildOf($vID, $rootID)) {
                        $mappingIsValid = true;
                        break;
                    }
                }
            }

            if (!$mappingIsValid) {
                // --- SONDE 2: Ablehnungsgrund prüfen ---

                $this->Log("Consistency Check: Dropping ID $vID - Mapping or hierarchy changed.", KL_WARNING);
                continue;
            }

            $key = $folder . '_' . $rootID . '_' . $vID;
            if (isset($uniqueCheck[$key])) {
                continue;
            }
            $uniqueCheck[$key] = true;

            $isDelete = !empty($item['Delete']);
            $isActive = !empty($item['Active']);

            if ($isDelete) {
                $hasDeleteTask = true;
            }

            if ($isActive && !$isDelete) {
                $this->RegisterMessage($vID, VM_UPDATE);
                $count++;
            }
            $cleanedSyncList[] = $item;
        }

        $this->WriteAttributeString("SyncListCache", json_encode($cleanedSyncList));

        // --- STATUS & INITIALER SYNC ---
        if ($count === 0 && !$hasDeleteTask && $this->ReadPropertyInteger('LocalPasswordModuleID') == 0) {
            $this->SetStatus(104);
        } else {
            $this->SetStatus(102);
            if ($count > 0 || $hasDeleteTask) {
                @IPS_RunScriptText("RS_ProcessSync(" . $this->InstanceID . ");");
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {

        switch ($Ident) {
            case "UpdateRow":
                $rows = json_decode($Value, true);
                if (!is_array($rows)) return;

                // Falls IP-Symcon nur ein einzelnes Objekt sendet, in Array umwandeln
                if (isset($rows['ObjectID'])) {
                    $rows = [$rows];
                }

                $cache = json_decode($this->ReadAttributeString("SyncListCache"), true);
                if (!is_array($cache)) $cache = [];

                $map = [];
                foreach ($cache as $item) {
                    $k = ($item['Folder'] ?? '') . '_' . ($item['LocalRootID'] ?? 0) . '_' . ($item['ObjectID'] ?? 0);
                    $map[$k] = $item;
                }

                foreach ($rows as $row) {
                    if (!isset($row['Folder'], $row['LocalRootID'], $row['ObjectID'])) continue;

                    // --- NEUE LOGIK v1.6.2 (Pro Zeile angewendet) ---
                    if ($row['Delete']) {
                        $row['Active'] = false;
                        $row['FullHistory'] = false; // NEU v1.6.2: Konsistenz-Check
                    }
                    // -----------------------------------------

                    $key = $row['Folder'] . '_' . $row['LocalRootID'] . '_' . $row['ObjectID'];
                    $map[$key] = $row;
                }

                $this->WriteAttributeString("SyncListCache", json_encode(array_values($map)));
                break;
        }
    }

    public function SaveSelections()
    {
        // Wir triggern nur noch ApplyChanges, um die Nachrichten-Registrierung zu aktualisieren.
        // Die Daten sind bereits im Attribut SyncListCache gespeichert (via RequestAction).
        $this->ApplyChanges();
        echo "Selection saved.";
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Bei VM_UPDATE enthält $Data[0] den neuen Wert der Variable.
        // Wir übergeben diesen direkt an AddToBuffer, um Race Conditions zu vermeiden.
        $this->AddToBuffer($SenderID, $Data[0]);
    }

    public function ProcessSync()
    {
        if (!$this->LoadConfig()) return;

        foreach ($this->config['SyncList'] as $item) {
            if (!empty($item['Active']) || !empty($item['Delete'])) {
                $this->AddToBuffer((int)$item['ObjectID']);
            }
        }
    }


    private function GetTargetConfig(string $FolderName)
    {
        if (!$this->LoadConfig()) return null;
        foreach ($this->config['Targets'] as $target) {
            if (isset($target['Name']) && $target['Name'] === $FolderName) return $target;
        }
        return null;
    }
    private function GetPayload(int $localID, array $itemConfig, array $roots, $Value = null): ?array
    {
        $folderName = $itemConfig['Folder'];
        $foundMapping = false;
        $localRootID = 0;
        $remoteRootID = 0;

        foreach ($roots as $root) {
            if (($root['TargetFolder'] ?? '') === $folderName) {
                if ($this->IsChildOf($localID, (int)$root['LocalRootID'])) {
                    $localRootID = (int)$root['LocalRootID'];
                    $remoteRootID = (int)$root['RemoteRootID'];
                    $foundMapping = true;
                    break;
                }
            }
        }

        if (!$foundMapping || !IPS_ObjectExists($localID)) {
            return null;
        }

        $var = IPS_GetVariable($localID);
        $pathStack = [];
        $currentID = $localID;
        while ($currentID != $localRootID && $currentID > 0) {
            array_unshift($pathStack, IPS_GetName($currentID));
            $currentID = IPS_GetParent($currentID);
        }

        // NEU v1.6.3: Wertermittlung ohne Race Condition
        // Wenn $Value nicht null ist (vom Event geliefert), nutzen wir diesen.
        // Andernfalls (manueller Sync) lesen wir den aktuellen Stand.
        $finalValue = ($Value !== null) ? $Value : GetValue($localID);

        return [
            'LocalID'      => $localID,
            'LocalSetID'   => $localRootID, // Identifikator für die Performance-Variable
            'RemoteRootID' => $remoteRootID, // Hilfswert für die Gruppierung
            'Folder'       => $folderName,   // Hilfswert
            'Value'        => $finalValue,   // Geändert v1.6.3
            'Type'         => $var['VariableType'],
            'Profile'      => $var['VariableCustomProfile'] ?: $var['VariableProfile'],
            'Name'         => IPS_GetName($localID),
            'Ident'        => IPS_GetObject($localID)['ObjectIdent'],
            'Key'          => $this->config['LocalServerKey'],
            'Action'       => !empty($itemConfig['Action']),
            'Delete'       => !empty($itemConfig['Delete']),
            'Path'         => $pathStack
        ];
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

            $urlRaw = $config['URL'] ?? $config['url'] ?? $config['Url'] ?? '';
            $user   = $config['User'] ?? $config['user'] ?? $config['Username'] ?? '';
            $pw     = $config['PW'] ?? $config['pw'] ?? $config['Password'] ?? '';

            if ($urlRaw === '') return false;

            $connectionUrl = 'https://' . urlencode($user) . ":" . urlencode($pw) . "@" . $urlRaw . "/api/";
            $this->rpcClient = new RemoteSync_RPCClient($connectionUrl);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function ExecuteWizardInstallation(string $FolderName, string $URL, string $User, string $PW, int $ScriptRoot, int $SecretsID)
    {
        $connectionUrl = 'https://' . urlencode($User) . ":" . urlencode($PW) . "@" . $URL . "/api/";
        $tempRpc = new RemoteSync_RPCClient($connectionUrl);

        // Backup des aktuellen Clients
        $oldClient = $this->rpcClient;
        $this->rpcClient = $tempRpc;

        try {
            // Installation durchführen (Nutzt die stabile FindRemoteScript v1.6.9)
            $gwID = $this->FindRemoteScript($ScriptRoot, "RS_Gateway");
            if ($gwID === 0) throw new Exception("Could not find or create Gateway script.");

            $gwCode = $this->GenerateGatewayCode($SecretsID);
            $this->rpcClient->IPS_SetScriptContent($gwID, $gwCode);

            $rxID = $this->FindRemoteScript($ScriptRoot, "RS_Receiver");
            if ($rxID === 0) throw new Exception("Could not find or create Receiver script.");

            $this->rpcClient->IPS_SetScriptContent($rxID, $this->GenerateReceiverCode($gwID));

            // IDs zurück in die lokale Property Targets schreiben
            $targets = json_decode($this->ReadPropertyString("Targets"), true);
            $updated = false;

            foreach ($targets as &$t) {
                if ($t['Name'] === $FolderName) {
                    $t['RemoteScriptRootID'] = $ScriptRoot;
                    $t['RemoteSecretsID']    = $SecretsID;
                    $updated = true;
                    break;
                }
            }

            if ($updated) {
                IPS_SetProperty($this->InstanceID, "Targets", json_encode($targets));
                IPS_ApplyChanges($this->InstanceID);
            }

            echo "Success: Remote setup completed and local configuration updated.";
        } catch (Exception $e) {
            $this->Log("Wizard Error: " . $e->getMessage(), KL_ERROR);
            echo "Error: " . $e->getMessage();
        } finally {
            // rpcClient wiederherstellen
            $this->rpcClient = $oldClient;
        }
    }



    private function AddToBuffer($localID, $Value = null)
    {
        if (IPS_GetKernelRunlevel() !== KR_READY || !$this->LoadConfig()) return;

        foreach ($this->config['SyncList'] as $item) {
            if ($item['ObjectID'] == $localID && (!empty($item['Active']) || !empty($item['Delete']))) {

                $folderName = $item['Folder'] ?? 'Unknown';
                $folderHash = md5($folderName);
                $short = substr($folderHash, 0, 20);

                $payload = $this->GetPayload((int)$localID, $item, $this->config['Roots'], $Value);

                if ($payload) {
                    // --- NEU v1.6.4: State-Lock zur Vermeidung von Race Conditions ---
                    $stateLock = "RS_StateLock_" . $this->InstanceID;
                    // Wir warten maximal 1000ms auf den Zugriff (RAM-Operationen sind extrem schnell)
                    if (IPS_SemaphoreEnter($stateLock, 1000)) {
                        try {
                            $state = json_decode($this->ReadAttributeString('_SyncState'), true) ?: ['buffer' => [], 'events' => [], 'starts' => []];

                            // v1.6.2 Logik (Anti-Conflation)
                            $bufferKey = (string)$localID;
                            if (!empty($item['FullHistory'])) {
                                $bufferKey = $localID . '_' . microtime(true);
                            }

                            // 1. In den segmentierten Puffer schreiben
                            $state['buffer'][$folderName][$bufferKey] = $payload;

                            // 2. Update event counter
                            $state['events'][$folderName] = ($state['events'][$folderName] ?? 0) + 1;

                            // 3. Update processing lag start time
                            if (!isset($state['starts'][$folderName]) || $state['starts'][$folderName] == 0) {
                                $state['starts'][$folderName] = microtime(true);
                            }

                            // Status zurückschreiben (NOCH INNERHALB DES LOCKS)
                            $this->WriteAttributeString('_SyncState', json_encode($state));
                        } finally {
                            IPS_SemaphoreLeave($stateLock);
                        }
                    }

                    // 4. Update Queue Size Monitoring (Außerhalb des State-Locks)
                    // Da wir das Attribut gerade geschrieben haben, lesen wir den Count neu
                    $qVarID = @IPS_GetObjectIDByIdent("Q" . $short, $this->InstanceID);
                    if ($qVarID > 0) {
                        $currentState = json_decode($this->ReadAttributeString('_SyncState'), true);
                        SetValue($qVarID, count($currentState['buffer'][$folderName] ?? []));
                    }

                    // 5. Worker-Start
                    $script = "RS_FlushBuffer(" . $this->InstanceID . ", '" . $folderName . "');";
                    if (!IPS_RunScriptText($script)) {
                        $this->Log("Critical Error: Worker thread for server '$folderName' could not be started. System might be overloaded.", KL_ERROR);
                    }
                }
            }
        }
    }



    private function IsChildOf(int $objectID, int $parentID): bool
    {
        if ($objectID === $parentID) return true;
        if (!IPS_ObjectExists($objectID)) return false;

        $depth = 0;
        // Limit von 100 Ebenen für maximale Sicherheit
        while ($objectID > 0 && $depth < 100) {
            $depth++;
            $objectID = IPS_GetParent($objectID);

            if ($objectID === $parentID) {
                return true;
            }
            if ($objectID > 0 && !IPS_ObjectExists($objectID)) {
                return false;
            }
        }
        return false;
    }

    public function ExportConfig(): string
    {
        $config = [
            'Properties' => [
                'Targets'               => $this->ReadPropertyString('Targets'),
                'Roots'                 => $this->ReadPropertyString('Roots'),
                'LocalPasswordModuleID' => $this->ReadPropertyInteger('LocalPasswordModuleID'),
                'LocalServerKey'        => $this->ReadPropertyString('LocalServerKey'),
                'DebugMode'             => $this->ReadPropertyBoolean('DebugMode'),
                'AutoCreate'            => $this->ReadPropertyBoolean('AutoCreate'),
                'ReplicateProfiles'     => $this->ReadPropertyBoolean('ReplicateProfiles')
            ],
            'Attributes' => [
                'SyncListCache' => $this->ReadAttributeString('SyncListCache')
            ]
        ];
        return json_encode($config, JSON_PRETTY_PRINT);
    }

    /**
     * @param string $JSONString
     * @return array Array mit ['status' => bool, 'messages' => array]
     */
    public function ImportConfig(string $JSONString): array
    {
        $data = json_decode($JSONString, true);
        if (!$data) return ['status' => false, 'messages' => ['Invalid JSON']];
        foreach ($data['Properties'] as $key => $v) IPS_SetProperty($this->InstanceID, $key, $v);
        $this->WriteAttributeString('SyncListCache', $data['Attributes']['SyncListCache']);
        IPS_ApplyChanges($this->InstanceID);
        return ['status' => true, 'messages' => []];
    }

    // --- UI Hilfsfunktionen für die Buttons ---

    public function UIExport()
    {
        $json = $this->ExportConfig();
        $this->UpdateFormField('TransferField', 'value', $json);
    }

    public function UIImport(string $JSONString)
    {
        if ($JSONString === "") {
            echo "Please paste the JSON export string into the text area first.";
            return;
        }

        $result = $this->ImportConfig($JSONString);
        echo $result['status'] ? "Success" : "Error";
    }

    public function InstallPerformanceVariables()
    {
        // -- ÄNDERUNG: Wir nutzen jetzt die Targets (Server) statt der Roots (Mappings) --
        $targets = json_decode($this->ReadPropertyString("Targets"), true) ?: [];
        $count = 0;

        foreach ($targets as $target) {
            $folderName = $target['Name'] ?? '';
            if ($folderName === '') continue;

            // -- ÄNDERUNG: Hash wird jetzt nur aus dem Folder Namen gebildet --
            $folderHash = md5($folderName);
            $short = substr($folderHash, 0, 20);

            // -- ÄNDERUNG: Caption angepasst auf den Server-Namen --
            $this->MaintainVariable("R" . $short, "RTT: " . $folderName, 2, "", 0, true);
            $this->MaintainVariable("B" . $short, "Batch: " . $folderName, 1, "", 0, true);
            $this->MaintainVariable("S" . $short, "Size: " . $folderName, 2, "", 0, true);
            $this->MaintainVariable("E" . $short, "Errors: " . $folderName, 1, "", 0, true);
            $this->MaintainVariable("D" . $short, "Skipped: " . $folderName, 1, "", 0, true);
            $this->MaintainVariable("L" . $short, "Lag: " . $folderName, 2, "", 0, true);
            $this->MaintainVariable("Q" . $short, "Queue: " . $folderName, 1, "", 0, true);
            $count++;
        }
        echo "Successfully installed performance variables for $count servers.";
    }

    public function DeletePerformanceVariables()
    {
        // -- ÄNDERUNG: Wir scannen nun die Targets --
        $targets = json_decode($this->ReadPropertyString("Targets"), true) ?: [];
        foreach ($targets as $target) {
            $folderName = $target['Name'] ?? '';
            if ($folderName !== '') {
                $folderHash = md5($folderName);
                $short = substr($folderHash, 0, 20);

                // Löschen durch setzen des letzten Parameters auf 'false'
                @$this->MaintainVariable("R" . $short, "", 2, "", 0, false);
                @$this->MaintainVariable("B" . $short, "", 1, "", 0, false);
                @$this->MaintainVariable("S" . $short, "", 2, "", 0, false);
                @$this->MaintainVariable("E" . $short, "", 1, "", 0, false);
                @$this->MaintainVariable("D" . $short, "", 1, "", 0, false);
                @$this->MaintainVariable("L" . $short, "", 2, "", 0, false);
                @$this->MaintainVariable("Q" . $short, "", 1, "", 0, false);
            }
        }
        echo "Performance variables deleted.";
    }


    public function Log(string $Message, int $Type = KL_MESSAGE)
    {
        // DebugMode aus dem Cache/Property lesen
        if ($this->ReadPropertyBoolean('DebugMode') || $Type == KL_ERROR || $Type == KL_WARNING) {
            // Wir filtern [BUFFER-CHECK] nur, wenn Debug aus ist
            if (strpos($Message, '[BUFFER-CHECK]') !== false && !$this->ReadPropertyBoolean('DebugMode')) {
                return;
            }
            // Wir rufen die originale System-Funktion auf
            parent::LogMessage($Message, $Type);
        }
    }

    public function FlushBuffer(string $FolderName = "")
    {
        if ($FolderName === "" || IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $folderHash = md5($FolderName);
        $lockName = "RS_Lock_" . $this->InstanceID . "_" . $folderHash;
        $short = substr($folderHash, 0, 20);

        if (!IPS_SemaphoreEnter($lockName, 0)) {
            return;
        }

        try {
            // --- NEU v1.6.4: State-Lock für atomares Auslesen und Leeren des Puffers ---
            $stateLock = "RS_StateLock_" . $this->InstanceID;
            $variables = [];
            $totalItems = 0;
            $skipped = 0;
            $firstEventTime = microtime(true);

            if (IPS_SemaphoreEnter($stateLock, 1000)) {
                try {
                    $state = json_decode($this->ReadAttributeString('_SyncState'), true) ?: ['buffer' => [], 'events' => [], 'starts' => []];

                    if (!isset($state['buffer'][$FolderName]) || count($state['buffer'][$FolderName]) === 0) {
                        IPS_SemaphoreLeave($stateLock);
                        IPS_SemaphoreLeave($lockName);
                        return;
                    }

                    $variables = $state['buffer'][$FolderName];
                    $totalItems = count($variables);

                    // Puffer-Segment leeren
                    unset($state['buffer'][$FolderName]);

                    // Metriken Snapshots
                    $eventCount = $state['events'][$FolderName] ?? $totalItems;
                    $skipped = max(0, $eventCount - $totalItems);
                    $state['events'][$FolderName] = 0;

                    $firstEventTime = $state['starts'][$FolderName] ?? microtime(true);
                    $state['starts'][$FolderName] = 0;

                    // Sofort zurückschreiben, damit der Puffer für andere Threads als "leer" gilt
                    $this->WriteAttributeString('_SyncState', json_encode($state));
                } finally {
                    IPS_SemaphoreLeave($stateLock);
                }
            } else {
                // Falls der State-Lock nicht zu bekommen ist
                throw new Exception("State-Lock timeout in FlushBuffer");
            }

            // --- AB HIER: Verarbeitung der extrahierten $variables (außerhalb des State-Locks) ---

            $this->Log("[BUFFER-CHECK] FlushBuffer: STARTING TRANSMISSION. Total items in this server-batch for $FolderName: $totalItems", KL_MESSAGE);

            // Queue Size Monitoring Reset
            $qVarID = @IPS_GetObjectIDByIdent("Q" . $short, $this->InstanceID);
            if ($qVarID > 0) SetValue($qVarID, 0);

            // Config-Abruf
            $firstVar = reset($variables);
            $target = $this->GetTargetConfig($FolderName);

            if (!$target || !$this->InitConnectionForFolder($target)) {
                $this->Log("[BUFFER-CHECK] FlushBuffer: ERROR - Connection to " . $FolderName . " failed.", KL_ERROR);
                return;
            }


            $batch = array_values($variables);
            $profiles = [];
            if ($this->ReadPropertyBoolean('ReplicateProfiles')) {
                foreach ($batch as $item) {
                    if (!empty($item['Profile']) && !isset($profiles[$item['Profile']])) {
                        if (IPS_VariableProfileExists($item['Profile'])) {
                            $profiles[$item['Profile']] = IPS_GetVariableProfile($item['Profile']);
                        }
                    }
                }
            }

            $packet = [
                'Batch'      => $batch,
                'AutoCreate' => $this->ReadPropertyBoolean('AutoCreate'),
                'Profiles'   => $profiles
            ];

            $jsonPacket = json_encode($packet);
            if ($jsonPacket === false) {
                throw new Exception("JSON Encoding failed: " . json_last_error_msg());
            }

            $sizeKB = round(strlen($jsonPacket) / 1024, 2);
            $receiverID = $this->FindRemoteScript((int)$target['RemoteScriptRootID'], "RemoteSync_Receiver");

            if ($receiverID > 0 && $this->rpcClient) {

                $startTime = microtime(true);
                // Der Aufruf erfolgt nun ohne Unterdrückung. 
                // Fehler werden durch den catch-Block weiter unten sauber abgefangen und geloggt.
                $result = $this->rpcClient->IPS_RunScriptWaitEx($receiverID, ['DATA' => $jsonPacket]);
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                // Performance Updates
                $rttVarID = @IPS_GetObjectIDByIdent("R" . $short, $this->InstanceID);
                if ($rttVarID > 0) SetValue($rttVarID, $duration);

                $batchVarID = @IPS_GetObjectIDByIdent("B" . $short, $this->InstanceID);
                if ($batchVarID > 0) SetValue($batchVarID, count($batch));

                $sizeVarID = @IPS_GetObjectIDByIdent("S" . $short, $this->InstanceID);
                if ($sizeVarID > 0) SetValue($sizeVarID, $sizeKB);

                $skippedVarID = @IPS_GetObjectIDByIdent("D" . $short, $this->InstanceID);
                if ($skippedVarID > 0) SetValue($skippedVarID, $skipped);

                $lag = round(microtime(true) - $firstEventTime, 2);
                $lagVarID = @IPS_GetObjectIDByIdent("L" . $short, $this->InstanceID);
                if ($lagVarID > 0) SetValue($lagVarID, $lag);
            }
        } catch (Exception $e) {
            $this->Log("[BUFFER-CHECK] FlushBuffer Exception: " . $e->getMessage(), KL_ERROR);
            $errVarID = @IPS_GetObjectIDByIdent("E" . $short, $this->InstanceID);
            if ($errVarID > 0) SetValue($errVarID, GetValue($errVarID) + 1);
        } finally {
            IPS_SemaphoreLeave($lockName);

            // Yield-Check (Atomarer Check am Ende)
            $checkState = json_decode($this->ReadAttributeString('_SyncState'), true);
            if (isset($checkState['buffer'][$FolderName]) && count($checkState['buffer'][$FolderName]) > 0) {
                $script = "RS_FlushBuffer(" . $this->InstanceID . ", '" . $FolderName . "');";
                @IPS_RunScriptText($script);
            } else {
                $this->Log("[BUFFER-CHECK] FlushBuffer: FINISHED for $FolderName. No more data pending.", KL_MESSAGE);
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

if (!is_array(\$packet)) return;

\$batch      = \$packet['Batch'] ?? [];
// -- ÄNDERUNG: rootID wird nicht mehr global aus dem Packet-Header gelesen --
\$autoCreate = !empty(\$packet['AutoCreate']); 
\$gatewayID  = $gwID;
\$profiles   = \$packet['Profiles'] ?? [];

// -- ÄNDERUNG: Validierung ohne rootID Prüfung --
if (!is_array(\$batch)) return;

// --- Profile Creation (unverändert) ---
if (is_array(\$profiles)) {
    foreach (\$profiles as \$pName => \$pDef) {
        if (!is_string(\$pName) || \$pName === '' || IPS_VariableProfileExists(\$pName)) continue;
        @IPS_CreateVariableProfile(\$pName, \$pDef['ProfileType'] ?? 0);
        if (isset(\$pDef['Icon'])) @IPS_SetVariableProfileIcon(\$pName, \$pDef['Icon']);
        @IPS_SetVariableProfileText(\$pName, \$pDef['Prefix'] ?? '', \$pDef['Suffix'] ?? '');
        @IPS_SetVariableProfileValues(\$pName, \$pDef['MinValue'] ?? 0, \$pDef['MaxValue'] ?? 0, \$pDef['StepSize'] ?? 0);
        if (isset(\$pDef['Digits'])) @IPS_SetVariableProfileDigits(\$pName, \$pDef['Digits']);
        if (!empty(\$pDef['Associations'])) {
            foreach (\$pDef['Associations'] as \$assoc) {
                @IPS_SetVariableProfileAssociation(\$pName, \$assoc['Value'], \$assoc['Name'] ?? '', \$assoc['Icon'] ?? '', \$assoc['Color'] ?? 0);
            }
        }
    }
}

foreach (\$batch as \$item) {
    // -- ÄNDERUNG: rootID wird nun für jedes Item individuell gesetzt --
    \$rootID    = \$item['RemoteRootID'] ?? 0;
    if (\$rootID <= 0) continue;
    
    \$localID   = \$item['LocalID'];
    
    try {
        \$serverKey = \$item['Key'];
        \$safeIdent = 'Rem_' . \$localID;
        \$refString = 'RS_REF:' . \$serverKey . ':' . \$localID;
        \$path      = \$item['Path'] ?? [];

        // 1. PFAD AUFLÖSEN
        \$currentParent = \$rootID;
        foreach (\$path as \$index => \$nodeName) {
            if (\$index === count(\$path) - 1) break;
            \$childID = @IPS_GetObjectIDByName(\$nodeName, \$currentParent);
            if (!\$childID && \$autoCreate) {
                \$childID = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
                IPS_SetParent(\$childID, \$currentParent);
                IPS_SetName(\$childID, \$nodeName);
            }
            if (\$childID) \$currentParent = \$childID;
        }

        // 2. VARIABLE SUCHEN
        \$remoteID = @IPS_GetObjectIDByIdent(\$safeIdent, \$currentParent);
        if (!\$remoteID) {
            foreach (IPS_GetChildrenIDs(\$currentParent) as \$cID) {
                if (IPS_GetObject(\$cID)['ObjectInfo'] === \$refString) {
                    \$remoteID = \$cID;
                    @IPS_SetIdent(\$remoteID, \$safeIdent);
                    break;
                }
            }
        }
        
        // 3. LÖSCHEN (unverändert)
        if (!empty(\$item['Delete'])) {
            if (\$remoteID > 0) {
                \$parentToCleanup = IPS_GetParent(\$remoteID);
                \$deleteRecursive = function(\$id) use (&\$deleteRecursive) {
                    foreach (IPS_GetChildrenIDs(\$id) as \$childID) \$deleteRecursive(\$childID);
                    \$obj = IPS_GetObject(\$id);
                    switch (\$obj['ObjectType']) {
                        case 0: @IPS_DeleteCategory(\$id); break;
                        case 1: @IPS_DeleteInstance(\$id); break;
                        case 2: @IPS_DeleteVariable(\$id); break;
                        case 3: @IPS_DeleteScript(\$id, true); break;
                    }
                };
                \$deleteRecursive(\$remoteID);
                while (\$parentToCleanup > 0 && \$parentToCleanup != \$rootID) {
                    if (!IPS_ObjectExists(\$parentToCleanup)) break;
                    \$children = IPS_GetChildrenIDs(\$parentToCleanup);
                    if (count(\$children) > 0) break;
                    \$obj = IPS_GetObject(\$parentToCleanup);
                    \$nextParent = IPS_GetParent(\$parentToCleanup);
                    if (\$obj['ObjectType'] == 0) {
                        @IPS_DeleteCategory(\$parentToCleanup);
                    } elseif (\$obj['ObjectType'] == 1) {
                        \$inst = IPS_GetInstance(\$parentToCleanup);
                        if (isset(\$inst['ModuleInfo']['ModuleID']) && strcasecmp(\$inst['ModuleInfo']['ModuleID'], '{485D0419-BE97-4548-AA9C-C083EB82E61E}') === 0) {
                            @IPS_DeleteInstance(\$parentToCleanup);
                        } else { break; }
                    } else { break; }
                    \$parentToCleanup = \$nextParent;
                }
            }
            continue;
        }

        // 4. ERSTELLEN
        if (!\$remoteID) {
            if (!\$autoCreate) continue;
            \$remoteID = IPS_CreateVariable(\$item['Type']);
            IPS_SetParent(\$remoteID, \$currentParent);
            IPS_SetName(\$remoteID, end(\$path));
            IPS_SetIdent(\$remoteID, \$safeIdent);
        }

        // 5. UPDATE
        if (\$remoteID) {
            IPS_SetInfo(\$remoteID, \$refString);
            SetValue(\$remoteID, \$item['Value']);
            if (!empty(\$item['Profile']) && IPS_VariableProfileExists(\$item['Profile'])) {
                IPS_SetVariableCustomProfile(\$remoteID, \$item['Profile']);
            }
            IPS_SetVariableCustomAction(\$remoteID, !empty(\$item['Action']) ? \$gatewayID : 0);
        }
    } catch (Exception \$e) {
        IPS_LogMessage('RemoteSync_RX', 'Error Item ' . \$item['LocalID'] . ': ' . \$e->getMessage());
    }
}
?>";
    }
















    private function GenerateGatewayCode(int $remSecID)
    {
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

    private function LoadConfig(): bool
    {
        // Falls die Konfiguration in diesem Prozess bereits geladen wurde, 
        // nutzen wir den Cache (spart CPU-Last bei Massenänderungen)
        if (!empty($this->config)) {
            return true;
        }

        try {
            $this->config = [
                'DebugMode'              => $this->ReadPropertyBoolean('DebugMode'),
                'AutoCreate'             => $this->ReadPropertyBoolean('AutoCreate'),
                'ReplicateProfiles'      => $this->ReadPropertyBoolean('ReplicateProfiles'),
                'LocalPasswordModuleID'  => $this->ReadPropertyInteger('LocalPasswordModuleID'),
                'LocalServerKey'         => $this->ReadPropertyString('LocalServerKey'),

                // Wir dekodieren die Listen hier zentral einmalig
                'Targets'                => json_decode($this->ReadPropertyString("Targets"), true) ?? [],
                'Roots'                  => json_decode($this->ReadPropertyString("Roots"), true) ?? [],
                'SyncList'               => json_decode($this->ReadAttributeString("SyncListCache"), true) ?? []
            ];

            return true;
        } catch (Exception $e) {
            $this->Log("LoadConfig Error: " . $e->getMessage(), KL_ERROR);
            return false;
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
        $result = file_get_contents($this->url, false, $context);
        // Die Prüfung 'if ($result === false)' existiert bereits im Code 
        // und wirft nun eine Exception mit der originalen PHP-Fehlermeldung.
        if ($result === false) throw new Exception("Connection failed");
        $response = json_decode($result, true);
        if (isset($response['error'])) throw new Exception($response['error']['message']);
        return $response['result'] ?? null;
    }
}
