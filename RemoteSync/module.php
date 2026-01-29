<?php

declare(strict_types=1);

// Version 1.9.8

class RemoteSync extends IPSModule
{
    const VERSION = '1.5.5';
    private $rpcClient = null;
    private $config = [];
    private $buffer = [];
    // We rely on attribute locking for state management
    private $isSending = false;
    private $remoteScriptCache = []; // NEU v1.9.7: RAM-Cache für Remote-IDs

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
        $this->RegisterPropertyInteger('LaneCount', 3);

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
        $this->RegisterAttributeString('_RemoteIDCache', '{}');

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

            // ID im persistenten Cache speichern
            $idCache = json_decode($this->ReadAttributeString('_RemoteIDCache'), true) ?: [];
            $idCache[$Folder . '_RX'] = $rxID;
            $idCache[$Folder . '_GW'] = $gwID;
            $this->WriteAttributeString('_RemoteIDCache', json_encode($idCache));
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
                    $this->Log("Critical Error: Ident '$ident' is already used by a non-script object. Please delete it on remote system.", KL_ERROR);
                    return 0;
                }
            }
        } catch (Exception $e) {
            // Nicht gefunden über Ident, wir machen weiter
        }

        // 2. NAMENS-FALLBACK (Heilung alter Installationen)
        try {
            $possibleNames = ($ident === 'RS_Gateway')
                ? ['RemoteSync_Gateway', 'RemoteSync Gateway']
                : ['RemoteSync_Receiver', 'RemoteSync Receiver'];

            $children = $this->rpcClient->IPS_GetChildrenIDs($parentID);
            if (is_array($children)) {
                foreach ($children as $cID) {
                    $obj = $this->rpcClient->IPS_GetObject($cID);

                    if (is_array($obj) && isset($obj['ObjectType']) && $obj['ObjectType'] == 3 && in_array($obj['ObjectName'], $possibleNames)) {
                        try {
                            $this->rpcClient->IPS_SetIdent($cID, $ident);
                            $this->rpcClient->IPS_SetName($cID, ($ident === 'RS_Gateway' ? 'RemoteSync Gateway' : 'RemoteSync Receiver'));
                            return $cID;
                        } catch (Exception $eIdent) {
                            // Falls das Setzen des Idents hier scheitert, nutzen wir das Skript trotzdem
                            return $cID;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Fehler beim Durchlaufen der Kinder
        }

        // 3. NEUERSTELLUNG mit Sicherheitsbremse gegen leere Dubletten (v1.7.8)
        try {
            $id = $this->rpcClient->IPS_CreateScript(0);
            $this->rpcClient->IPS_SetParent($id, $parentID);
            $this->rpcClient->IPS_SetName($id, ($ident === 'RS_Gateway' ? 'RemoteSync Gateway' : 'RemoteSync Receiver'));

            try {
                $this->rpcClient->IPS_SetIdent($id, $ident);
                return $id;
            } catch (Exception $eIdent) {
                // --- KORREKTUR v1.7.8: Sicherheits-Löschung ---
                // Der Ident ist blockiert. Wir löschen das neue leere Skript sofort wieder,
                // damit keine Geister-Skripte ohne Inhalt entstehen.
                $this->rpcClient->IPS_DeleteScript($id, true);

                // Letzter Versuch: Wir holen uns die ID des Objekts, das den Ident blockiert
                $existingID = $this->rpcClient->IPS_GetObjectIDByIdent($ident, $parentID);
                $this->Log("Notice: Ident '$ident' was blocked. Using existing object ID $existingID instead.", KL_MESSAGE);
                return $existingID;
            }
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

        // --- SELBSTHEILUNG SCHRITT 1: ROOTS (Step 2) BEREINIGEN ---
        $rootsRaw = $this->ReadPropertyString("Roots");
        $roots = json_decode($rootsRaw, true) ?: [];
        $cleanedRoots = [];
        $rootsChanged = false;

        foreach ($roots as $root) {
            $rID = (int)($root['LocalRootID'] ?? 0);
            if ($rID > 0 && IPS_ObjectExists($rID)) {
                $cleanedRoots[] = $root;
            } else {
                $this->Log("Self-Healing: Removing dead Root ID $rID from configuration.", KL_WARNING);
                $rootsChanged = true;
            }
        }

        if ($rootsChanged) {
            IPS_SetProperty($this->InstanceID, "Roots", json_encode($cleanedRoots));
            // Wir arbeiten intern sofort mit der bereinigten Liste weiter
        }
        $roots = $cleanedRoots;

        // --- KONSISTENZ-PRÜFUNG DER KONFIGURATION (Step 3) ---
        $syncListRaw = $this->ReadAttributeString("SyncListCache");

        $syncList = json_decode($syncListRaw, true) ?: [];
        // Hinweis: $roots ist hier bereits die bereinigte Liste aus Schritt 1

        $cleanedSyncList = [];
        $uniqueCheck = [];
        $count = 0;
        $hasDeleteTask = false;

        foreach ($syncList as $item) {
            $vID = (int)($item['ObjectID'] ?? 0);
            $folder = $item['Folder'] ?? '';
            $rootID = (int)($item['LocalRootID'] ?? 0);

            // SELBSTHEILUNG: Nur weitermachen, wenn das Objekt wirklich existiert
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
                // SELBSTHEILUNG: Verwaiste Variablen (deren Root gelöscht wurde) werden hier entfernt
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
                // --- NEUE DEBUG SONDE FÜR ID 25458 ---
                if ($vID == 25458) {
                    $this->LogMessage("DEBUG_25458: ApplyChanges - RegisterMessage executed for variable 25458", KL_MESSAGE);
                }
                // -------------------------------------
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
        if ($SenderID == 25458) {
            $this->LogMessage("DEBUG_25458: Message received in MessageSink", KL_MESSAGE);
        }
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
            'Timestamp'    => microtime(true),
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

    private function AddToBuffer($localID, $Value = null)
    {
        if (IPS_GetKernelRunlevel() !== KR_READY || !$this->LoadConfig()) return;

        // --- NEU v1.9.0: Lane-Berechnung ---
        $laneCount = $this->ReadPropertyInteger('LaneCount');
        if ($laneCount < 1) $laneCount = 1;
        $laneID = ($localID % $laneCount) + 1;
        // -----------------------------------

        foreach ($this->config['SyncList'] as $item) {
            if ($localID == 25458) {
                // Wir loggen den Status, den das Modul aktuell im Speicher (Cache) hat
                $this->LogMessage("DEBUG_25458: AddToBuffer triggered. Searching in SyncList Cache...", KL_MESSAGE);
            }
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
                            // --- NEU v1.9.1: Automatische Struktur-Migration (Fix für Fatal Error) ---
                            if (isset($state['buffer'][$folderName]) && !empty($state['buffer'][$folderName])) {
                                $firstElement = reset($state['buffer'][$folderName]);
                                // Wenn das erste Element das Feld 'LocalID' hat, ist es noch das v1.8 Format
                                if (is_array($firstElement) && isset($firstElement['LocalID'])) {
                                    $state['buffer'][$folderName] = []; // Puffer für diesen Folder leeren
                                    $state['events'][$folderName] = []; // Events für diesen Folder leeren
                                    $state['starts'][$folderName] = []; // Zeitstempel für diesen Folder leeren
                                }
                            }
                            // --------------------------------------------------------------------------

                            // v1.6.2 Logik (Anti-Conflation)
                            $bufferKey = (string)$localID;

                            // --- NEU v1.8.7: Zeitstempel-Schutz (Timestamp Preservation) ---
                            // ÄNDERUNG v1.9.0: Pfad inkludiert jetzt die laneID
                            if (isset($state['buffer'][$folderName][$laneID][$bufferKey]['Timestamp'])) {
                                $payload['Timestamp'] = $state['buffer'][$folderName][$laneID][$bufferKey]['Timestamp'];
                            }
                            // ----------------------------------------------------------------

                            // 1. In den segmentierten Puffer schreiben (Pfad inkl. LaneID)
                            $state['buffer'][$folderName][$laneID][$bufferKey] = $payload;

                            // 2. Update event counter (Lane-spezifisch für präzise Metriken)
                            $state['events'][$folderName][$laneID] = ($state['events'][$folderName][$laneID] ?? 0) + 1;

                            // 3. Update processing lag start time (Lane-spezifisch)
                            if (!isset($state['starts'][$folderName][$laneID]) || $state['starts'][$folderName][$laneID] == 0) {
                                $state['starts'][$folderName][$laneID] = microtime(true);
                            }

                            // Status zurückschreiben (NOCH INNERHALB DES LOCKS)
                            $this->WriteAttributeString('_SyncState', json_encode($state));
                        } finally {
                            IPS_SemaphoreLeave($stateLock);
                        }
                    }

                    // 4. Update Queue Size Monitoring (Summe über alle Lanes dieses Folders)
                    $qVarID = @IPS_GetObjectIDByIdent("Q" . $short, $this->InstanceID);
                    if ($qVarID > 0) {
                        $currentState = json_decode($this->ReadAttributeString('_SyncState'), true);
                        $totalQueue = 0;
                        if (isset($currentState['buffer'][$folderName])) {
                            foreach ($currentState['buffer'][$folderName] as $laneData) {
                                $totalQueue += count($laneData);
                            }
                        }
                        SetValue($qVarID, $totalQueue);
                    }

                    // 5. Worker-Start (MODIFIZIERT v1.9.0: Lane-spezifischer Pre-Flight Check)
                    // Der Lock-Name enthält nun die LaneID
                    $lockName = "RS_Lock_" . $this->InstanceID . "_" . $folderHash . "_" . $laneID;

                    // Wir prüfen, ob die Semaphore für DIESE Lane bereits belegt ist
                    if (IPS_SemaphoreEnter($lockName, 0)) {
                        IPS_SemaphoreLeave($lockName);

                        // Übergabe der LaneID an den Worker
                        $script = "RS_FlushBuffer(" . $this->InstanceID . ", '" . $folderName . "', " . $laneID . ");";
                        if (!IPS_RunScriptText($script)) {
                            $this->Log("Critical Error: Worker thread for server '$folderName' Lane $laneID could not be started.", KL_ERROR);
                        }
                    } else {
                        // Lane ist belegt -> Bestehender Worker übernimmt die Daten später
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
    public function GetSyncState(): string
    {
        // Best Practice: Wir geben den Inhalt des geschützten Attributs 
        // für externe Analyse-Tools frei.
        return $this->ReadAttributeString('_SyncState');
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

    public function FlushBuffer(string $FolderName = "", int $LaneID = 1)
    {
        if ($FolderName === "" || IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $folderHash = md5($FolderName);
        // ÄNDERUNG v1.9.0: Semaphore ist nun Lane-spezifisch
        $lockName = "RS_Lock_" . $this->InstanceID . "_" . $folderHash . "_" . $LaneID;
        $short = substr($folderHash, 0, 20);

        if (!IPS_SemaphoreEnter($lockName, 0)) {
            return;
        }

        try {
            // --- NEU v1.6.4: State-Lock für atomares Auslesen und Leeren des Puffers ---
            $stateLock = "RS_StateLock_" . $this->InstanceID;
            $variables = [];
            $totalInBucketBefore = 0;
            $skipped = 0;
            $firstEventTime = microtime(true);

            // --- NEU v1.8.2: Chunking-Parameter ---
            $maxItemsPerBatch = 400;

            if (IPS_SemaphoreEnter($stateLock, 1000)) {
                try {
                    $state = json_decode($this->ReadAttributeString('_SyncState'), true) ?: ['buffer' => [], 'events' => [], 'starts' => []];

                    // ÄNDERUNG v1.9.0: Zugriff auf das Lane-spezifische Segment
                    if (!isset($state['buffer'][$FolderName][$LaneID]) || count($state['buffer'][$FolderName][$LaneID]) === 0) {
                        IPS_SemaphoreLeave($stateLock);
                        IPS_SemaphoreLeave($lockName);
                        return;
                    }

                    // --- CHIRURGISCHE ÄNDERUNG v1.8.2/1.9.0: Nur Teilmenge der Lane entnehmen ---
                    $allVariables = $state['buffer'][$FolderName][$LaneID];
                    $totalInBucketBefore = count($allVariables);

                    $variables = array_slice($allVariables, 0, $maxItemsPerBatch, true);
                    $totalItems = count($variables);

                    // Wir löschen NUR die entnommenen Items aus dem Puffer-Segment der Lane
                    foreach ($variables as $key => $val) {
                        unset($state['buffer'][$FolderName][$LaneID][$key]);
                    }
                    // -------------------------------------------------------------

                    // Metriken Snapshots (Lane-spezifisch)
                    $eventCount = $state['events'][$FolderName][$LaneID] ?? $totalItems;
                    $skipped = max(0, $eventCount - $totalItems);
                    $state['events'][$FolderName][$LaneID] = 0;

                    // v1.8.8: Wir sichern den globalen Startzeitpunkt der Warteschlange dieser Lane
                    $firstEventTime = $state['starts'][$FolderName][$LaneID] ?? microtime(true);

                    // --- KORREKTUR v1.8.5: Ehrliche Lag-Messung pro Lane ---
                    if (count($state['buffer'][$FolderName][$LaneID]) === 0) {
                        $state['starts'][$FolderName][$LaneID] = 0;
                    }
                    // -----------------------------------------------

                    // Sofort zurückschreiben
                    $this->WriteAttributeString('_SyncState', json_encode($state));
                } finally {
                    IPS_SemaphoreLeave($stateLock);
                }
            } else {
                throw new Exception("State-Lock timeout in FlushBuffer");
            }

            // --- AB HIER: Verarbeitung der extrahierten $variables ---

            $this->Log("[BUFFER-CHECK] FlushBuffer: STARTING TRANSMISSION. Items in this server-batch (Lane $LaneID) for $FolderName: $totalItems", KL_MESSAGE);

            // Queue Size Monitoring (Wir zeigen hier weiterhin die Summe aller Lanes zur Übersicht)
            $qVarID = @IPS_GetObjectIDByIdent("Q" . $short, $this->InstanceID);
            if ($qVarID > 0) {
                $currentState = json_decode($this->ReadAttributeString('_SyncState'), true);
                $totalQueue = 0;
                if (isset($currentState['buffer'][$FolderName])) {
                    foreach ($currentState['buffer'][$FolderName] as $laneData) $totalQueue += count($laneData);
                }
                SetValue($qVarID, $totalQueue);
            }

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

            // --- NEU v1.9.8: Persistenter ID-Cache (Attribute) ---
            $idCache = json_decode($this->ReadAttributeString('_RemoteIDCache'), true) ?: [];
            $receiverID = $idCache[$FolderName . '_RX'] ?? 0;

            if ($receiverID === 0) {
                $receiverID = $this->GetRemoteScriptID((int)$target['RemoteScriptRootID'], "RS_Receiver");
                if ($receiverID > 0) {
                    $idCache[$FolderName . '_RX'] = $receiverID;
                    $this->WriteAttributeString('_RemoteIDCache', json_encode($idCache));
                }
            }
            // -----------------------------------------------------

            // Falls das Skript fehlt, geben wir einen Hinweis aus
            if ($receiverID === 0) {
                $this->Log("RemoteSync Error: Receiver script not found on '" . $FolderName . "'. Please run 'Install Remote' in Step 1.", KL_ERROR);
            }

            if ($receiverID > 0 && $this->rpcClient) {

                $startTime = microtime(true);
                // KORREKTUR v1.9.0: Zurück zu RunScriptWaitEx für RTT/Error Messung
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

                // Lag-Berechnung gegen den globalen Startzeitpunkt der Lane
                $lag = round(microtime(true) - $firstEventTime, 2);
                $lagVarID = @IPS_GetObjectIDByIdent("L" . $short, $this->InstanceID);
                if ($lagVarID > 0) SetValue($lagVarID, $lag);
            }
        } catch (Exception $e) {
            $this->Log("[BUFFER-CHECK] FlushBuffer Exception Lane $LaneID: " . $e->getMessage(), KL_ERROR);
            $errVarID = @IPS_GetObjectIDByIdent("E" . $short, $this->InstanceID);
            if ($errVarID > 0) SetValue($errVarID, GetValue($errVarID) + 1);
        } finally {
            IPS_SemaphoreLeave($lockName);

            // Yield-Check (v1.9.0: Prüft gezielt die eigene Lane und reicht die LaneID weiter)
            $checkState = json_decode($this->ReadAttributeString('_SyncState'), true);
            if (isset($checkState['buffer'][$FolderName][$LaneID]) && count($checkState['buffer'][$FolderName][$LaneID]) > 0) {
                $script = "RS_FlushBuffer(" . $this->InstanceID . ", '" . $FolderName . "', " . $LaneID . ");";
                @IPS_RunScriptText($script);
            }
        }
    }
    // --- CODE GENERATORS ---




    private function GetRemoteScriptID(int $parentID, string $ident): int
    {
        // 1. Suche über Ident
        try {
            $id = $this->rpcClient->IPS_GetObjectIDByIdent($ident, $parentID);
            if ($id > 0) {
                $obj = $this->rpcClient->IPS_GetObject($id);
                // KORREKTUR v1.9.6: Check auf Array-Validität (Offset-Fix)
                if (is_array($obj) && isset($obj['ObjectType']) && $obj['ObjectType'] == 3) {
                    return $id;
                }
            }
        } catch (Exception $e) {
        }

        // 2. Fallback: Suche über Namen (ohne das Skript zu heilen oder zu verändern)
        try {
            $possibleNames = ($ident === 'RS_Gateway')
                ? ['RemoteSync_Gateway', 'RemoteSync Gateway']
                : ['RemoteSync_Receiver', 'RemoteSync Receiver'];

            $children = $this->rpcClient->IPS_GetChildrenIDs($parentID);
            if (is_array($children)) {
                foreach ($children as $cID) {
                    $obj = $this->rpcClient->IPS_GetObject($cID);
                    // KORREKTUR v1.9.6: Check auf Array-Validität (Offset-Fix)
                    if (is_array($obj) && isset($obj['ObjectType']) && $obj['ObjectType'] == 3 && in_array($obj['ObjectName'], $possibleNames)) {
                        return $cID;
                    }
                }
            }
        } catch (Exception $e) {
        }

        return 0; // Nichts gefunden
    }


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
        // KORREKTUR v1.9.2: Sicherheitscheck, ob das Objekt existiert
        if (!IPS_ObjectExists($parentID)) {
            return;
        }

        $children = IPS_GetChildrenIDs($parentID);

        // KORREKTUR v1.9.2: Sicherstellen, dass wir ein Array erhalten
        if (!is_array($children)) {
            return;
        }

        foreach ($children as $childID) {
            $obj = @IPS_GetObject($childID);
            if ($obj !== false) {
                if ($obj['ObjectType'] == 2) {
                    $result[] = $childID;
                }
                if ($obj['HasChildren']) {
                    $this->GetRecursiveVariables($childID, $result);
                }
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
    private $url;

    public function __construct($url)
    {
        $this->url = $url;
    }
    public function __construct($url)
    {
        $this->url = $url;
    }

    public function __call($method, $params)
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => time()
        ]);

        $ch = curl_init($this->url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ],
            CURLOPT_TIMEOUT        => 60,
            // KORREKTUR v1.9.9: Mehr Zeit für den Verbindungsaufbau bei hoher Last
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $errno  = curl_errno($ch);

        curl_close($ch);

        if ($result === false) {
            throw new Exception("cURL Error ($errno): $error");
        }

        $response = json_decode($result, true);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message']);
        }

        return $response['result'] ?? null;
    }
}
