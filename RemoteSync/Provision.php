<?php
declare(strict_types=1);

trait RemoteSyncProvision
{
    private const RECEIVER_MODULE = '{D07F247A-9FC0-49BF-961D-D2D54D0B9648}';

    private function receiverFor(string $folder, array $target): int
    {
        $source = $this->ReadAttributeString('SourceIdentity');
        $maps = [];
        foreach (json_decode($this->ReadPropertyString('Roots'), true, 64, JSON_THROW_ON_ERROR) as $root) {
            if ($root['TargetFolder'] !== $folder) continue;
            $mapping = hash('sha256', $folder . '|' . (int)$root['LocalRootID'] . '|' . (int)$root['RemoteRootID']);
            $maps[$mapping] = (int)$root['RemoteRootID'];
        }
        $actions = false;
        foreach (json_decode($this->ReadAttributeString('SyncListCache'), true, 64, JSON_THROW_ON_ERROR) as $row) {
            if ($row['Folder'] === $folder && !empty($row['Active']) && !empty($row['Action'])) { $actions = true; break; }
        }
        $settings = [
            'actions' => $actions,
            'key' => $this->ReadPropertyString('LocalServerKey'), 'maps' => $maps,
            'scriptRoot' => (int)$target['RemoteScriptRootID'], 'secrets' => (int)$target['RemoteSecretsID'],
            'timeout' => $this->ReadPropertyInteger('RequestTimeout')
        ];
        $fingerprint = hash('sha256', json_encode([$target, $settings, $this->rpcClient->endpoint(), $source], JSON_THROW_ON_ERROR));
        $key = 'Companion_' . hash('sha256', $folder);
        $lock = 'RS_Setup_' . $this->InstanceID . '_' . hash('sha256', $folder);
        if (!IPS_SemaphoreEnter($lock, 10000)) throw new RuntimeException('Destination setup is busy.');
        try {
            $cache = json_decode($this->GetBuffer($key), true);
            if (($cache['signature'] ?? '') === $fingerprint) return $cache['id'];
            $ident = 'RS_Companion_' . substr(hash('sha256', $source . '/' . $folder), 0, 32);
            $id = 0;
            foreach ($this->rpcClient->IPS_GetChildrenIDs($settings['scriptRoot']) as $child) {
                $object = $this->rpcClient->IPS_GetObject($child);
                if ($object['ObjectIdent'] !== $ident) continue;
                if ($object['ObjectType'] !== 1 || strtoupper($this->rpcClient->IPS_GetInstance($child)['ModuleInfo']['ModuleID']) !== self::RECEIVER_MODULE) {
                    throw new RuntimeException('Destination companion ident belongs to another object.');
                }
                $id = $child;
                break;
            }
            if (!$id) {
                if (!in_array(self::RECEIVER_MODULE, array_map('strtoupper', $this->rpcClient->IPS_GetModuleList()), true)) {
                    throw new RuntimeException('Destination library does not contain RemoteSync Receiver. Upgrade the RemoteSync library on that installation.');
                }
                // Recover an interrupted attachment before creating another instance.
                foreach ($this->rpcClient->IPS_GetInstanceListByModuleID(self::RECEIVER_MODULE) as $candidate) {
                    $object = $this->rpcClient->IPS_GetObject($candidate);
                    if ($object['ParentID'] === 0 && $object['ObjectIdent'] === $ident) { $id = $candidate; break; }
                }
                if (!$id) {
                    $id = $this->rpcClient->IPS_CreateInstance(self::RECEIVER_MODULE);
                    if (!$id || $this->rpcClient->IPS_SetIdent($id, $ident) === false) throw new RuntimeException('Could not identify new companion.');
                }
                if ($this->rpcClient->IPS_SetName($id, 'RemoteSync Receiver ' . $folder) === false ||
                    $this->rpcClient->IPS_SetParent($id, $settings['scriptRoot']) === false) throw new RuntimeException('Could not attach destination companion.');
            }
            if ($this->rpcClient->RSR_Configure($id, $source, json_encode($settings, JSON_THROW_ON_ERROR)) !== 2) {
                throw new RuntimeException('Destination did not confirm protocol 2.');
            }
            $this->SetBuffer($key, json_encode(['signature' => $fingerprint, 'id' => $id], JSON_THROW_ON_ERROR));
            return $id;
        } finally { IPS_SemaphoreLeave($lock); }
    }

    public function ProvisionTargets(): void
    {
        $this->SetTimerInterval('ProvisionTargets', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY || !$this->ReadPropertyBoolean('SyncEnabled')) return;
        $retry = false;
        foreach (json_decode($this->ReadPropertyString('Targets'), true) ?: [] as $target) {
            try {
                if (!$this->InitConnectionForFolder($target)) throw new RuntimeException('Check the target Secrets reference and HTTPS endpoint.');
                $this->receiverFor($target['Name'], $target);
            } catch (Throwable $e) {
                $this->Log('Destination ' . $target['Name'] . ': ' . $e->getMessage(), KL_ERROR);
                $retry = true;
            }
        }
        if ($retry) $this->SetTimerInterval('ProvisionTargets', 60000);
    }
}
