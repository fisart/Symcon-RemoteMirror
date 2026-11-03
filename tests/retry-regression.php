<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;$ok=static function($condition,$why)use(&$checks){if(!$condition)throw new RuntimeException($why);++$checks;};
$m=new RemoteSync();$m->Create();$m->properties['LocalPasswordModuleID']=49800;$m->properties['Targets']='[{"Name":"Peer","RemoteKey":"Connection","RemoteScriptRootID":12345}]';$m->properties['ReplicateProfiles']=false;$m->attributes['_RemoteIDCache']='{"Peer_RX":12345}';
$seed=static function(array $values)use($m){$m->attributes['_SyncState']=json_encode(['buffer'=>['Peer'=>[1=>$values]],'events'=>[],'starts'=>[]]);};
$state=static fn()=>json_decode($m->GetSyncState(),true);
$due=static function()use($m,$state){$s=$state();$s['retry']['Peer'][1]['after']=time()-1;$m->attributes['_SyncState']=json_encode($s);};
$payload=['LocalID'=>11111,'Value'=>'VALUE-CANARY'];$seed([11111=>$payload]);
$m->FlushBuffer('Peer',1);
$ok(count($GLOBALS['jobs'])===0,'Failed worker immediately relaunched the same batch.');
$ok($state()['buffer']['Peer'][1][11111]===$payload,'Failed read lost its queued payload.');
$ok($m->timers['FailedBatchRetry']===5000,'Failure did not arm bounded retry timer.');
$ok($state()['retry']['Peer'][1]['after']>=time()+4,'Retry has no initial delay.');
for($i=0;$i<100;$i++)$m->FlushBuffer('Peer',1);
$ok($GLOBALS['reads']===1&&count($GLOBALS['jobs'])===0,'Calls during cooldown retried credentials or spawned more workers.');
$m->RetryFailedBatches();$ok(count($GLOBALS['jobs'])===0,'Timer retried before the deadline.');
foreach([10,20,40,60,60]as$delay){$due();$m->RetryFailedBatches();$ok(count($GLOBALS['jobs'])===1,'Timer must dispatch exactly one due worker.');$GLOBALS['jobs']=[];$m->FlushBuffer('Peer',1);$ok($state()['retry']['Peer'][1]['after']>=time()+$delay-1,'Exponential retry delay did not grow/cap correctly.');}
// A rejected ScriptEngine dispatch must remain eligible for a later bounded retry.
$due();$GLOBALS['dispatchFail']=true;$m->RetryFailedBatches();$GLOBALS['dispatchFail']=false;
$ok($state()['retry']['Peer'][1]['after']>time()&&count($GLOBALS['jobs'])===0,'Failed dispatch stranded or spun the retry.');
// Fresh runtime instance retains cooldown and pending payload across restart.
$restarted=new RemoteSync();$restarted->attributes=$m->attributes;$restarted->Create();$restarted->properties=$m->properties;$restarted->FlushBuffer('Peer',1);
$ok($GLOBALS['reads']===6,'Restart bypassed saved cooldown.');
// Successful retry resumes the existing send path and clears its circuit state.
$GLOBALS['secret']='{"URL":"synthetic.invalid","User":"fixture","PW":"PASSWORD-CANARY"}';$due();$m->RetryFailedBatches();$GLOBALS['jobs']=[];$m->FlushBuffer('Peer',1);
$ok(empty($state()['buffer']['Peer'][1])&&empty($state()['retry'])&&$m->timers['FailedBatchRetry']===0,'Recovery did not drain the queued batch and clear retry state.');
// A newer update received during a failed network request wins without dropping other queued history.
$seed([11111=>$payload]);$GLOBALS['transportFail']=true;$GLOBALS['duringRequest']=static function()use($m,$state){$s=$state();$s['buffer']['Peer'][1][11111]=['LocalID'=>11111,'Value'=>'NEWER-CANARY'];$s['buffer']['Peer'][1]['11111_history']=['LocalID'=>11111,'Value'=>'HISTORY-CANARY'];$m->attributes['_SyncState']=json_encode($s);};
$m->FlushBuffer('Peer',1);
$ok($state()['buffer']['Peer'][1][11111]['Value']==='NEWER-CANARY'&&isset($state()['buffer']['Peer'][1]['11111_history']),'Recovery overwrote a newer queued value or dropped history.');
$ok(count($GLOBALS['jobs'])===0,'Network failure restarted the immediate retry loop.');
// One unavailable lane must not delay successful work on another lane.
$GLOBALS['transportFail']=false;$before=$state();$s=$before;$s['buffer']['Peer'][2]=[22222=>['LocalID'=>22222,'Value'=>'OTHER-LANE']];$m->attributes['_SyncState']=json_encode($s);$m->FlushBuffer('Peer',2);
$ok(empty($state()['buffer']['Peer'][2])&&$state()['retry']['Peer'][1]===$before['retry']['Peer'][1],'A failed lane blocked or reset another lane.');
// Missing remote receiver is also a retryable failure, never a successful drain.
$seed([11111=>$payload]);$m->attributes['_RemoteIDCache']='{}';$GLOBALS['rpcResult']=[];$m->FlushBuffer('Peer',1);
$ok(isset($state()['buffer']['Peer'][1][11111])&&!empty($state()['retry'])&&!$GLOBALS['jobs'],'Missing receiver discarded the batch or respawned immediately.');
$m->attributes['_RemoteIDCache']='{"Peer_RX":12345}';$GLOBALS['rpcResult']='OK';
// Successful chunking still yields remaining queued work promptly.
$GLOBALS['transportFail']=false;$items=[];for($i=1;$i<=401;$i++)$items[$i]=['LocalID'=>$i,'Value'=>$i];$seed($items);$m->FlushBuffer('Peer',1);
$ok(count($state()['buffer']['Peer'][1])===1&&count($GLOBALS['jobs'])===1,'Retry fix blocked successful multi-chunk progress.');
$GLOBALS['jobs']=[];$seed([]);$m->FlushBuffer('Peer',1);$ok(!$GLOBALS['jobs'],'An empty lane scheduled another worker.');
$ok(!array_filter($GLOBALS['locks']),'Worker leaked a semaphore.');
$ok(!str_contains(json_encode($GLOBALS['logs']),'CANARY'),'Failure logging disclosed payload/credential values.');
echo 'PASS '.$checks.' actual RemoteSync worker checks: credential/network failure, no immediate respawn, cooldown, capped backoff, dispatch failure, restart, recovery, newer values/history, successful chunking and lock cleanup'.PHP_EOL;
