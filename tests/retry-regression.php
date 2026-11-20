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
