<?php

declare(strict_types=1);

// Version 1.5.5

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

        // Diese Property dient nur noch als persistente Hülle für die Sync-Liste
        $this->RegisterPropertyString('SyncList', '[]');

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
        // StartSyncTimer dient dem initialen Anstoß nach ApplyChanges
        $this->RegisterTimer('StartSyncTimer', 0, 'RS_ProcessSync($_IPS[\'TARGET\']);');
    }


    public function UpdateUI()
    {
        $this->ReloadForm();
    }

    // --- FORM & UI ---
    public function GetConfigurationForm()
    {
        // Sicherstellen, dass der RAM-Arbeitsspeicher (Blueprint) beim Start gefüllt ist
        if ($this->ReadAttributeString("SyncListCache") === "[]") {
            $this->WriteAttributeString("SyncListCache", $this->ReadPropertyString("SyncList"));
        }

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

                            // Action Gruppe
                            ["type" => "Button", "caption" => "Action ALL", "onClick" => "RS_ToggleAll(\$id, 'Action', true, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Action NONE", "onClick" => "RS_ToggleAll(\$id, 'Action', false, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],

                            // NEU: Delete Gruppe
                            ["type" => "Button", "caption" => "Del ALL", "onClick" => "RS_ToggleAll(\$id, 'Delete', true, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Del NONE", "onClick" => "RS_ToggleAll(\$id, 'Delete', false, '$folderName', $localRootID);", "width" => "85px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],

                            // Management & Installation
                            ["type" => "Button", "caption" => "💾 SAVE ALL", "onClick" => "RS_SaveSelections(\$id);", "width" => "100px"],
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
                            ["name" => "LocalRootID", "caption" => "Root", "visible" => false], // Versteckt für interne Logik
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



    public function Destroy()
    {
        // Alle Timer sicher stoppen
        @$this->SetTimerInterval('StartSyncTimer', 0);

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
                $map[$key] = ["Folder" => $Folder, "LocalRootID" => $LocalRootID, "ObjectID" => $vID, "Name" => IPS_GetName($vID), "Active" => false, "Action" => false, "Delete" => false];
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
            $gwID = $this->FindRemoteScript($scriptRoot, "RemoteSync_Gateway");
            $this->SendDebug("RS_Install", "Gateway Script located/created at ID: " . $gwID, 0);

            $gwCode = $this->GenerateGatewayCode($remoteSecID);
            $this->rpcClient->IPS_SetScriptContent($gwID, $gwCode);

            $rxID = $this->FindRemoteScript($scriptRoot, "RemoteSync_Receiver");
            $this->SendDebug("RS_Install", "Receiver Script located/created at ID: " . $rxID, 0);

            $this->rpcClient->IPS_SetScriptContent($rxID, $this->GenerateReceiverCode($gwID));

            $this->SendDebug("RS_Install", "Scripts successfully updated on Remote System.", 0);
            echo "Success: Scripts installed on $Folder";
        } catch (Exception $e) {
            $this->SendDebug("RS_Error", "Exception during Install: " . $e->getMessage(), 0);
            echo "Error: " . $e->getMessage();
        }
    }


    private function FindRemoteScript(int $parentID, string $name): int
    {
        try {
            // 1. Suche nach existierendem Skript
            $children = $this->rpcClient->IPS_GetChildrenIDs($parentID);
            if (is_array($children)) {
                foreach ($children as $cID) {
                    $obj = $this->rpcClient->IPS_GetObject($cID);
                    if ($obj['ObjectType'] == 3 && $obj['ObjectName'] == $name) {
                        return $cID;
                    }
                }
            }

            // 2. Erstellung, falls nicht gefunden
            $id = $this->rpcClient->IPS_CreateScript(0);
            $this->rpcClient->IPS_SetParent($id, $parentID);
            $this->rpcClient->IPS_SetName($id, $name);

            return $id;
        } catch (Exception $e) {
            $this->Log("RPC Error in FindRemoteScript: " . $e->getMessage(), KL_MESSAGE);
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

        // 1. Timer initial stoppen
        $this->SetTimerInterval('StartSyncTimer', 0);

        // --- NACHRICHTEN-CLEANUP ---
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // --- KONSISTENZ-PRÜFUNG DER KONFIGURATION ---
        $syncListRaw = $this->ReadAttributeString("SyncListCache");

        if ($syncListRaw === "" || $syncListRaw === "[]") {
            $syncListRaw = $this->ReadPropertyString("SyncList");
            $this->WriteAttributeString("SyncListCache", $syncListRaw);
        }

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
                $this->SetTimerInterval('StartSyncTimer', 500);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $this->Log("REQ_RAW: " . $Value, KL_MESSAGE);
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

                    // --- NEUE LOGIK (Pro Zeile angewendet) ---
                    if ($row['Delete']) {
                        $row['Active'] = false;
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
        // Wir setzen die Property permanent auf leer, da wir nun nur noch 
        // mit dem Attribut SyncListCache arbeiten.
        IPS_SetProperty($this->InstanceID, "SyncList", "[]");

        // Triggert ApplyChanges, was die neue Bereinigungs-Logik ausführt
        IPS_ApplyChanges($this->InstanceID);

        echo "Selection saved and configuration cleaned.";
    }


    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->AddToBuffer($SenderID);
    }

    public function ProcessSync()
    {
        $this->SetTimerInterval('StartSyncTimer', 0);
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
    private function GetPayload(int $localID, array $itemConfig, array $roots): ?array
    {
        $folderName = $itemConfig['Folder'];
        $foundMapping = false;
        $localRootID = 0;
        $remoteRootID = 0;

        foreach ($roots as $root) {
            if ($localID == 37889) {
                $this->Log("CHECKMAPPING: Testing Var 37889 against Root " . $root['LocalRootID'] . " for Folder " . $root['TargetFolder'], KL_MESSAGE);
            }
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

        return [
            'LocalID'      => $localID,
            'LocalSetID'   => $localRootID, // Identifikator für die Performance-Variable
            'RemoteRootID' => $remoteRootID, // Hilfswert für die Gruppierung
            'Folder'       => $folderName,   // Hilfswert
            'Value'        => GetValue($localID),
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

    private function AddToBuffer($localID)
    {
        if (IPS_GetKernelRunlevel() !== KR_READY || !$this->LoadConfig()) return;

        foreach ($this->config['SyncList'] as $item) {
            if ($item['ObjectID'] == $localID && (!empty($item['Active']) || !empty($item['Delete']))) {

                $mappingID = md5(($item['Folder'] ?? '') . ($item['LocalRootID'] ?? 0));
                $short = substr($mappingID, 0, 20);
                $payload = $this->GetPayload((int)$localID, $item, $this->config['Roots']);

                if ($payload) {
                    if ($localID == 37889) {
                        $this->Log("TRACEDELETE: Variable 37889 checked. Delete-Flag in Payload is: " . ($payload['Delete'] ? 'TRUE' : 'FALSE'), KL_MESSAGE);
                    }
                    // Unified State Access (Batch, Events, Starts)
                    $state = json_decode($this->ReadAttributeString('_SyncState'), true) ?: ['buffer' => [], 'events' => [], 'starts' => []];

                    // 1. In den segmentierten Puffer schreiben (Deduplizierung)
                    $state['buffer'][$mappingID][$localID] = $payload;

                    // 2. Update event counter for deflation tracking
                    $state['events'][$mappingID] = ($state['events'][$mappingID] ?? 0) + 1;

                    // 3. Update processing lag start time
                    if (!isset($state['starts'][$mappingID]) || $state['starts'][$mappingID] == 0) {
                        $state['starts'][$mappingID] = microtime(true);
                    }

                    // Write unified state
                    $this->WriteAttributeString('_SyncState', json_encode($state));

                    // 4. Update Queue Size Monitoring
                    $qVarID = @IPS_GetObjectIDByIdent("Q" . $short, $this->InstanceID);
                    if ($qVarID > 0) SetValue($qVarID, count($state['buffer'][$mappingID]));

                    $this->Log("[BUFFER-CHECK] AddToBuffer: Single item $localID added.", KL_MESSAGE);

                    // --- TRACE LOGIK FÜR ID 25458 ---
                    if ($localID == 25458) {
                        $this->Log("[TRACE-25458] AddToBuffer: Variable successfully written to Attribute Puffer.", KL_NOTIFY);
                    }

                    // 5. Worker-Check: Versuchen die Semaphore für dieses Set zu bekommen
                    $lockName = "RS_Lock_" . $this->InstanceID . "_" . $mappingID;

                    // Asynchroner Worker-Start via RunScriptText (vermeidet Race Conditions in AddToBuffer)
                    $script = "RS_FlushBuffer(" . $this->InstanceID . ", '" . $mappingID . "');";
                    @IPS_RunScriptText($script);
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
        $roots = json_decode($this->ReadPropertyString("Roots"), true) ?: [];
        $count = 0;
        foreach ($roots as $root) {
            $localID = (int)($root['LocalRootID'] ?? 0);
            if ($localID === 0) continue;

            $objectName = IPS_ObjectExists($localID) ? IPS_GetName($localID) : "ID " . $localID;
            $folder = $root['TargetFolder'] ?? 'Unknown';

            // Eindeutiger Ident basierend auf dem Kurz-Hash des Sets (max 32 Chars)
            $mappingID = md5($folder . $localID);
            $short = substr($mappingID, 0, 20);

            $this->MaintainVariable("R" . $short, "RTT: " . $objectName . " (" . $folder . ")", 2, "", 0, true);
            $this->MaintainVariable("B" . $short, "Batch: " . $objectName . " (" . $folder . ")", 1, "", 0, true);
            $this->MaintainVariable("S" . $short, "Size: " . $objectName . " (" . $folder . ")", 2, "", 0, true);
            $this->MaintainVariable("E" . $short, "Errors: " . $objectName . " (" . $folder . ")", 1, "", 0, true);
            $this->MaintainVariable("D" . $short, "Skipped: " . $objectName . " (" . $folder . ")", 1, "", 0, true);
            $this->MaintainVariable("L" . $short, "Lag: " . $objectName . " (" . $folder . ")", 2, "", 0, true);
            $this->MaintainVariable("Q" . $short, "Queue: " . $objectName . " (" . $folder . ")", 1, "", 0, true);
            $count++;
        }
        echo "Successfully installed performance variables for $count sets.";
    }

    public function DeletePerformanceVariables()
    {
        // Wir scannen alle Mappings, um die entsprechenden Variablen gezielt zu löschen
        $roots = json_decode($this->ReadPropertyString("Roots"), true) ?: [];
        foreach ($roots as $root) {
            $localID = (int)($root['LocalRootID'] ?? 0);
            $folder = $root['TargetFolder'] ?? 'Unknown';
            if ($localID > 0) {
                $mappingID = md5($folder . $localID);
                $short = substr($mappingID, 0, 20);
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

    public function FlushBuffer(string $MappingID = "")
    {
        $this->Log("!!! SEMAPHORE-CHECK: Ich bin in FlushBuffer gelandet für Set $MappingID !!!", KL_MESSAGE);

        if ($MappingID === "" || IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $lockName = "RS_Lock_" . $this->InstanceID . "_" . $MappingID;
        $short = substr($MappingID, 0, 20);

        // Der Worker-Thread versucht hier als Erstes, die Semaphore zu betreten.
        if (!IPS_SemaphoreEnter($lockName, 0)) {
            $this->Log("[BUFFER-CHECK] FlushBuffer: EXIT - already sending (Busy) for Set $MappingID", KL_MESSAGE);
            return;
        }

        try {
            $state = json_decode($this->ReadAttributeString('_SyncState'), true) ?: ['buffer' => [], 'events' => [], 'starts' => []];

            if (!isset($state['buffer'][$MappingID]) || count($state['buffer'][$MappingID]) === 0) {
                return;
            }

            $variables = $state['buffer'][$MappingID];
            $totalItems = count($variables);

            $this->Log("[BUFFER-CHECK] FlushBuffer: STARTING TRANSMISSION. Total items in this batch for $MappingID: $totalItems", KL_MESSAGE);

            // Puffer-Segment sofort leeren
            unset($state['buffer'][$MappingID]);

            // Deflation Tracking Snapshot
            $eventCount = $state['events'][$MappingID] ?? $totalItems;
            $skipped = max(0, $eventCount - $totalItems);
            $state['events'][$MappingID] = 0;

            // Processing Lag Snapshot
            $firstEventTime = $state['starts'][$MappingID] ?? microtime(true);
            $state['starts'][$MappingID] = 0;

            // Write consolidated state back
            $this->WriteAttributeString('_SyncState', json_encode($state));

            // Queue Size Monitoring Reset
            $qVarID = @IPS_GetObjectIDByIdent("Q" . $short, $this->InstanceID);
            if ($qVarID > 0) SetValue($qVarID, 0);

            $firstVar = reset($variables);
            $target = $this->GetTargetConfig($firstVar['Folder']);
            $localSetID = (int)($firstVar['LocalSetID'] ?? 0);

            if (!$target || !$this->InitConnectionForFolder($target)) {
                $this->Log("[BUFFER-CHECK] FlushBuffer: ERROR - Connection to " . $firstVar['Folder'] . " failed.", KL_ERROR);
                return;
            }

            // --- TRACE LOGIK FÜR ID 25458 ---
            if (isset($variables[25458])) {
                $this->Log("[TRACE-25458] FlushBuffer: ID 25458 IS PRESENT in the batch. Value: " . (string)$variables[25458]['Value'], KL_NOTIFY);
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
                'TargetID'   => (int)$firstVar['RemoteRootID'],
                'Batch'      => $batch,
                'AutoCreate' => $this->ReadPropertyBoolean('AutoCreate'),
                'Profiles'   => $profiles
            ];

            $jsonPacket = json_encode($packet);

            // --- JSON VALIDIERUNG ---
            if ($jsonPacket === false) {
                $errorMsg = json_last_error_msg();
                $this->Log("[BUFFER-CHECK] ERROR: JSON Encoding failed. Error: " . $errorMsg, KL_ERROR);
                return;
            }

            // VOLUMETRIC MEASUREMENT
            $sizeKB = round(strlen($jsonPacket) / 1024, 2);

            $receiverID = $this->FindRemoteScript((int)$target['RemoteScriptRootID'], "RemoteSync_Receiver");

            if ($receiverID > 0 && $this->rpcClient) {
                $this->Log("[BUFFER-CHECK] FlushBuffer: Sending " . count($batch) . " items (" . $sizeKB . " KB) to " . $firstVar['Folder'], KL_MESSAGE);

                // TEMPORAL MEASUREMENT START
                $startTime = microtime(true);

                $result = @$this->rpcClient->IPS_RunScriptWaitEx($receiverID, ['DATA' => $jsonPacket]);

                // TEMPORAL MEASUREMENT END
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                // UPDATE PERFORMANCE VARIABLES
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

                $this->Log("[PERF-DEBUG] Mapping: $MappingID, IdentShort: $short, Time: $duration ms, Lag: $lag s", KL_MESSAGE);
                $this->Log("[BUFFER-CHECK] FlushBuffer: Remote response: " . $result . " (Time: " . $duration . "ms)", KL_MESSAGE);
            }
        } catch (Exception $e) {
            $this->Log("[BUFFER-CHECK] FlushBuffer Exception: " . $e->getMessage(), KL_ERROR);

            // ERROR COUNTER MEASUREMENT
            $errVarID = @IPS_GetObjectIDByIdent("E" . $short, $this->InstanceID);
            if ($errVarID > 0) SetValue($errVarID, GetValue($errVarID) + 1);
        } finally {
            // Semaphore UNBEDINGT wieder freigeben
            IPS_SemaphoreLeave($lockName);

            // Yield-Check: Sind neue Daten für DIESES Set reingekommen während wir gesendet haben?
            $checkState = json_decode($this->ReadAttributeString('_SyncState'), true);
            if (isset($checkState['buffer'][$MappingID]) && count($checkState['buffer'][$MappingID]) > 0) {
                $this->Log("[BUFFER-CHECK] FlushBuffer: NEW DATA arrived during transmission for $MappingID. Restarting...", KL_MESSAGE);
                $script = "RS_FlushBuffer(" . $this->InstanceID . ", '" . $MappingID . "');";
                @IPS_RunScriptText($script);
            } else {
                $this->Log("[BUFFER-CHECK] FlushBuffer: FINISHED for $MappingID. No more data pending.", KL_MESSAGE);
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
\$rootID     = \$packet['TargetID'] ?? 0;
\$autoCreate = !empty(\$packet['AutoCreate']); 
\$gatewayID  = $gwID;
\$profiles   = \$packet['Profiles'] ?? [];

if (!is_array(\$batch) || \$rootID == 0) return;

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
        $result = @file_get_contents($this->url, false, $context);
        if ($result === false) throw new Exception("Connection failed");
        $response = json_decode($result, true);
        if (isset($response['error'])) throw new Exception($response['error']['message']);
        return $response['result'] ?? null;
    }
}
