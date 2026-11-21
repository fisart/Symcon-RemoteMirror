<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Transport.php';
require_once __DIR__ . '/Delivery.php';
require_once __DIR__ . '/Provision.php';
require_once __DIR__ . '/Configuration.php';
require_once __DIR__ . '/Administration.php';

class RemoteSync extends IPSModule
{
    use RemoteSyncDelivery, RemoteSyncProvision, RemoteSyncConfiguration, RemoteSyncAdministration;

    const VERSION = '1.14.4';
    private $rpcClient = null;
    private $config = [];
    private $buffer = [];
    // We rely on attribute locking for state management
    private $isSending = false;


    public function Create()
    {
        parent::Create();
        $this->createAdministration();
        $this->RegisterPropertyBoolean('SyncEnabled', true);
        $this->RegisterPropertyInteger('RequestTimeout', 120);
        $this->RegisterAttributeString('SourceIdentity', '');
        $this->RegisterAttributeString('_DeliveryLanes', '{}');
        $this->RegisterAttributeString('_DeliveryRetry', '{}');
        $this->RegisterTimer('ProvisionTargets', 0, 'RS_ProvisionTargets($_IPS["TARGET"]);');

        $this->rpcClient = null;
        $this->config = [];
        $this->buffer = [];

        // Wir behalten nur noch die globalen Steuerungswerte
        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyBoolean('AutoCreate', true);
        $this->RegisterPropertyBoolean('ReplicateProfiles', true);
        $this->RegisterPropertyInteger('LaneCount', 3);
        $this->RegisterPropertyInteger('BatchIntervalMs', 1000);
        $this->RegisterPropertyBoolean('OnlyChangedValues', true);
        $this->RegisterPropertyInteger('PerformanceInterval', 60); // NEU: Intervall in s

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
        $this->RegisterAttributeString('_PerfBuffer', '{}'); // NEU: Puffer für Metriken
        $this->RegisterPropertyInteger('MaxBufferSize', 5000);
        // Timer für Performance-Metriken
        $this->RegisterTimer('UpdatePerformanceTimer', 0, 'RS_PublishPerformanceMetrics($_IPS[\'TARGET\']);');
        $this->RegisterTimer('FailedBatchRetry', 0, 'RS_RetryFailedBatches($_IPS[\'TARGET\']);');
        $this->RegisterTimer('BatchDispatch', 0, 'RS_DispatchPendingBatches($_IPS[\'TARGET\']);');

        // --- WICHTIG: NEU HINZUFÜGEN ---
        // Damit MessageSink Nachrichten über den Systemstart (Kernel Ready) erhält
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
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
            try {
                $keys = json_decode(SEC_GetKeys($secID), true);
                foreach (is_array($keys) ? $keys : [] as $k) $serverOptions[] = ['caption' => (string)$k, 'value' => (string)$k];
            } catch (Throwable $e) {
                $this->Log('Secrets list unavailable. Check the read approval for this instance.', KL_WARNING);
            }
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
                    "Immediate"   => $stateCache[$key]['Immediate'] ?? false,
                    "Action"      => $stateCache[$key]['Action'] ?? false,
                    "Delete"      => $stateCache[$key]['Delete'] ?? false
                ];
            }

            // Eindeutige ID für dieses Mapping (Folder + Root)
            $mappingID = md5($folderName . $localRootID);
            $listName  = "List_" . $mappingID;
            $quotedFolder = var_export($folderName, true);
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
                            ["type" => "Button", "caption" => "Sync ALL", "onClick" => "RS_ToggleAll(\$id, 'Active', true, $quotedFolder, $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Sync NONE", "onClick" => "RS_ToggleAll(\$id, 'Active', false, $quotedFolder, $localRootID);", "width" => "85px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],

                            // NEU v1.6.2: History Gruppe
                            ["type" => "Button", "caption" => "Hist ALL", "onClick" => "RS_ToggleAll(\$id, 'FullHistory', true, $quotedFolder, $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Hist NONE", "onClick" => "RS_ToggleAll(\$id, 'FullHistory', false, $quotedFolder, $localRootID);", "width" => "85px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],

                            // Action Gruppe
                            ["type" => "Button", "caption" => "Action ALL", "onClick" => "RS_ToggleAll(\$id, 'Action', true, $quotedFolder, $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Action NONE", "onClick" => "RS_ToggleAll(\$id, 'Action', false, $quotedFolder, $localRootID);", "width" => "85px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],

                            // Delete Gruppe
                            ["type" => "Button", "caption" => "Del ALL", "onClick" => "RS_ToggleAll(\$id, 'Delete', true, $quotedFolder, $localRootID);", "width" => "85px"],
                            ["type" => "Button", "caption" => "Del NONE", "onClick" => "RS_ToggleAll(\$id, 'Delete', false, $quotedFolder, $localRootID);", "width" => "85px"],
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
                            ["name" => "Immediate", "caption" => "Immediate", "width" => "85px", "edit" => ["type" => "CheckBox"]],
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

        $this->removeWebHook();
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
                    "Immediate"   => false,
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
    /** Compatibility entry point used by existing form buttons and scripts. */
    public function InstallRemoteScripts(string $Folder)
    {
        try {
            $target = $this->GetTargetConfig($Folder);
            if (!$target || !$this->InitConnectionForFolder($target)) throw new RuntimeException('Target connection unavailable.');
            $this->SetBuffer('Companion_' . hash('sha256', $Folder), '');
            $this->receiverFor($Folder, $target);
            echo 'Success: destination companion verified.';
        } catch (Throwable $e) {
            echo 'Error: ' . $e->getMessage();
        }
    }

    // --- RUNTIME ---

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->config = [];
        $this->rpcClient = null;
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        foreach ($this->GetMessageList() as $sender => $messages) {
            if ($sender > 0) $this->UnregisterMessage($sender, VM_UPDATE);
        }
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
        $this->initializeDelivery();
        $this->applyAdministration();
        $enabled = $this->ReadPropertyBoolean('SyncEnabled');
        $this->Log('Sender configuration applied. Synchronization is ' . ($enabled ? 'enabled.' : 'paused.'));
        $work = false;
        foreach (json_decode($this->ReadAttributeString('SyncListCache'), true) ?: [] as $row) {
            $id = (int)($row['ObjectID'] ?? 0);
            if (!$id || !IPS_ObjectExists($id)) continue;
            if (!empty($row['Active']) && empty($row['Delete']) && $enabled) $this->RegisterMessage($id, VM_UPDATE);
            $work = $work || !empty($row['Active']) || !empty($row['Delete']);
        }
        $this->migrateDelivery();
        $this->SetStatus($enabled && ($work || $this->ReadPropertyInteger('LocalPasswordModuleID')) ? 102 : 104);
        $this->SetTimerInterval('UpdatePerformanceTimer', max(10, $this->ReadPropertyInteger('PerformanceInterval')) * 1000);
        $this->SetTimerInterval('FailedBatchRetry', 0);
        $this->SetTimerInterval('ProvisionTargets', $enabled && json_decode($this->ReadPropertyString('Targets'), true) ? 1000 : 0);
        if ($enabled) {
            $this->DispatchPendingBatches();
            if ($work) IPS_RunScriptText('RS_ProcessSync(' . $this->InstanceID . ');');
        } else {
            $this->SetTimerInterval('BatchDispatch', 0);
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
        // NEU: Wenn der Kernel meldet "Ich bin fertig", führen wir ApplyChanges nochmal sauber aus
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->ApplyChanges();
            return;
        }

        if ($Message !== VM_UPDATE || !array_key_exists(0, $Data)) return;
        // Missing change metadata must not discard an update.
        $unchanged = array_key_exists(1, $Data) && !$Data[1];
        $this->AddToBuffer($SenderID, $Data[0], $unchanged);
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
            if (($root['TargetFolder'] ?? '') === $folderName && (int)$root['LocalRootID'] === (int)($itemConfig['LocalRootID'] ?? 0)) {
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
            'Source'       => $this->ReadAttributeString('SourceIdentity'),
            'MappingID'    => hash('sha256', $folderName . '|' . $localRootID . '|' . $remoteRootID),
            'EventID'      => bin2hex(random_bytes(16)),
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
        try {
            $vault = (int)($target['_SecretsID'] ?? $this->ReadPropertyInteger('LocalPasswordModuleID'));
            if (!$vault || empty($target['RemoteKey']) || !function_exists('SEC_GetSecret')) throw new RuntimeException('Missing Secrets reference.');
            $this->CountTraffic(['SecretLookups' => 1]);
            $credentials = RemoteSyncConnectionV2::credentials(SEC_GetSecret($vault, $target['RemoteKey']));
            $this->rpcClient = new RemoteSync_RPCClient($credentials, $this->ReadPropertyInteger('RequestTimeout'), function ($size) {
                $this->CountTraffic(['RPCRequests' => 1, 'RequestBodyBytes' => $size]);
            });
            return true;
        } catch (Throwable $e) {
            $this->rpcClient = null;
            $this->Log('Cannot read target connection. Check Secrets read approval and the HTTPS address.', KL_ERROR);
            return false;
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
        if ($this->ReadPropertyBoolean('DebugMode') || $Type === KL_ERROR || $Type === KL_WARNING) {
            $this->LogMessage($Message, KL_MESSAGE);
            $this->appendDebug($Message, $Type);
        }
    }

    public function PublishPerformanceMetrics()
    {
        $stateLock = "RS_StateLock_" . $this->InstanceID;
        $perf = [];
        $state = [];

        // 1. Atomarer Snapshot und Reset des Puffers
        if (IPS_SemaphoreEnter($stateLock, 1000)) {
            try {
                $perf = json_decode($this->ReadAttributeString('_PerfBuffer'), true) ?: [];
                $delivery = json_decode($this->GetDeliveryStatus(), true, 64, JSON_THROW_ON_ERROR);
                $this->WriteAttributeString('_PerfBuffer', '{}');
            } finally {
                IPS_SemaphoreLeave($stateLock);
            }
        } else {
            return; // Lock Timeout: Wir versuchen es beim nächsten Timer-Intervall erneut
        }

        // 2. Verarbeitung pro konfiguriertem Server-Target
        $targets = json_decode($this->ReadPropertyString("Targets"), true) ?: [];
        foreach ($targets as $target) {
            $folderName = $target['Name'] ?? '';
            if ($folderName === '') continue;

            $folderHash = md5($folderName);
            $short = substr($folderHash, 0, 20);
            $p = $perf[$folderName] ?? null;

            // -- Übertragungswerte (Durchschnittsberechnung) --
            if ($p) {
                if ($p['Count'] > 0) {
                    $rID = @IPS_GetObjectIDByIdent("R" . $short, $this->InstanceID);
                    if ($rID > 0) SetValue($rID, round($p['RTT'] / $p['Count'], 2));

                    $bID = @IPS_GetObjectIDByIdent("B" . $short, $this->InstanceID);
                    if ($bID > 0) SetValue($bID, $p['Batch']);

                    $sID = @IPS_GetObjectIDByIdent("S" . $short, $this->InstanceID);
                    if ($sID > 0) SetValue($sID, round($p['Size'], 2));

                    $dID = @IPS_GetObjectIDByIdent("D" . $short, $this->InstanceID);
                    if ($dID > 0) SetValue($dID, $p['Skipped']);

                    $lID = @IPS_GetObjectIDByIdent("L" . $short, $this->InstanceID);
                    if ($lID > 0) SetValue($lID, round($p['Lag'] / $p['Count'], 2));
                }

                // Error-Update erfolgt auch dann, wenn Count 0 ist
                // Error-Update erfolgt unabhängig von erfolgreichen Übertragungen
                if ($p && $p['Errors'] > 0) {
                    $eID = @IPS_GetObjectIDByIdent("E" . $short, $this->InstanceID);
                    if ($eID > 0) SetValue($eID, GetValue($eID) + $p['Errors']);
                }
            }

            // -- Queue Monitoring (Aktueller Füllstand aller Lanes) --
            $qVarID = @IPS_GetObjectIDByIdent("Q" . $short, $this->InstanceID);
            if ($qVarID > 0) {
                $totalQueue = 0;
                foreach ($delivery['lanes'] as $lane) {
                    if ($lane['folder'] === $folderName) $totalQueue += $lane['normalPending'] + $lane['historyPending'];
                }
                SetValue($qVarID, $totalQueue);
            }
        }
    }


    /** Reserve a worker before dispatch; wait only while queued work exists. */

    private function CountTraffic(array $increments): void
    {
        $lock = 'RS_Traffic_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $stats = json_decode($this->GetBuffer('TrafficStatistics'), true) ?: ['Since' => time()];
            foreach ($increments as $key => $value) $stats[$key] = ($stats[$key] ?? 0) + $value;
            // RAM only: telemetry must not create persistent attribute writes per read/request.
            $this->SetBuffer('TrafficStatistics', json_encode($stats));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    public function GetTrafficStatistics(): string
    {
        return json_encode((json_decode($this->GetBuffer('TrafficStatistics'), true) ?: ['Since' => time()]) + [
            'RPCRequests' => 0, 'RequestBodyBytes' => 0, 'SecretLookups' => 0,
            'FilteredUpdates' => 0, 'CoalescedUpdates' => 0, 'DroppedUpdates' => 0,
            'SuccessfulBatches' => 0
        ], JSON_PRETTY_PRINT);
    }

    public function ResetTrafficStatistics(): void
    {
        $lock = 'RS_Traffic_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $this->SetBuffer('TrafficStatistics', json_encode(['Since' => time()]));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
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
            $this->config['Index'] = [];
            foreach ($this->config['SyncList'] as $row) {
                $this->config['Index'][(int)$row['ObjectID']][] = $row;
            }
            return true;
        } catch (Exception $e) {
            $this->Log("LoadConfig Error: " . $e->getMessage(), KL_ERROR);
            return false;
        }
    }
}





class RemoteSync_RPCClient
{
    public function __construct(private array $connection, private int $timeout, private $onRequest) {}

    public function endpoint(): string { return $this->connection['url']; }

    public function __call($method, $params)
    {
        return RemoteSyncConnectionV2::call($this->connection, $method, $params, $this->timeout, $this->onRequest);
    }
}

