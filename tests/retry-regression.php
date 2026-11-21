<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

(static function () {

$m=fresh(true);connectPeer();$GLOBALS['secretFail']=true;
event($m,1);flushLane($m);
$retry=json_decode($m->attributes['_DeliveryRetry'],true);$key=array_key_first($retry);
check($retry[$key]['after']>=time()+4 && count(rows($m))===1,'Failed Secrets read retains history with a deadline.');
$reads=$GLOBALS['reads'];$jobs=count($GLOBALS['jobs']);
for($i=0;$i<30;$i++){flushLane($m);$m->DispatchPendingBatches();}
check($GLOBALS['reads']===$reads && count($GLOBALS['jobs'])===$jobs,'Cooldown suppresses repeated reads and workers.');
check($m->timers['BatchDispatch']>=3000,'Dispatcher sleeps through backoff.');
foreach([10,20,40,60,60] as $delay){
    $retry=json_decode($m->attributes['_DeliveryRetry'],true);$retry[$key]['after']=0;$m->attributes['_DeliveryRetry']=json_encode($retry);
    flushLane($m);$retry=json_decode($m->attributes['_DeliveryRetry'],true);
    check($retry[$key]['after']>=time()+$delay-1,'Bounded exponential backoff '.$delay);
}
$restarted=new RemoteSync();$restarted->attributes=$m->attributes;$restarted->properties=$m->properties;$restarted->Create();$restarted->ApplyChanges();
check(count(rows($restarted))===1 && $restarted->attributes['SourceIdentity']===$m->attributes['SourceIdentity'],'Restart recovers identity and queued event.');
$reads=$GLOBALS['reads'];flushLane($restarted);check($GLOBALS['reads']===$reads,'Restart honors cooldown.');
$GLOBALS['secretFail']=false;forceDue($restarted);flushLane($restarted);
check(!rows($restarted)&&GetValue(mirrorID())===1,'Recovered peer acknowledges history.');

$m=fresh(true);connectPeer();event($m,13);
connectPeer(static function($method,$args){if($method==='RSR_Receive'){RSR_Receive(...$args);return 'lost acknowledgement';}return null;});
flushLane($m);$mirror=mirrorID();$writes=$GLOBALS['valueWrites'][$mirror];
check(count(rows($m))===1,'Lost acknowledgement retains sender event.');
forceDue($m);connectPeer();flushLane($m);
check(!rows($m)&&$GLOBALS['valueWrites'][$mirror]===$writes,'Retry after lost ACK does not apply again.');
check(count(glob(IPS_GetKernelDir().'remotesync-received/*/history/*/*.json'))===1,'Receiver stores one history record per EventID.');

$m=fresh(true);connectPeer();obj(20002,2,10001);
$selection=json_decode($m->attributes['SyncListCache'],true);$other=$selection[0];$other['ObjectID']=20002;$selection[]=$other;
$m->attributes['SyncListCache']=json_encode($selection);$m->ApplyChanges();
$GLOBALS['rejectValue']=71;event($m,71);event($m,72);event($m,34,true,20002);flushLane($m);
check(count(rows($m))===2 && GetValue(IPS_GetObjectIDByIdent('Rem_20002',10002))===34,'Broken variable does not block its healthy lane neighbor.');
check($m->timers['BatchDispatch']>=20000,'Individual stream retries sleep until due.');
event($m,35,true,20002);flushLane($m);
check(GetValue(IPS_GetObjectIDByIdent('Rem_20002',10002))===35 && count(rows($m))===2,'Healthy traffic continues while another stream cools down.');

$m = fresh(true);
obj(10004, 0);
obj(10005, 0);
obj(20002, 2, 10005);
$roots = json_decode($m->properties['Roots'], true);
$roots[] = ['LocalRootID' => 10005, 'TargetFolder' => 'Berlin', 'RemoteRootID' => 10004];
$m->properties['Roots'] = json_encode($roots);
$selection = json_decode($m->attributes['SyncListCache'], true);
$selection[] = array_replace($selection[0], ['ObjectID' => 20002, 'LocalRootID' => 10005]);
$m->attributes['SyncListCache'] = json_encode($selection);
$m->ApplyChanges();
connectPeer(static function(string $method, array $args) {
    if ($method === 'RSR_Configure') {
        foreach (json_decode($args[2], true)['maps'] as $root) {
            if (!IPS_ObjectExists($root)) throw new RuntimeException('Older receiver rejects missing roots.');
        }
    }
    return null;
});
event($m, 14);
event($m, 25, true, 20002);
unset($GLOBALS['objects'][10002]);
flushLane($m);
$healthyMirror = IPS_GetObjectIDByIdent('Rem_20002', 10004);
$pending = array_values(rows($m));
check(count($pending) === 1 && $pending[0]['Value'] === 14 && GetValue($healthyMirror) === 25,
    'A missing destination root leaves only its history queued and allows another mapping through an older receiver.');
check(count(array_filter($GLOBALS['logs'], static fn(string $line): bool => str_contains($line, 'destination root #10002 is missing'))) === 1,
    'Missing destination root is logged by ID.');
event($m, 15);
event($m, 26, true, 20002);
flushLane($m);
check(count(rows($m)) === 2 && GetValue($healthyMirror) === 26, 'A paused destination root continues capturing its Full History.');

$makePendingStreamDue = static function(RemoteSync $sender): void {
    $event = array_values(rows($sender))[0];
    $cursorFile = glob(IPS_GetKernelDir() . 'remotesync/*/*/*/streams/' . $event['Stream'] . '/state.json')[0];
    $cursor = json_decode(file_get_contents($cursorFile), true);
    $cursor['after'] = 0;
    file_put_contents($cursorFile, json_encode($cursor));
};
$cacheKey = 'Companion_' . hash('sha256', 'Berlin');
$cache = json_decode($m->buffers[$cacheKey], true);
$cache['recheckAt'] = 1;
$m->buffers[$cacheKey] = json_encode($cache);
obj(10002, 0);
$makePendingStreamDue($m);
flushLane($m);
check(!rows($m) && GetValue(mirrorID()) === 15 && $GLOBALS['valueWrites'][mirrorID()] === 2,
    'A restored root resumes the original history stream in order.');

connectPeer();
event($m, 16);
event($m, 27, true, 20002);
unset($GLOBALS['objects'][10002]);
flushLane($m);
check(count(rows($m)) === 1 && GetValue($healthyMirror) === 27 && !isset($m->buffers[$cacheKey]),
    'A root deleted after setup is rejected independently and invalidates the cached roots.');

obj(10002, 0);
$savedSource = $GLOBALS['objects'][20001];
unset($GLOBALS['objects'][20001]);
$makePendingStreamDue($m);
$GLOBALS['values'][20002] = 28;
$m->ProcessSync();
flushLane($m);
check(count(rows($m)) === 1 && GetValue($healthyMirror) === 28,
    'A missing source variable leaves its queued history and allows another mapping through a sync pass.');
check((bool)array_filter($GLOBALS['logs'], static fn(string $line): bool => str_contains($line, 'source variable #20001 is missing')),
    'Missing source variable is logged by ID.');

$GLOBALS['objects'][20001] = $savedSource;
$savedRoot = $GLOBALS['objects'][10001];
unset($GLOBALS['objects'][10001]);
$makePendingStreamDue($m);
event($m, 29, true, 20002);
flushLane($m);
check(count(rows($m)) === 1 && GetValue($healthyMirror) === 29,
    'A missing source root leaves its queued history and allows another mapping to sync.');
check((bool)array_filter($GLOBALS['logs'], static fn(string $line): bool => str_contains($line, 'source root #10001 is missing')),
    'Missing source root is logged by ID.');
$GLOBALS['objects'][10001] = $savedRoot;
$makePendingStreamDue($m);
flushLane($m);
check(!rows($m) && GetValue(mirrorID()) === 16, 'Restoring the original source objects releases the queued event.');

$m->properties['AutoCreate'] = false;
$missingMirror = mirrorID();
$savedMirror = $GLOBALS['objects'][$missingMirror];
unset($GLOBALS['objects'][$missingMirror]);
event($m, 17);
event($m, 30, true, 20002);
flushLane($m);
check(count(rows($m)) === 1 && GetValue($healthyMirror) === 30,
    'With Auto-Create disabled, a deleted mirror pauses its stream and healthy delivery continues.');
check((bool)array_filter($GLOBALS['logs'], static fn(string $line): bool => str_contains($line, 'Destination mirror #' . $missingMirror . ' is missing')),
    'The previously confirmed mirror is logged by its ID.');
$GLOBALS['objects'][$missingMirror] = $savedMirror;
$makePendingStreamDue($m);
flushLane($m);
check(!rows($m) && GetValue($missingMirror) === 17, 'Restoring the mirror releases queued history without changing its object ID.');

$m=fresh(true);connectPeer();event($m,91);
$GLOBALS['duringRequest']=static function()use($m){event($m,92);};
flushLane($m);check(count(rows($m))===1 && array_values(rows($m))[0]['Value']===92,'New event survives in-flight send.');
flushLane($m);check(GetValue(mirrorID())===92,'Following value delivered.');
check(!glob(IPS_GetKernelDir().'remotesync/*/*/*/streams/*/[0-9]*.json'),'Acknowledged history files reclaimed.');

$m=fresh(true);connectPeer();$GLOBALS['dispatchFail']=true;event($m,2);
check((bool)json_decode($m->attributes['_DeliveryRetry'],true),'Rejected worker dispatch backs off.');
check(!array_filter($GLOBALS['locks']),'No leaked semaphore.');
echo 'PASS delivery: '.$GLOBALS['testCount']." assertions\n";
})();
