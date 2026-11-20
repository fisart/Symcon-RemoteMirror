<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/Journal.php';
require_once __DIR__ . '/../libs/Transport.php';

class RemoteSyncReceiver extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('Binding', '{}');
    }

    private function binding(): array
    {
        return json_decode($this->ReadAttributeString('Binding'), true, 64, JSON_THROW_ON_ERROR);
    }

    private function withLock(string $name, callable $operation)
    {
        $global = str_starts_with($name, 'root_') || $name === 'profiles';
        $lock = 'RSR_' . ($global ? '' : $this->InstanceID . '_') . $name;
        if (!IPS_SemaphoreEnter($lock, 10000)) throw new RuntimeException('Receiver busy.');
        try { return $operation(); } finally { IPS_SemaphoreLeave($lock); }
    }

    /** Called by the sender over the existing authenticated Symcon JSON-RPC connection. */
    public function Configure(string $Source, string $Settings): int
    {
        return $this->withLock('setup', function () use ($Source, $Settings) {
            $new = json_decode($Settings, true, 32, JSON_THROW_ON_ERROR);
            $old = $this->binding();
            if (!preg_match('/^[a-f0-9]{32}$/D', $Source) || ($old && $old['source'] !== $Source)) throw new RuntimeException('Receiver belongs to another sender.');
            if (!is_string($new['key'] ?? null) || !is_array($new['maps'] ?? null) || !is_int($new['secrets'] ?? null)
                || ($new['scriptRoot'] ?? null) !== IPS_GetParent($this->InstanceID)) throw new RuntimeException('Invalid receiver configuration.');
            foreach ($new['maps'] as $mapping => $root) {
                if (!preg_match('/^[a-f0-9]{64}$/D', $mapping) || !is_int($root) || !IPS_ObjectExists($root)
                    || !in_array(IPS_GetObject($root)['ObjectType'], [0, 1], true)) throw new RuntimeException('Remote root is unavailable.');
            }
            if (!empty($new['actions'])) {
                $secret = SEC_GetSecret($new['secrets'], $new['key']);
                $connection = RemoteSyncConnectionV2::credentials($secret);
                $version = RemoteSyncConnectionV2::call($connection, 'IPS_GetKernelVersion', [], $new['timeout']);
                if (!is_string($version) || $version === '') throw new RuntimeException('Cannot verify the reverse HTTPS endpoint.');
            }
            $this->SetBuffer('LegacyIndex', '');
            $new['source'] = $Source;
            $new['gateway'] = $this->configureGateway($new['scriptRoot']);
            $this->WriteAttributeString('Binding', json_encode($new, JSON_THROW_ON_ERROR));
            return 2;
        });
    }

    private function configureGateway(int $root): int
    {
        $script = @IPS_GetObjectIDByIdent('RS_Gateway', $root);
        $forward = '<?php RSR_ForwardAction(' . $this->InstanceID . ', (int)$_IPS["VARIABLE"], json_encode($_IPS["VALUE"], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));';
        if (!$script) {
            foreach (IPS_GetChildrenIDs($root) as $id) {
                if (in_array(IPS_GetName($id), ['RemoteSync Gateway', 'RemoteSync_Gateway'], true)) {
                    if ($script) return $this->privateGateway($forward);
                    $script = $id;
                }
            }
        }
        if ($script) {
            if (IPS_GetObject($script)['ObjectType'] !== 3) return $this->privateGateway($forward);
            $code = trim(str_replace("\r\n", "\n", IPS_GetScriptContent($script)));
            if ($code === $forward) return $script;
            $normalized = preg_replace('/^\$secID = [0-9]+;$/m', '$secID = 0;', $code);
            if (!hash_equals('4d01cb3e0c5362731f3ca462b4f470ee2f5e87f928084c78e7bd40397138714a', hash('sha256', $normalized))) {
                return $this->privateGateway($forward);
            }
        } else {
            return $this->privateGateway($forward);
        }
        if (!IPS_SetScriptContent($script, $forward)) throw new RuntimeException('Cannot upgrade the action script.');
        return $script;
    }

    private function privateGateway(string $forward): int
    {
        $id = @IPS_GetObjectIDByIdent('ActionGateway', $this->InstanceID);
        if ($id) {
            if (IPS_GetObject($id)['ObjectType'] !== 3 || trim(IPS_GetScriptContent($id)) !== $forward) throw new RuntimeException('The companion action adapter has been modified.');
            return $id;
        }
        $id = IPS_CreateScript(0);
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetName($id, 'RemoteSync action adapter');
        if (!IPS_SetScriptContent($id, $forward)) throw new RuntimeException('Cannot create the companion action adapter.');
        IPS_SetIdent($id, 'ActionGateway');
        return $id;
    }

    private function storage(): string
    {
        return IPS_GetKernelDir() . 'remotesync-received/' . $this->InstanceID;
    }

    public function Receive(string $Packet): string
    {
        $packet = json_decode($Packet, true, 64, JSON_THROW_ON_ERROR);
        $binding = $this->binding();
        if (!$binding || ($packet['Protocol'] ?? null) !== 2 || ($packet['Source'] ?? null) !== $binding['source']
            || !is_array($packet['Batch'] ?? null) || count($packet['Batch']) > 400) throw new RuntimeException('Incompatible sender or protocol.');
        $accepted = $errors = $blocked = [];
        foreach ($packet['Batch'] as $item) {
            $id = $item['EventID'] ?? '';
            $stream = ($item['MappingID'] ?? '') . '/' . ($item['LocalID'] ?? '');
            try {
                if (isset($blocked[$stream])) throw new RuntimeException('Earlier value in this stream is pending.');
                if (!preg_match('/^[a-f0-9]{32}$/D', $id) || ($item['Source'] ?? null) !== $binding['source']
                    || !isset($binding['maps'][$item['MappingID'] ?? '']) || $binding['maps'][$item['MappingID']] !== ($item['RemoteRootID'] ?? null)
                    || !is_int($item['LocalID'] ?? null) || $item['LocalID'] <= 0 || ($item['Key'] ?? null) !== $binding['key']) throw new RuntimeException('Invalid item ownership.');
                $this->withLock(hash('sha256', $stream), function () use ($item, $binding, $packet) {
                    $reliable = !empty($item['Reliable']);
                    $receipt = $this->storage() . '/history/' . substr($item['EventID'], 0, 2) . '/' . $item['EventID'] . '.json';
                    if ($reliable && is_file($receipt)) return;
                    $intent = $receipt . '.pending';
                    $resuming = $reliable && is_file($intent);
                    if ($reliable && !$resuming) {
                        RemoteSyncJournalV2::durableWrite($intent, json_encode($item, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                    }
                    $this->applyMirror($item, $binding, $packet, $resuming);
                    if ($reliable) RemoteSyncJournalV2::finish($intent, $receipt);
                });
                $accepted[] = $id;
            } catch (Throwable $e) {
                if (!empty($item['Reliable'])) $blocked[$stream] = true;
                $errors[] = ['EventID' => $id, 'message' => $e->getMessage()];
                $this->LogMessage('RemoteSync variable ' . (int)($item['LocalID'] ?? 0) . ': ' . $e->getMessage(), KL_MESSAGE);
            }
        }
        return json_encode(['Protocol' => 2, 'Accepted' => $accepted, 'Errors' => $errors], JSON_THROW_ON_ERROR);
    }

    private function applyMirror(array $item, array $binding, array $packet, bool $resuming): void
    {
        $type = $item['Type'] ?? -1;
        $value = $item['Value'] ?? null;
        if (!match ($type) {0 => is_bool($value), 1 => is_int($value), 2 => is_float($value) || is_int($value), 3 => is_string($value), default => false}) {
            throw new RuntimeException('Variable type and value differ.');
        }
        $path = $item['Path'] ?? null;
        if (!is_array($path) || !$path || count($path) > 128 || !is_string($item['Name'] ?? null)) throw new RuntimeException('Invalid variable path.');
        $root = $item['RemoteRootID'];
        $ref = 'RS_REF:' . $binding['key'] . ':' . $item['LocalID'];
        $owner = ['source' => $binding['source'], 'mapping' => $item['MappingID'], 'localID' => $item['LocalID']];
        $auto = !empty($packet['AutoCreate']);
        $delete = !empty($item['Delete']);
        $remote = $this->withLock('root_' . $root, function () use ($root, $ref, $owner, $auto, $delete, $type, $path, $item) {
            $cacheKey = 'Mirror_' . $owner['mapping'] . '_' . $owner['localID'];
            $remembered = (int)$this->GetBuffer($cacheKey);
            if ($remembered && IPS_ObjectExists($remembered)) {
                $object = IPS_GetObject($remembered);
                $ancestor = $remembered;
                while ($ancestor && $ancestor !== $root) $ancestor = IPS_GetParent($ancestor);
                if ($ancestor !== $root || $object['ObjectType'] !== 2 || $object['ObjectInfo'] !== $ref || IPS_GetVariable($remembered)['VariableType'] !== $type) {
                    throw new RuntimeException('Previously matched mirror has changed ownership, type or root.');
                }
                return $remembered;
            }
            $parent = $root;
            $cached = $this->legacyCandidates($root, $ref);
            if (count($cached) > 1) throw new RuntimeException('Multiple existing mirrors have the same source reference. Original objects preserved.');
            if ($cached) {
                $parent = IPS_GetParent($cached[0]);
                $path = [IPS_GetName($cached[0])];
            }
            foreach (array_slice($path, 0, -1) as $name) {
                if (!is_string($name) || $name === '') throw new RuntimeException('Invalid path component.');
                $children = array_values(array_filter(IPS_GetChildrenIDs($parent), static fn($child) => IPS_GetName($child) === $name));
                if (count($children) > 1) throw new RuntimeException('Ambiguous destination path.');
                $next = $children[0] ?? 0;
                if (!$next) {
                    if (!$auto || $delete) break;
                    $next = IPS_CreateCategory();
                    if (!$next || IPS_SetName($next, $name) === false || IPS_SetParent($next, $parent) === false) throw new RuntimeException('Cannot create destination container.');
                }
                if (!in_array(IPS_GetObject($next)['ObjectType'], [0, 1], true)) throw new RuntimeException('Destination path conflict.');
                $parent = $next;
            }
            $ident = 'Rem_' . $item['LocalID'];
            $byIdent = @IPS_GetObjectIDByIdent($ident, $parent);
            $matches = [];
            foreach (IPS_GetChildrenIDs($parent) as $child) {
                if (IPS_GetObject($child)['ObjectInfo'] === $ref) $matches[] = $child;
            }
            if (count($matches) > 1 || ($byIdent && !in_array($byIdent, $matches, true))) throw new RuntimeException('Ambiguous mirror or ownership conflict.');
            $remote = $matches[0] ?? 0;
            if (!$remote) {
                if ($delete) return 0;
                if (!$auto) throw new RuntimeException('Existing mirror missing and automatic creation disabled.');
                $remote = IPS_CreateVariable($type);
                if (!$remote || IPS_SetInfo($remote, $ref) === false || IPS_SetName($remote, $item['Name']) === false
                    || IPS_SetParent($remote, $parent) === false || IPS_SetIdent($remote, $ident) === false) throw new RuntimeException('Cannot create destination mirror.');
            }
            if (IPS_GetObject($remote)['ObjectType'] !== 2 || IPS_GetVariable($remote)['VariableType'] !== $type) throw new RuntimeException('Mirror variable type conflict.');
            $file = $this->storage() . '/bindings/' . $remote . '.json';
            $claim = IPS_GetKernelDir() . 'remotesync-received/owners/' . $remote . '.json';
            $identity = ['receiver' => $this->InstanceID] + $owner;
            if (is_file($claim) && json_decode(file_get_contents($claim), true, 16, JSON_THROW_ON_ERROR) !== $identity) throw new RuntimeException('Mirror already claimed by another receiver or mapping.');
            if (is_file($file) && json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR) !== $owner) throw new RuntimeException('Mirror belongs to another mapping.');
            if (!is_file($claim)) RemoteSyncJournalV2::durableWrite($claim, json_encode($identity, JSON_THROW_ON_ERROR));
            if (!is_file($file)) RemoteSyncJournalV2::durableWrite($file, json_encode($owner, JSON_THROW_ON_ERROR));
            $this->SetBuffer($cacheKey, (string)$remote);
            return $remote;
        });
        if (!$remote) return;
        if ($delete) {
            if (IPS_GetChildrenIDs($remote)) throw new RuntimeException('Mirror has children. Delete refused.');
            if (!IPS_DeleteVariable($remote)) throw new RuntimeException('Mirror deletion failed.');
            $file = $this->storage() . '/bindings/' . $remote . '.json';
            if (is_file($file)) unlink($file);
            $claim = IPS_GetKernelDir() . 'remotesync-received/owners/' . $remote . '.json';
            if (is_file($claim)) unlink($claim);
            return;
        }
        $profile = $item['Profile'] ?? '';
        if ($profile !== '') $this->applyProfile($remote, $profile, $packet['Profiles'][$profile] ?? null);
        $action = !empty($item['Action']) ? $binding['gateway'] : 0;
        if ((IPS_GetVariable($remote)['VariableCustomAction'] ?? 0) !== $action && !IPS_SetVariableCustomAction($remote, $action)) throw new RuntimeException('Action binding failed.');
        if ($resuming && GetValue($remote) === $value) return;
        if (SetValue($remote, $value) === false) throw new RuntimeException('Value update failed.');
    }

    private function legacyCandidates(int $root, string $reference): array
    {
        $known = json_decode($this->GetBuffer('LegacyIndex'), true) ?: [];
        if (!isset($known[$root])) {
            $known[$root] = [];
            $todo = [$root];
            while ($todo) {
                foreach (IPS_GetChildrenIDs(array_pop($todo)) as $child) {
                    $object = IPS_GetObject($child);
                    if ($object['ObjectType'] === 2 && str_starts_with($object['ObjectInfo'], 'RS_REF:')) {
                        $known[$root][$object['ObjectInfo']][] = $child;
                    }
                    if (!empty($object['HasChildren']) || in_array($object['ObjectType'], [0, 1], true)) $todo[] = $child;
                }
            }
            $this->SetBuffer('LegacyIndex', json_encode($known, JSON_THROW_ON_ERROR));
        }
        $matches = [];
        foreach ($known[$root][$reference] ?? [] as $id) {
            if (!IPS_ObjectExists($id) || IPS_GetObject($id)['ObjectInfo'] !== $reference) continue;
            $parent = $id;
            while ($parent && $parent !== $root) $parent = IPS_GetParent($parent);
            if ($parent !== $root) throw new RuntimeException('Existing mirror moved outside the configured root.');
            $matches[] = $id;
        }
        return $matches;
    }

    private function applyProfile(int $id, string $profile, ?array $definition): void
    {
        $this->withLock('profiles', static function () use ($profile, $definition, $id) {
            if (!IPS_VariableProfileExists($profile) && $definition !== null) {
                IPS_CreateVariableProfile($profile, $definition['ProfileType']);
                IPS_SetVariableProfileText($profile, $definition['Prefix'], $definition['Suffix']);
                IPS_SetVariableProfileIcon($profile, $definition['Icon']);
                IPS_SetVariableProfileValues($profile, $definition['MinValue'], $definition['MaxValue'], $definition['StepSize']);
                IPS_SetVariableProfileDigits($profile, $definition['Digits']);
                foreach ($definition['Associations'] ?? [] as $a) IPS_SetVariableProfileAssociation($profile, $a['Value'], $a['Name'], $a['Icon'], $a['Color']);
            }
            if (IPS_VariableProfileExists($profile) && IPS_GetVariable($id)['VariableCustomProfile'] !== $profile) IPS_SetVariableCustomProfile($id, $profile);
        });
    }

    public function ForwardAction(int $VariableID, string $JSONValue): void
    {
        $binding = $this->binding();
        $path = $this->storage() . '/bindings/' . $VariableID . '.json';
        if (!$binding || !is_file($path)) throw new RuntimeException('Mirror has not yet been confirmed by its sender.');
        $owner = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $root = $binding['maps'][$owner['mapping']] ?? 0;
        if (!$root || $owner['source'] !== $binding['source'] || IPS_GetVariable($VariableID)['VariableCustomAction'] !== $binding['gateway']
            || IPS_GetObject($VariableID)['ObjectInfo'] !== 'RS_REF:' . $binding['key'] . ':' . $owner['localID']) throw new RuntimeException('Unverified action binding.');
        $parent = $VariableID;
        while ($parent && $parent !== $root) $parent = IPS_GetParent($parent);
        if ($parent !== $root) throw new RuntimeException('Mirror moved outside its mapped root.');
        $secret = SEC_GetSecret($binding['secrets'], $binding['key']);
        $connection = RemoteSyncConnectionV2::credentials($secret);
        $result = RemoteSyncConnectionV2::call($connection, 'RequestAction', [$owner['localID'], json_decode($JSONValue, true, 16, JSON_THROW_ON_ERROR)], $binding['timeout']);
        if ($result === false) throw new RuntimeException('The source action handler refused the request.');
    }
}
