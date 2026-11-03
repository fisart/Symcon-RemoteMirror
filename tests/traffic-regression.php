<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$checks = 0;
$ok = static function (bool $condition, string $why) use (&$checks): void {
    if (!$condition) throw new RuntimeException($why);
    ++$checks;
};
$fixture = static function (array $options = [], array $properties = []): RemoteSync {
    $GLOBALS['jobs'] = $GLOBALS['requests'] = $GLOBALS['locks'] = [];
    $GLOBALS['reads'] = 0;
    $GLOBALS['transportFail'] = $GLOBALS['dispatchFail'] = false;
    $GLOBALS['secret'] = '{"URL":"synthetic.invalid","User":"fixture","PW":"PASSWORD-CANARY"}';
    $m = new RemoteSync();
    $m->Create();
    $m->properties = $properties + [
        'LaneCount' => 1, 'LocalPasswordModuleID' => 49800, 'ReplicateProfiles' => false,
        'Targets' => '[{"Name":"Peer","RemoteKey":"Connection","RemoteScriptRootID":12345}]',
        'Roots' => '[{"TargetFolder":"Peer","LocalRootID":10001,"RemoteRootID":30001}]'
    ] + $m->properties;
    $m->attributes['SyncListCache'] = json_encode([$options + [
        'ObjectID' => 20001, 'Folder' => 'Peer', 'LocalRootID' => 10001,
        'Active' => true, 'Delete' => false, 'FullHistory' => false, 'Immediate' => false
    ]]);
    $m->attributes['_RemoteIDCache'] = '{"Peer_RX":12345}';
    return $m;
};
$state = static fn(RemoteSync $m): array => json_decode($m->GetSyncState(), true);
$stats = static fn(RemoteSync $m): array => json_decode($m->GetTrafficStatistics(), true);
$queue = static fn(RemoteSync $m): array => $state($m)['buffer']['Peer'][1] ?? [];
$event = static fn(RemoteSync $m, $value, bool $changed = true) => $m->MessageSink(time(), 20001, VM_UPDATE, [$value, $changed]);
$expire = static function (RemoteSync $m) use ($state): void {
    $s = $state($m);
    $s['due']['Peer'][1] = microtime(true) - 1;
    $m->attributes['_SyncState'] = json_encode($s);
};
$run = static function (RemoteSync $m): void {
    $GLOBALS['jobs'] = [];
    $m->FlushBuffer('Peer', 1);
};
$packet = static function (): array {
    $rpc = json_decode(end($GLOBALS['requests']), true);
    return json_decode($rpc['params'][1]['DATA'], true)['Batch'];
};

// 100 changed readings in one window retain the final state and one fixed deadline.
$m = $fixture();
$event($m, 0);
$first = $state($m)['due']['Peer'][1];
$timerWrites = $m->timerWrites['BatchDispatch'];
for ($i = 1; $i < 100; ++$i) $event($m, $i);
$ok(count($queue($m)) === 1 && $queue($m)[20001]['Value'] === 99, 'Burst did not coalesce to latest state');
$ok($state($m)['due']['Peer'][1] === $first, 'Continuous updates extended the batching deadline');
$ok($m->timerWrites['BatchDispatch'] === $timerWrites, 'Updates repeatedly rearmed the timer and could starve delivery');
$m->DispatchPendingBatches();
$ok(!$GLOBALS['jobs'] && !$GLOBALS['reads'], 'Batch sent or read credentials before the deadline');
$m->FlushBuffer('Peer', 1);
$ok(!$GLOBALS['reads'] && count($queue($m)) === 1, 'Direct worker call bypassed the window');
$expire($m); $m->DispatchPendingBatches();
for ($i = 0; $i < 100; ++$i) $m->DispatchPendingBatches();
$ok(count($GLOBALS['jobs']) === 1, 'Concurrent dispatcher calls enqueued duplicate workers');
$run($m);
$ok(count($GLOBALS['requests']) === 1 && $GLOBALS['reads'] === 1 && $packet()[0]['Value'] === 99, 'Burst was not delivered in one request with one secret read');
$ok($stats($m)['CoalescedUpdates'] === 99 && $stats($m)['SuccessfulBatches'] === 1, 'Burst counters incorrect');
$ok($stats($m)['RPCRequests'] === 1 && $stats($m)['SecretLookups'] === 1 && $stats($m)['RequestBodyBytes'] === strlen($GLOBALS['requests'][0]), 'Request/byte/read counters incorrect');
$ok($m->timers['BatchDispatch'] === 0 && $m->timers['FailedBatchRetry'] === 0, 'Idle timers remained enabled');
$before = $m->writes;
for ($i = 0; $i < 100; ++$i) $event($m, 99, false);
$m->DispatchPendingBatches();
$ok($before === $m->writes && !$queue($m) && count($GLOBALS['requests']) === 1, 'Unchanged values caused persistent writes or transmissions');
$ok($stats($m)['FilteredUpdates'] === 100, 'Filtered counter incorrect');
$ok(!str_contains($m->GetTrafficStatistics(), 'CANARY') && !str_contains($m->GetTrafficStatistics(), 'synthetic.invalid'), 'Traffic stats contain credentials or endpoint');
$m->ResetTrafficStatistics();
$ok($stats($m)['RPCRequests'] === 0 && $stats($m)['SecretLookups'] === 0 && $before === $m->writes, 'Counter reset changed durable state or retained counters');

// Missing metadata, a manual sync, or disabling filtering must still send a value.
$m = $fixture(); $m->MessageSink(time(), 20001, VM_UPDATE, [17]);
$ok(count($queue($m)) === 1, 'Missing change metadata discarded a value');
$m = $fixture(); $m->ProcessSync();
$ok($queue($m)[20001]['Value'] === 42, 'Manual sync did not include current value');
$m = $fixture([], ['OnlyChangedValues' => false]); $event($m, 7, false);
$ok(count($queue($m)) === 1, 'Filtering cannot be disabled');
$m = $fixture(); $m->MessageSink(time(), 20001, 99999, [7, true]);
$ok(!$queue($m), 'Non-variable message entered the queue');

// Full history retains identical updates and transitions within a single batch.
$m = $fixture(['FullHistory' => true]);
foreach ([0, 0, 1, 0] as $value) $event($m, $value, false);
$ok(count($queue($m)) === 4 && !$GLOBALS['jobs'], 'Full history coalesced, filtered or bypassed batching');
$expire($m); $m->DispatchPendingBatches(); $run($m);
$ok(array_column($packet(), 'Value') === [0, 0, 1, 0], 'History sequence or repeated events lost in transmission');

// Immediate preserves repeated event signals and skips the batching window.
$m = $fixture(['Immediate' => true]);
foreach ([0, 1, 0, 0] as $value) $event($m, $value, false);
$ok(count($GLOBALS['jobs']) === 1 && count($queue($m)) === 4, 'Urgent events were delayed, coalesced or spawned duplicate jobs');
$run($m);
$ok(array_column($packet(), 'Value') === [0, 1, 0, 0], 'Urgent pulse transitions lost');
$m = $fixture([], ['BatchIntervalMs' => 0]); $event($m, 1);
$ok(count($GLOBALS['jobs']) === 1, 'Zero batching interval is not immediate');
$m = $fixture(['Active' => false, 'Delete' => true]); $m->ProcessSync();
$ok(count($GLOBALS['jobs']) === 1 && $queue($m)[20001]['Delete'], 'Manual deletion was delayed or filtered');

// Dispatch rejection and lost accepted jobs recover without a spin loop.
$m = $fixture(); $event($m, 1); $expire($m); $GLOBALS['dispatchFail'] = true; $m->DispatchPendingBatches();
$ok(!$GLOBALS['jobs'] && $state($m)['retry']['Peer'][1]['after'] > time(), 'Failed dispatch did not schedule backoff');
$GLOBALS['dispatchFail'] = false; $event($m, 2); $m->DispatchPendingBatches();
$ok(!$GLOBALS['jobs'] && !$GLOBALS['reads'], 'New events bypassed dispatch-failure cooldown');
$s = $state($m); $s['retry']['Peer'][1]['after'] = time()-1; $m->attributes['_SyncState'] = json_encode($s);
$m->RetryFailedBatches(); $run($m);
$ok($packet()[0]['Value'] === 2 && !$queue($m), 'Failed-dispatch recovery lost latest state');
$m = $fixture(); $event($m, 1); $expire($m); $m->DispatchPendingBatches(); $GLOBALS['jobs'] = [];
$s = $state($m); $s['scheduled']['Peer'][1] = microtime(true)-1; $m->attributes['_SyncState'] = json_encode($s);
$m->DispatchPendingBatches();
$ok(count($GLOBALS['jobs']) === 1, 'Expired worker reservation stranded the queue');
$run($m);

// Restart restores pending work, including lanes from an older lane count.
$m = $fixture(); $event($m, 3); $expire($m);
$s = $state($m); $s['buffer']['Peer'][7] = $s['buffer']['Peer'][1]; $m->attributes['_SyncState'] = json_encode($s);
$restarted = new RemoteSync(); $restarted->attributes = $m->attributes; $restarted->Create(); $restarted->properties = $m->properties;
$restarted->DispatchPendingBatches();
$ok(count($GLOBALS['jobs']) === 2, 'Restart/changed lane count stranded existing lanes');

// A new ordinary update during transmission starts a new bounded window.
$m = $fixture(); $event($m, 1); $expire($m); $m->DispatchPendingBatches();
$GLOBALS['duringRequest'] = static function () use ($m, $event) { $event($m, 2); };
$run($m);
$ok(!$GLOBALS['jobs'] && count($queue($m)) === 1 && $m->timers['BatchDispatch'] > 0, 'In-flight update lost its next window or was stranded');
$expire($m); $m->DispatchPendingBatches(); $run($m);
$ok($packet()[0]['Value'] === 2 && $stats($m)['SecretLookups'] === 2, 'Next batch reused credentials or lost latest update');

// An urgent update during transmission is picked up promptly after the lane unlocks.
$m = $fixture(['Immediate' => true]); $event($m, 1);
$GLOBALS['duringRequest'] = static function () use ($m, $event) { $event($m, 0); };
$run($m);
$ok(count($GLOBALS['jobs']) === 1 && count($queue($m)) === 1, 'Urgent in-flight update did not schedule the next worker');
$run($m);
$ok($packet()[0]['Value'] === 0, 'Urgent in-flight transition lost');

// At capacity, allow replacement of existing state; report dropped history.
$m = $fixture([], ['MaxBufferSize' => 1]); $event($m, 1); $event($m, 2);
$ok(count($queue($m)) === 1 && $queue($m)[20001]['Value'] === 2 && $stats($m)['DroppedUpdates'] === 0, 'Full buffer rejected a latest-state replacement');
$m = $fixture(['FullHistory' => true], ['MaxBufferSize' => 1]); $event($m, 1); $event($m, 2);
$ok(count($queue($m)) === 1 && $stats($m)['DroppedUpdates'] === 1 && !array_filter($GLOBALS['locks']), 'History overflow not bounded/countable or leaked a lock');
$export = json_decode($m->ExportConfig(), true);
$ok($export['Properties']['BatchIntervalMs'] === 1000 && $export['Properties']['OnlyChangedValues'] === true && $export['Properties']['LaneCount'] === 1, 'Export omitted traffic settings');

// Fresh authorization on the next batch: removing access must prevent another RPC.
$m = $fixture(); $event($m, 1); $expire($m); $run($m);
$GLOBALS['secret'] = ''; $event($m, 2); $expire($m); $run($m);
$ok($stats($m)['SecretLookups'] === 2 && $stats($m)['RPCRequests'] === 1 && count($queue($m)) === 1, 'Revoked credentials were reused across batches');

// A failed history batch retains its complete sequence ahead of in-flight events.
$m = $fixture(['FullHistory' => true]);
foreach ([0, 1, 0] as $value) $event($m, $value);
$expire($m); $GLOBALS['transportFail'] = true;
$GLOBALS['duringRequest'] = static function () use ($m, $event) { $event($m, 7); };
$run($m);
$ok(array_column(array_values($queue($m)), 'Value') === [0, 1, 0, 7] && !$GLOBALS['jobs'], 'History recovery reordered/dropped transitions or immediately retried');
$GLOBALS['transportFail'] = false;
$s = $state($m); $s['retry']['Peer'][1]['after'] = time()-1; $m->attributes['_SyncState'] = json_encode($s); $expire($m);
$m->RetryFailedBatches(); $run($m);
$ok(array_column($packet(), 'Value') === [0, 1, 0, 7], 'Recovered history sequence was not transmitted intact');

// Deterministic synthetic burst: immediate unfiltered delivery versus the new defaults.
$m = $fixture([], ['OnlyChangedValues' => false, 'BatchIntervalMs' => 0]);
for ($i = 0; $i < 100; ++$i) { $event($m, $i); $run($m); }
for ($i = 0; $i < 100; ++$i) { $event($m, 99, false); $run($m); }
$baseline = $stats($m);
$m = $fixture();
for ($i = 0; $i < 100; ++$i) $event($m, $i);
for ($i = 0; $i < 100; ++$i) $event($m, 99, false);
$expire($m); $m->DispatchPendingBatches(); $run($m);
$batched = $stats($m);
$ok($baseline['RPCRequests'] === 200 && $baseline['SecretLookups'] === 200 && $batched['RPCRequests'] === 1 && $batched['SecretLookups'] === 1 && $batched['RequestBodyBytes'] < $baseline['RequestBodyBytes'], 'Burst workload did not reduce requests, bytes and secret lookups');
echo 'Synthetic burst (100 changes + 100 unchanged updates): requests/read lookups ' . $baseline['RPCRequests'] . ' -> ' . $batched['RPCRequests'] . '; JSON request bytes ' . $baseline['RequestBodyBytes'] . ' -> ' . $batched['RequestBodyBytes'] . PHP_EOL;
echo "PASS $checks traffic checks: batching, filtering, immediate/history order, counters, dispatch recovery, restart, in-flight updates, overflow and export" . PHP_EOL;
