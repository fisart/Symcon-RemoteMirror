<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/Journal.php';

trait RemoteSyncDelivery
{
    private function initializeDelivery(): void
    {
        $dir=IPS_GetKernelDir().'remotesync/'.$this->InstanceID;RemoteSyncJournalV2::ensureDirectory($dir);
        $lock='RS_Identity_'.$this->InstanceID;if(!IPS_SemaphoreEnter($lock,10000))throw new RuntimeException('Source identity busy.');
        try{
            $file=$dir.'/identity';
            if(is_file($file))$identity=trim(file_get_contents($file));
            else{$identity=$this->ReadAttributeString('SourceIdentity')?:bin2hex(random_bytes(16));RemoteSyncJournalV2::durableWrite($file,$identity);}
            if(!preg_match('/^[a-f0-9]{32}$/D',$identity))throw new RuntimeException('Invalid source identity. Preserve delivery storage and repair before enabling.');
            if($this->ReadAttributeString('SourceIdentity')!==$identity)$this->WriteAttributeString('SourceIdentity',$identity);
            $lanes=json_decode($this->ReadAttributeString('_DeliveryLanes'),true)?:[];
            foreach(glob($dir.'/'.$identity.'/*/route.json')?:[] as $file){$route=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);if(!is_string($route['folder']??null)||!is_int($route['lane']??null))throw new RuntimeException('Invalid delivery route.');$lanes[$this->laneKey($route['folder'],$route['lane'])]=$route;}
            $this->WriteAttributeString('_DeliveryLanes',json_encode($lanes,JSON_THROW_ON_ERROR));
        }finally{IPS_SemaphoreLeave($lock);}
    }
    private function route(string $folder,int $lane): ?array
    {
        $file=IPS_GetKernelDir().'remotesync/'.$this->InstanceID.'/'.$this->ReadAttributeString('SourceIdentity').'/'.$this->laneKey($folder,$lane).'/route.json';
        return is_file($file)?json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR):null;
    }
    private function persistRoute(string $folder,int $lane): void
    {
        $dir=IPS_GetKernelDir().'remotesync/'.$this->InstanceID.'/'.$this->ReadAttributeString('SourceIdentity').'/'.$this->laneKey($folder,$lane);
        $route=['folder'=>$folder,'lane'=>$lane,'target'=>$this->GetTargetConfig($folder),'secretsID'=>$this->ReadPropertyInteger('LocalPasswordModuleID')];
        if(!$route['target'])throw new RuntimeException('Full History target is unavailable. Event was not accepted.');
        $old=$this->route($folder,$lane);
        if($old==$route)return;
        if($old&&$this->journal($folder,$lane)->pending())throw new RuntimeException('Full History route changed while delivery is pending. Restore the target settings or drain the original route before capturing new events.');
        RemoteSyncJournalV2::ensureDirectory($dir);RemoteSyncJournalV2::durableWrite($dir.'/route.json',json_encode($route,JSON_THROW_ON_ERROR));
    }
    private function laneKey(string $folder,int $lane): string {return hash('sha256',$folder.'|'.$lane);}
    private function journal(string $folder,int $lane): RemoteSyncJournalV2 {
        return new RemoteSyncJournalV2(IPS_GetKernelDir().'remotesync/'.$this->InstanceID.'/'.$this->ReadAttributeString('SourceIdentity').'/'.$this->laneKey($folder,$lane));
    }
    private function registerLane(string $folder,int $lane): void {
        $key=$this->laneKey($folder,$lane);$lanes=json_decode($this->ReadAttributeString('_DeliveryLanes'),true)?:[];
        if(isset($lanes[$key]))return;
        $lock='RS_Lanes_'.$this->InstanceID;if(!IPS_SemaphoreEnter($lock,10000))throw new RuntimeException('Delivery registry busy; update was not accepted.');
        try{$lanes=json_decode($this->ReadAttributeString('_DeliveryLanes'),true)?:[];$lanes[$key]=['folder'=>$folder,'lane'=>$lane];$this->WriteAttributeString('_DeliveryLanes',json_encode($lanes,JSON_THROW_ON_ERROR));}
        finally{IPS_SemaphoreLeave($lock);}
    }
    private function laneMeta(string $key): array {return json_decode($this->GetBuffer('RSM_'.$key),true)?:['normal'=>0,'due'=>0,'scheduled'=>0];}
    private function writeLaneMeta(string $key,array $meta): void {$this->SetBuffer('RSM_'.$key,json_encode($meta,JSON_THROW_ON_ERROR));}
    private function lanePending(string $folder,int $lane): bool {return ($this->laneMeta($this->laneKey($folder,$lane))['normal']??0)>0||$this->journal($folder,$lane)->pending();}
    private function AddToBuffer($localID,$Value=null,bool $unchanged=false)
    {
        if(IPS_GetKernelRunlevel()!==KR_READY||!$this->ReadPropertyBoolean('SyncEnabled')||!$this->LoadConfig())return;
        foreach($this->config['Index'][(int)$localID]??[] as $item){
            if(empty($item['Active'])&&empty($item['Delete']))continue;
            $history=!empty($item['FullHistory']);$immediate=!empty($item['Immediate'])||!empty($item['Delete']);
            if($unchanged&&$this->ReadPropertyBoolean('OnlyChangedValues')&&!$history&&!$immediate){$this->CountTraffic(['FilteredUpdates'=>1]);continue;}
            $p=$this->GetPayload((int)$localID,$item,$this->config['Roots'],$Value);if(!$p){if($history)throw new RuntimeException('Full History mapping unavailable.');continue;}
            $folder = $item['Folder'];
            $lane = ($localID % max(1, $this->ReadPropertyInteger('LaneCount'))) + 1;
            if ($history) {
                foreach ($this->lanes() as $previous) {
                    if ($previous['folder'] !== $folder || $previous['lane'] === $lane) continue;
                    if ($this->journal($folder, $previous['lane'])->containsStream($p['MappingID'], (int)$localID)) {
                        $lane = $previous['lane'];
                        break;
                    }
                }
            }
            $key = $this->laneKey($folder, $lane);
            $this->registerLane($folder,$lane);$p['Stream']=$this->ReadAttributeString('SourceIdentity').':'.$key;
            $lock='RS_Queue_'.$this->InstanceID.'_'.$key;
            if(!IPS_SemaphoreEnter($lock,$history?10000:1000)){
                if($history){$this->Log('Full History enqueue lock unavailable. Event not accepted; investigate immediately.',KL_ERROR);throw new RuntimeException('Full History enqueue failed.');}
                $this->CountTraffic(['DroppedUpdates'=>1]);continue;
            }
            try{
                $m=$this->laneMeta($key);$wasIdle=!$m['due'];
                if($history){$this->persistRoute($folder,$lane);$this->journal($folder,$lane)->append($p);}
                else{
                    $name='RSN_'.$key.'_'.$p['MappingID'].'_'.$localID;$old=$this->GetBuffer($name);
                    if($old===''&&$m['normal']>=max(1,$this->ReadPropertyInteger('MaxBufferSize'))){$this->CountTraffic(['DroppedUpdates'=>1]);continue;}
                    $this->SetBuffer($name,json_encode($p,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
                    if ($old === '') {
                        $m['normal']++;
                    } else {
                        $m['skipped'] = ($m['skipped'] ?? 0) + 1;
                        $this->CountTraffic(['CoalescedUpdates' => 1]);
                    }
                }
                $m['events']=($m['events']??0)+1;
                if(empty($m['starts']))$m['starts']=microtime(true);
                $this->CountTraffic(['CapturedUpdates'=>1,'FullHistoryCaptured'=>$history?1:0]);
                if(!$m['due']||$immediate)$m['due']=microtime(true)+($immediate?0:max(0,min(5000,$this->ReadPropertyInteger('BatchIntervalMs')))/1000);
                $this->writeLaneMeta($key,$m);
            }catch(Throwable $e){$this->CountTraffic(['CaptureFailures'=>1]);$this->Log('Capture failed: '.$e->getMessage(),KL_ERROR);throw $e;}
            finally{IPS_SemaphoreLeave($lock);}
            if($immediate||$this->ReadPropertyInteger('BatchIntervalMs')===0) $this->DispatchPendingBatches();
            elseif($wasIdle) $this->wakeDispatcher();
        }
    }
    public function ProcessSync()
    {
        if(!$this->ReadPropertyBoolean('SyncEnabled')||!$this->LoadConfig())return;
        foreach(array_keys($this->config['Index']) as $id)$this->AddToBuffer($id);
    }
    private function retryState(): array {return json_decode($this->ReadAttributeString('_DeliveryRetry'),true)?:[];}
    private function updateRetry(string $key,bool $failed): void {
        $lock='RS_Retry_'.$this->InstanceID;if(!IPS_SemaphoreEnter($lock,10000))throw new RuntimeException('Retry state unavailable.');
        try{$s=$this->retryState();if($failed){$n=min(6,($s[$key]['attempts']??0)+1);$s[$key]=['attempts'=>$n,'after'=>time()+min(60,5*2**($n-1))];}else unset($s[$key]);$this->WriteAttributeString('_DeliveryRetry',json_encode($s,JSON_THROW_ON_ERROR));}
        finally{IPS_SemaphoreLeave($lock);}
    }
    public function RetryFailedBatches(): void {$this->DispatchPendingBatches();}

    private function wakeDispatcher(): void
    {
        $lock = 'RS_Wake_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 10000)) throw new RuntimeException('Cannot wake dispatcher.');
        try { $this->SetTimerInterval('BatchDispatch', 250); }
        finally { IPS_SemaphoreLeave($lock); }
    }

    public function DispatchPendingBatches(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY || !$this->ReadPropertyBoolean('SyncEnabled')) return;
        $lock = 'RS_Wake_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 10000)) return;
        $jobs = [];
        try {
            $now = microtime(true);
            $wake = null;
            $retries = $this->retryState();
            foreach ($this->lanes() as $key => $lane) {
                $queue = 'RS_Queue_' . $this->InstanceID . '_' . $key;
                if (!IPS_SemaphoreEnter($queue, 0)) { $wake = min($wake ?? INF, $now + 0.25); continue; }
                try {
                    $meta = $this->laneMeta($key);
                    $history = $this->journal($lane['folder'], $lane['lane'])->summary();
                    if (!$meta['normal'] && !$history['count']) continue;
                    $due = $meta['normal'] ? 0 : $history['due'];
                    $due = max($due, $meta['due'], $meta['scheduled'], $retries[$key]['after'] ?? 0);
                    if ($due <= $now) {
                        $worker = 'RS_Lock_' . $this->InstanceID . '_' . $key;
                        if (IPS_SemaphoreEnter($worker, 0)) {
                            IPS_SemaphoreLeave($worker);
                            $jobs[] = $lane;
                            $due = $meta['scheduled'] = $now + 30;
                            $this->writeLaneMeta($key, $meta);
                        } else { $due = $now + 1; }
                    }
                    $wake = min($wake ?? INF, $due);
                } catch (Throwable $e) {
                    $this->Log('Cannot inspect delivery queue: ' . $e->getMessage(), KL_ERROR);
                    $wake = min($wake ?? INF, $now + 30);
                } finally { IPS_SemaphoreLeave($queue); }
            }
            $this->SetTimerInterval('BatchDispatch', $wake === null ? 0 : max(250, (int)ceil(($wake - $now) * 1000)));
        } finally { IPS_SemaphoreLeave($lock); }
        foreach ($jobs as $lane) {
            $script = 'RS_FlushBuffer(' . $this->InstanceID . ', ' . var_export($lane['folder'], true) . ', ' . (int)$lane['lane'] . ');';
            if (!IPS_RunScriptText($script)) {
                $key = $this->laneKey($lane['folder'], $lane['lane']);
                $this->updateRetry($key, true);
                $queue = 'RS_Queue_' . $this->InstanceID . '_' . $key;
                if (IPS_SemaphoreEnter($queue, 1000)) {
                    try {
                        $meta = $this->laneMeta($key);
                        $meta['scheduled'] = 0;
                        $this->writeLaneMeta($key, $meta);
                    } finally { IPS_SemaphoreLeave($queue); }
                }
                $this->wakeDispatcher();
            }
        }
    }

    private function lanes(): array
    {
        return json_decode($this->ReadAttributeString('_DeliveryLanes'), true, 64, JSON_THROW_ON_ERROR);
    }

    public function FlushBuffer(string $FolderName = '', int $LaneID = 1)
    {
        if ($FolderName === '' || !$this->ReadPropertyBoolean('SyncEnabled') || IPS_GetKernelRunlevel() !== KR_READY) return;
        $key = $this->laneKey($FolderName, $LaneID);
        $worker = 'RS_Lock_' . $this->InstanceID . '_' . $key;
        if (!IPS_SemaphoreEnter($worker, 0)) return;
        $normal = [];
        $start = microtime(true);
        try {
            $queue = 'RS_Queue_' . $this->InstanceID . '_' . $key;
            if (!IPS_SemaphoreEnter($queue, 10000)) throw new RuntimeException('Queue busy.');
            try {
                $meta = $this->laneMeta($key);
                if (($this->retryState()[$key]['after'] ?? 0) > time() || $meta['due'] > microtime(true)) return;
                $journal = $this->journal($FolderName, $LaneID);
                $history = $journal->peek(200);
                foreach ($this->GetBufferList() as $name) {
                    if (!str_starts_with($name, 'RSN_' . $key . '_')) continue;
                    $raw = $this->GetBuffer($name);
                    if ($raw === '') continue;
                    $normal[] = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    $this->SetBuffer($name, '');
                    if (count($normal) + count($history) >= 400) break;
                }
                $meta['normal'] = max(0, $meta['normal'] - count($normal));
                $meta['scheduled'] = $meta['due'] = 0;
                $skipped = $meta['skipped'] ?? 0;
                $meta['starts'] = $meta['events'] = $meta['skipped'] = 0;
                $this->writeLaneMeta($key, $meta);
            } finally { IPS_SemaphoreLeave($queue); }
            $target = $this->GetTargetConfig($FolderName);
            if ($history) {
                $route = $this->route($FolderName, $LaneID);
                if (!$route || $route['target'] != $target || $route['secretsID'] !== $this->ReadPropertyInteger('LocalPasswordModuleID')) {
                    throw new RuntimeException('Restore the saved target for this pending history lane.');
                }
            }
            $batch = array_merge(array_column($history, 'payload'), $normal);
            if (!$batch) return;
            if (!$target || !$this->InitConnectionForFolder($target)) throw new RuntimeException('Target connection unavailable.');
            $receiver = $this->receiverFor($FolderName, $target);
            $profiles = [];
            if ($this->ReadPropertyBoolean('ReplicateProfiles')) {
                foreach ($batch as $item) {
                    $name = $item['Profile'] ?? '';
                    if ($name !== '' && !isset($profiles[$name]) && IPS_VariableProfileExists($name)) $profiles[$name] = IPS_GetVariableProfile($name);
                }
            }
            $packet = json_encode(['Protocol' => 2, 'Source' => $this->ReadAttributeString('SourceIdentity'),
                'Batch' => $batch, 'Profiles' => $profiles, 'AutoCreate' => $this->ReadPropertyBoolean('AutoCreate')], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $reply = $this->rpcClient->RSR_Receive($receiver, $packet);
            $ack = is_string($reply) ? json_decode($reply, true, 64, JSON_THROW_ON_ERROR) : null;
            if (($ack['Protocol'] ?? 0) !== 2 || !is_array($ack['Accepted'] ?? null) || !is_array($ack['Errors'] ?? null)) throw new RuntimeException('Invalid delivery acknowledgement.');
            $offered = array_fill_keys(array_column($batch, 'EventID'), true);
            $accepted = [];
            foreach ($ack['Accepted'] as $id) {
                if (!is_string($id) || !isset($offered[$id]) || isset($accepted[$id])) throw new RuntimeException('Invalid item acknowledgement.');
                $accepted[$id] = true;
            }
            $normal = array_filter($normal, static fn($item) => !isset($accepted[$item['EventID']]));
            $journal->settle($history, array_keys($accepted));
            $failed = count($accepted) !== count($batch) || $ack['Errors'];
            $this->CountTraffic(['AcceptedUpdates' => count($accepted), 'DroppedUpdates' => count($normal),
                'SuccessfulBatches' => $failed ? 0 : 1, 'FailedBatches' => $failed ? 1 : 0]);
            $this->recordPerformance($FolderName, count($batch), strlen($packet), $start, $batch[0]['Timestamp'], $failed, $skipped);
            if ($failed) $this->Log('Destination ' . $FolderName . ' rejected items. ' . ($ack['Errors'][0]['message'] ?? 'Check receiver diagnostics.'), KL_WARNING);
            $this->updateRetry($key, false);
        } catch (Throwable $e) {
            $this->SetBuffer('Companion_' . hash('sha256', $FolderName), '');
            $this->CountTraffic(['FailedBatches' => 1, 'DroppedUpdates' => count($normal)]);
            $this->recordPerformance($FolderName, 0, 0, $start, $start, true);
            $this->Log($e->getMessage(), KL_ERROR);
            $this->updateRetry($key, true);
        } finally {
            IPS_SemaphoreLeave($worker);
            $this->DispatchPendingBatches();
        }
    }

    private function recordPerformance(string $folder, int $batch, int $bytes, float $start, float $captured, bool $failed, int $skipped = 0): void
    {
        $lock = 'RS_StateLock_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $perf = json_decode($this->ReadAttributeString('_PerfBuffer'), true) ?: [];
            $row = $perf[$folder] ?? array_fill_keys(['RTT','Batch','Size','Skipped','Lag','Errors','Count'], 0);
            $row['Errors'] += (int)$failed;
            $row['Skipped'] += $skipped;
            if ($batch) {
                $row['Count']++;
                $row['Batch'] += $batch;
                $row['Size'] += $bytes / 1024;
                $row['Lag'] += max(0, microtime(true) - $captured);
                $row['RTT'] += (microtime(true) - $start) * 1000;
            }
            $perf[$folder] = $row;
            $this->WriteAttributeString('_PerfBuffer', json_encode($perf, JSON_THROW_ON_ERROR));
        } finally { IPS_SemaphoreLeave($lock); }
    }

    public function GetDeliveryStatus(): string
    {
        $out = ['lanes' => [], 'retry' => $this->retryState()];
        foreach ($this->lanes() as $key => $lane) {
            $history = $this->journal($lane['folder'], $lane['lane'])->summary();
            $out['lanes'][] = ['folder' => $lane['folder'], 'lane' => $lane['lane'],
                'normalPending' => $this->laneMeta($key)['normal'], 'historyPending' => $history['count'], 'historyBytes' => $history['bytes']];
        }
        return json_encode($out, JSON_THROW_ON_ERROR);
    }

    public function GetSyncState(): string
    {
        $state = ['buffer' => [], 'events' => [], 'starts' => [], 'due' => [], 'scheduled' => [], 'retry' => []];
        $retry = $this->retryState();
        foreach ($this->lanes() as $key => $lane) {
            $folder = $lane['folder'];
            $id = $lane['lane'];
            $meta = $this->laneMeta($key);
            $items = [];
            foreach ($this->journal($folder, $id)->peek(PHP_INT_MAX, true) as $row) {
                $event = $row['payload'];
                $items[$event['LocalID'] . '_' . $event['EventID']] = $event;
            }
            foreach ($this->GetBufferList() as $name) {
                if (!str_starts_with($name, 'RSN_' . $key . '_')) continue;
                $value = $this->GetBuffer($name);
                if ($value === '') continue;
                $event = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
                $index = $event['LocalID'];
                if (isset($items[$index])) $index .= '_' . $event['MappingID'];
                $items[$index] = $event;
            }
            $state['buffer'][$folder][$id] = $items;
            if ($items) {
                $meta['events'] = max($meta['events'] ?? 0, count($items));
                if (empty($meta['starts'])) $meta['starts'] = min(array_column($items, 'Timestamp'));
            }
            foreach (['events','starts','due','scheduled'] as $field) $state[$field][$folder][$id] = $meta[$field] ?? 0;
            if (isset($retry[$key])) $state['retry'][$folder][$id] = $retry[$key];
        }
        return json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function hasPendingDelivery(): bool
    {
        foreach ($this->lanes() as $lane) if ($this->lanePending($lane['folder'], $lane['lane'])) return true;
        return false;
    }

    private function migrateDelivery(): void
    {
        $state = json_decode($this->ReadAttributeString('_SyncState'), true, 128, JSON_THROW_ON_ERROR);
        if (empty($state['buffer'])) return;
        foreach ($state['buffer'] as $folder => $lanes) foreach ($lanes as $lane => $items) {
            if (!$items || !$this->GetTargetConfig($folder)) continue;
            $this->registerLane($folder, (int)$lane);
            foreach ($items as $item) {
                if (!is_array($item) || empty($item['LocalID'])) continue;
                $item['MappingID'] = hash('sha256', $folder . '|' . $item['LocalSetID'] . '|' . $item['RemoteRootID']);
                $item['Source'] = $this->ReadAttributeString('SourceIdentity');
                $item['EventID'] = bin2hex(random_bytes(16));
                $this->persistRoute($folder, (int)$lane);
                $this->journal($folder, (int)$lane)->append($item);
            }
        }
        $this->WriteAttributeString('_SyncState', '{"buffer":{},"events":{},"starts":{}}');
    }
}
