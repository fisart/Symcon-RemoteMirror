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
            $this->LogDebug("RPC Error in FindRemoteScript: " . $e->getMessage());
            return 0;
        }
    }

    // --- RUNTIME ---

    public function ApplyChanges()
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        parent::ApplyChanges();

        // WICHTIG: Interne Konfiguration zurücksetzen, damit sie neu geladen wird
        $this->rpcClient = null;
        $this->config = [];

        $this->WriteAttributeBoolean('_IsSending', false);
        $this->WriteAttributeString('_BatchBuffer', '[]');

        $syncListRaw = $this->ReadAttributeString("SyncListCache");

        // Korrigierter Fallback: Nur wenn das Attribut wirklich noch nie gesetzt wurde (leer)
        // Wir prüfen NICHT auf "[]", da dies eine gültige leere Auswahl ist.
        if ($syncListRaw === "") {
            $syncListRaw = $this->ReadPropertyString("SyncList");
            $this->WriteAttributeString("SyncListCache", $syncListRaw);
        }

        $syncList = json_decode($syncListRaw, true);

        $this->SetTimerInterval('BufferTimer', 0);
        $this->SetTimerInterval('StartSyncTimer', 0);

        // Alle alten Nachrichten-Registrierungen löschen
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            // Wir unregistrieren nur VM_UPDATE Nachrichten
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        $count = 0;
        $hasDeleteTask = false;
        if (is_array($syncList)) {
            foreach ($syncList as $item) {
                $isDelete = !empty($item['Delete']);
                $isActive = !empty($item['Active']);

                if ($isDelete) $hasDeleteTask = true;

                if ($isActive && !$isDelete && isset($item['ObjectID']) && IPS_ObjectExists((int)$item['ObjectID'])) {
                    $this->RegisterMessage((int)$item['ObjectID'], VM_UPDATE);
                    $count++;
                }
            }
        }

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
        switch ($Ident) {
            case "UpdateRow":
                $row = json_decode($Value, true);
                if (!$row || !isset($row['Folder'], $row['LocalRootID'], $row['ObjectID'])) return;

                // --- NEUE LOGIK ---
                if ($row['Delete']) {
                    $row['Active'] = false;
                }
                // ------------------

                $cache = json_decode($this->ReadAttributeString("SyncListCache"), true);
                if (!is_array($cache)) $cache = [];

                $map = [];
                foreach ($cache as $item) {
                    $k = ($item['Folder'] ?? '') . '_' . ($item['LocalRootID'] ?? 0) . '_' . ($item['ObjectID'] ?? 0);
                    $map[$k] = $item;
                }

                $key = $row['Folder'] . '_' . $row['LocalRootID'] . '_' . $row['ObjectID'];
                $map[$key] = $row;

                $this->WriteAttributeString("SyncListCache", json_encode(array_values($map)));
                break;
        }
    }

    public function SaveSelections()
    {
        // BEST PRACTICE: Wir verzichten auf IPS_SetProperty und IPS_ApplyChanges,
        // um die Instanz nicht unnötig neu zu starten und die Konfiguration 
        // nicht automatisiert zu überschreiben.

        // Da die Auswahl bereits durch RequestAction/ToggleAll im Attribut 
        // 'SyncListCache' liegt, müssen wir lediglich die Nachrichten-Registrierung 
        // aktualisieren. Dies erreichen wir durch einen manuellen Aufruf von ApplyChanges.
        $this->ApplyChanges();

        echo "Selection saved and active.";
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

        $this->SendDebug("RS_Connect", "Requesting SEC-ID: $secID for Key: $key", 0);

        if ($secID == 0 || $key === '') {
            $this->SendDebug("RS_Error", "SEC-ID is 0 or RemoteKey is empty.", 0);
            return false;
        }

        try {
            if (!function_exists('SEC_GetSecret')) {
                $this->SendDebug("RS_Error", "Function SEC_GetSecret not found. Is the Secrets Module installed?", 0);
                return false;
            }

            $json = SEC_GetSecret($secID, $key);
            $this->SendDebug("RS_Connect", "Data received from SEC: " . $json, 0);

            $config = json_decode($json, true);
            if (!is_array($config)) {
                $this->SendDebug("RS_Error", "JSON from SEC is invalid or empty.", 0);
                return false;
            }

            // Wir prüfen verschiedene Schreibweisen (Groß/Klein), um robust zu sein
            $urlRaw = $config['URL'] ?? $config['url'] ?? $config['Url'] ?? '';
            $user   = $config['User'] ?? $config['user'] ?? $config['Username'] ?? '';
            $pw     = $config['PW'] ?? $config['pw'] ?? $config['Password'] ?? '';

            if ($urlRaw === '') {
                $this->SendDebug("RS_Error", "URL field missing in SEC data.", 0);
                return false;
            }

            $connectionUrl = 'https://' . urlencode($user) . ":" . urlencode($pw) . "@" . $urlRaw . "/api/";

            // WICHTIG: Hier muss der Name Ihrer Klasse am Ende der Datei stehen!
            // Falls Ihre Klasse am Ende 'RemoteSync_RPCClient' heißt, muss das hier stehen:
            $this->rpcClient = new RemoteSync_RPCClient($connectionUrl);

            $this->SendDebug("RS_Connect", "RPC Client initialized for URL: " . $urlRaw, 0);
            return true;
        } catch (Exception $e) {
            $this->SendDebug("RS_Error", "Exception in InitConnection: " . $e->getMessage(), 0);
            return false;
        }
    }



    private function AddToBuffer($localID)
    {
        // Nutzt konsequent das Attribut
        $syncList = json_decode($this->ReadAttributeString("SyncListCache"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $rawBuffer = $this->ReadAttributeString('_BatchBuffer');
        $buffer = json_decode($rawBuffer, true);
        if (!is_array($buffer)) $buffer = [];

        if (!is_array($syncList) || !is_array($roots)) return;

        foreach ($syncList as $item) {
            // Logik: Variable in den Buffer aufnehmen, wenn Active ODER Delete gesetzt ist
            if ($item['ObjectID'] == $localID && (!empty($item['Active']) || !empty($item['Delete']))) {
                $folderName = $item['Folder'];

                // Mapping suchen (Ihre Manager-Logik)
                $foundMapping = false;
                $localRootID = 0;
                $remoteRootID = 0;
                foreach ($roots as $root) {
                    if (($root['TargetFolder'] ?? '') === $folderName) {
                        if ($this->IsChildOf((int)$localID, (int)$root['LocalRootID'])) {
                            $localRootID = (int)$root['LocalRootID'];
                            $remoteRootID = (int)$root['RemoteRootID'];
                            $foundMapping = true;
                            break;
                        }
                    }
                }

                if (!$foundMapping || !IPS_ObjectExists($localID)) continue;

                $var = IPS_GetVariable($localID);
                $payload = [
                    'LocalID' => $localID,
                    'Value'   => GetValue($localID),
                    'Type'    => $var['VariableType'],
                    'Profile' => $var['VariableCustomProfile'] ?: $var['VariableProfile'],
                    'Name'    => IPS_GetName($localID),
                    'Ident'   => IPS_GetObject($localID)['ObjectIdent'],
                    'Key'     => $this->ReadPropertyString("LocalServerKey"),
                    'Action'  => !empty($item['Action']),
                    'Delete'  => !empty($item['Delete']) // WICHTIG: Flag für den Receiver
                ];

                // Pfad-Berechnung (Ihre Manager-Logik)
                $pathStack = [];
                $currentID = $localID;
                while ($currentID != $localRootID && $currentID > 0) {
                    array_unshift($pathStack, IPS_GetName($currentID));
                    $currentID = IPS_GetParent($currentID);
                }
                $payload['Path'] = $pathStack;

                $bufferKey = $folderName . ':' . $remoteRootID;
                $buffer[$bufferKey][$localID] = $payload;
            }
        }

        $this->WriteAttributeString('_BatchBuffer', json_encode($buffer));
        $this->SetTimerInterval('BufferTimer', 200);
    }


    private function IsChildOf(int $objectID, int $parentID): bool
    {
        if ($objectID === $parentID) return true;
        while ($objectID > 0) {
            $objectID = IPS_GetParent($objectID);
            if ($objectID === $parentID) return true;
        }
        return false;
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
            foreach ($fullBuffer as $bufferKey => $variables) {
                $parts = explode(':', $bufferKey);
                if (count($parts) < 2) continue;

                $folderName = $parts[0];
                $remoteRootID = (int)$parts[1];

                $target = $this->GetTargetConfig($folderName);
                if (!$target || !$this->InitConnectionForFolder($target)) continue;

                $batch = array_values($variables);
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

                $packet = [
                    'TargetID'   => $remoteRootID, // Nutzt die ID aus dem Mapping (Step 2)
                    'Batch'      => $batch,
                    'AutoCreate' => $this->ReadPropertyBoolean('AutoCreate'),
                    'Profiles'   => $profiles
                ];

                $receiverID = $this->FindRemoteScript((int)$target['RemoteScriptRootID'], "RemoteSync_Receiver");
                if ($receiverID > 0) {
                    $this->rpcClient->IPS_RunScriptWaitEx($receiverID, ['DATA' => json_encode($packet)]);
                }
            }
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

if (!is_array(\$packet)) return;

\$batch      = \$packet['Batch'] ?? [];
\$rootID     = \$packet['TargetID'] ?? 0;
\$autoCreate = !empty(\$packet['AutoCreate']); 
\$gatewayID  = $gwID;
\$profiles   = \$packet['Profiles'] ?? [];

if (!is_array(\$batch) || \$rootID == 0) return;

// --- Profile Creation (identisch) ---
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
    try {
        \$localID   = \$item['LocalID'];
        \$serverKey = \$item['Key'];
        \$safeIdent = 'Rem_' . \$localID;
        \$refString = 'RS_REF:' . \$serverKey . ':' . \$localID;
        \$path      = \$item['Path'] ?? [];

        // 1. PFAD AUFLÖSEN (Um in die Instanz-Ebene einzutauchen)
        \$currentParent = \$rootID;
        foreach (\$path as \$index => \$nodeName) {
            // Wenn wir beim letzten Element sind, ist das die Variable selbst
            if (\$index === count(\$path) - 1) break;

            \$childID = @IPS_GetObjectIDByName(\$nodeName, \$currentParent);
            if (!\$childID && \$autoCreate) {
                // Erstelle Dummy-Instanz als Container (Spiegel der HW-Instanz)
                \$childID = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
                IPS_SetParent(\$childID, \$currentParent);
                IPS_SetName(\$childID, \$nodeName);
            }
            if (\$childID) \$currentParent = \$childID;
        }

        // 2. VARIABLE SUCHEN (Im nun korrekten Unterordner)
        // Erst über Ident suchen
        \$remoteID = @IPS_GetObjectIDByIdent(\$safeIdent, \$currentParent);
        
        // Falls nicht gefunden, über das Info-Feld suchen (sehr sicher)
        if (!\$remoteID) {
            foreach (IPS_GetChildrenIDs(\$currentParent) as \$cID) {
                if (IPS_GetObject(\$cID)['ObjectInfo'] === \$refString) {
                    \$remoteID = \$cID;
                    @IPS_SetIdent(\$remoteID, \$safeIdent);
                    break;
                }
            }
        }

        // 3. LÖSCHEN
        if (!empty(\$item['Delete'])) {
            if (\$remoteID > 0) {
                // Rekursives Löschen
                \$deleteFunc = function(\$id) use (&\$deleteFunc) {
                    foreach (IPS_GetChildrenIDs(\$id) as \$childID) \$deleteFunc(\$childID);
                    \$type = IPS_GetObject(\$id)['ObjectType'];
                    switch (\$type) {
                        case 0: @IPS_DeleteCategory(\$id); break;
                        case 1: @IPS_DeleteInstance(\$id); break;
                        case 2: @IPS_DeleteVariable(\$id); break;
                        case 3: @IPS_DeleteScript(\$id, true); break;
                    }
                };
                \$deleteFunc(\$remoteID);
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
            
            // Aktion setzen (Gateway)
            IPS_SetVariableCustomAction(\$remoteID, !empty(\$item['Action']) ? \$gatewayID : 0);
        }
    } catch (Exception \$e) {
        IPS_LogMessage('RemoteSync_RX', 'Error Item ' . \$item['LocalID'] . ': ' . \$e->getMessage());
    }
}
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
                'DebugMode'              => $this->ReadPropertyBoolean('DebugMode'),
                'AutoCreate'             => $this->ReadPropertyBoolean('AutoCreate'),
                'ReplicateProfiles'      => $this->ReadPropertyBoolean('ReplicateProfiles'),
                'LocalPasswordModuleID'  => $this->ReadPropertyInteger('LocalPasswordModuleID'),
                'RemoteServerKey'        => $this->ReadPropertyString('RemoteServerKey'),
                'RemotePasswordModuleID' => $this->ReadPropertyInteger('RemotePasswordModuleID'),
                'LocalServerKey'         => $this->ReadPropertyString('LocalServerKey'),
                'LocalRootID'            => $this->ReadPropertyInteger('LocalRootID'),
                'RemoteRootID'           => $this->ReadPropertyInteger('RemoteRootID'),
                'RemoteScriptRootID'     => $this->ReadPropertyInteger('RemoteScriptRootID'),
                'SyncListRaw'            => $this->ReadAttributeString('SyncListCache')
            ];

            $this->config['SyncList'] = json_decode($this->config['SyncListRaw'], true);
            if (!is_array($this->config['SyncList'])) {
                $this->config['SyncList'] = [];
            }
            return true;
        } catch (Exception $e) {
            $this->SendDebug("LoadConfig Error", $e->getMessage(), 0);
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
        // Kein @ verwenden. ReadProperty sollte im laufenden Betrieb nicht fehlschlagen.
        if ($this->ReadPropertyBoolean('DebugMode')) {
            IPS_LogMessage('RemoteSync', $msg);
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
