<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

(static function () {

$m=fresh();connectPeer();$m->properties['BatchIntervalMs']=1000;
event($m,0);$deadline=json_decode($m->GetSyncState(),true)['due']['Berlin'][1];$writes=$m->timerWrites['BatchDispatch'];
for($i=1;$i<100;$i++)event($m,$i);
check(count(rows($m))===1 && rows($m)[20001]['Value']===99,'Coalesce ordinary updates by mapping and variable.');
check(json_decode($m->GetSyncState(),true)['due']['Berlin'][1]===$deadline && $m->timerWrites['BatchDispatch']===$writes,'First deadline is stable.');
flushLane($m);check(!$GLOBALS['requests'],'Batch window prevents early network activity.');
forceDue($m);flushLane($m);check(GetValue(mirrorID())===99 && stats($m)['CoalescedUpdates']===99,'Latest value arrives with correct counters.');
$requests=count($GLOBALS['requests']);$reads=$GLOBALS['reads'];
for($i=0;$i<100;$i++)event($m,99,false);
check(!$m->timers['BatchDispatch'] && $GLOBALS['reads']===$reads && count($GLOBALS['requests'])===$requests && stats($m)['FilteredUpdates']===100,'Idle and unchanged events produce no traffic.');
$m->ResetTrafficStatistics();check(stats($m)['SecretLookups']===0,'Independent counter reset.');
$m->MessageSink(0,20001,VM_UPDATE,[5]);check(rows($m)[20001]['Value']===5,'Missing change metadata still captures.');
$m->ProcessSync();check(rows($m)[20001]['Value']===42,'Manual sync captures a fresh snapshot.');
$m->MessageSink(0,20001,99999,[8,true]);check(rows($m)[20001]['Value']===42,'Ignore unrelated kernel messages.');
check(!str_contains($m->GetTrafficStatistics(),'test-password'),'Counters exclude credentials.');

$m=fresh();connectPeer();$m->properties['BatchIntervalMs']=5000;
$rows=json_decode($m->attributes['SyncListCache'],true);$rows[0]['Immediate']=true;$m->attributes['SyncListCache']=json_encode($rows);$m->ApplyChanges();$GLOBALS['jobs']=[];
event($m,7,false);event($m,8,false);check(count($GLOBALS['jobs'])===1 && count(rows($m))===1,'Immediate dispatches now and coalesces ordinary values.');
flushLane($m);check(GetValue(mirrorID())===8,'Immediate latest value arrives.');
$m->properties['OnlyChangedValues']=false;$m->ApplyChanges();event($m,8,false);check(count(rows($m))===1,'Disabling the filter captures equal values.');
$GLOBALS['secretFail']=true;flushLane($m);check(!rows($m) && stats($m)['DroppedUpdates']===1,'Failed ordinary updates are best effort.');

$m=fresh(true);connectPeer();$m->properties['MaxBufferSize']=1;$m->properties['BatchIntervalMs']=5000;
foreach([0,0,1,0] as $v)event($m,$v,false);
check(array_column(rows($m),'Value')===[0,0,1,0],'Full History retains transitions and repeated values beyond ordinary limit.');
forceDue($m);flushLane($m);$records=glob(IPS_GetKernelDir().'remotesync-received/*/history/*/*.json');
check(count($records)===4,'Receiver retains all accepted values.');
$events=array_map(static fn($path)=>json_decode(file_get_contents($path),true),$records);usort($events,static fn($a,$b)=>$a['Sequence']<=>$b['Sequence']);
check(array_column($events,'Value')===[0,0,1,0] && isset($events[0]['Timestamp']),'Receiver history preserves order and source time.');
$legacy=json_decode($m->GetSyncState(),true);check(isset($legacy['buffer'],$legacy['starts'],$legacy['events'],$legacy['due'],$legacy['scheduled'],$legacy['retry']),'1.13 monitoring fields available.');
check(isset(json_decode($m->GetDeliveryStatus(),true)['lanes'][0]['historyBytes']),'Separate detailed status.');
check(!array_filter($GLOBALS['locks']),'No leaked semaphore.');
echo 'PASS traffic: '.$GLOBALS['testCount']." assertions\n";
})();
