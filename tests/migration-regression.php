<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

(static function () {
    $m=fresh(true);
    $rows=json_decode($m->attributes['SyncListCache'],true);
    $rows[0]['Immediate']=$rows[0]['Action']=true;
    $m->attributes['SyncListCache']=json_encode($rows);
    $before=$m->ExportConfig();
    obj(47001,3,10003,'RemoteSync Gateway','RS_Gateway');
    IPS_SetScriptContent(47001,file_get_contents(__DIR__.'/fixtures/1.13-gateway.txt'));
    obj(47002,0,10002,'Renamed destination room');
    obj(47003,2,47002,'Existing dashboard mirror','DashboardValue','RS_REF:local:20001');
    obj(47004,6,10003,'Existing visualisation link');
    $linkTarget=47003;
    $m->ApplyChanges();connectPeer();$m->ProvisionTargets();event($m,21);flushLane($m);
    check($m->ExportConfig()===$before,'Automatic provisioning preserves the native configuration.');
    check(IPS_GetObject(47003)['ObjectIdent']==='DashboardValue' && GetValue($linkTarget)===21,'Existing ID, custom ident and dashboard link survive migration.');
    check(IPS_GetParent(47003)===47002 && !mirrorID(),'Mirror in former path adopted without duplicate.');
    check(str_contains(IPS_GetScriptContent(47001),'RSR_ForwardAction') && IPS_GetVariable(47003)['VariableCustomAction']===47001,'Verified legacy action script reused in place.');
    $ids=array_keys(array_filter($GLOBALS['moduleIDs'],static fn($id)=>$id===RECEIVER_GUID));$rx=$GLOBALS['modules'][end($ids)];
    $id=$rx->InstanceID;$count=count(IPS_GetChildrenIDs(10003));$m->ApplyChanges();$m->ProvisionTargets();
    check(count(IPS_GetChildrenIDs(10003))===$count && IPS_InstanceExists($id),'Repeated provisioning reuses companion and gateway.');
    $calls=[];connectPeer(static function($method,$params)use(&$calls){$calls[]=$method;if($method==='RequestAction')return true;return null;});
    $rx->ForwardAction(47003,'22');check($calls===['RequestAction'] && GetValue(47003)===21,'Gateway uses RequestAction only and waits for reported state.');
    $GLOBALS['rpcError']=['code'=>-32603,'message'=>'No action handler'];$start=count($GLOBALS['requests']);
    throws(fn()=>$rx->ForwardAction(47003,'22'),'Missing action handler reports failure.');
    check(array_column(array_slice($GLOBALS['requests'],$start),'method')===['RequestAction'] && GetValue(47003)===21,'No direct-write fallback or optimistic display.');
    unset($GLOBALS['rpcError']);connectPeer();
    $GLOBALS['throwAfterSet']=47003;event($m,37);flushLane($m);
    $writes=$GLOBALS['valueWrites'][47003];check(count(rows($m))===1 && GetValue(47003)===37,'Interruption after applying a value retains unacknowledged event.');
    foreach(glob(IPS_GetKernelDir().'remotesync/*/*/*/streams/*/state.json') as $path){
        $s=json_decode(file_get_contents($path),true);$s['after']=0;RemoteSyncJournalV2::durableWrite($path,json_encode($s));
    }
    forceDue($m);flushLane($m);
    check(!rows($m) && $GLOBALS['valueWrites'][47003]===$writes,'Restart reconciliation commits receipt without a second SetValue.');
    check(count(glob(IPS_GetKernelDir().'remotesync-received/*/history/*/*.json'))===2,'Repeated transport attempts retain two distinct accepted source events.');

    $m=fresh();connectPeer();obj(48001,2,10002,'Unrelated object','Rem_20001','Unrelated');
    event($m,5);flushLane($m);
    check(GetValue(48001)==='' && stats($m)['FailedBatches']===1,'Foreign ident collision preserves the other object.');
    $m=fresh();connectPeer();obj(48001,2,10002,'A','a','RS_REF:local:20001');obj(48002,2,10002,'B','b','RS_REF:local:20001');
    event($m,5);flushLane($m);check(GetValue(48001)==='' && GetValue(48002)==='' && !mirrorID(),'Ambiguous legacy identity refuses duplicate creation.');

    $m = fresh(true);
    obj(21001, 0, 10001, 'Utility Room Door');
    obj(20001, 2, 21001, 'State');
    obj(20002, 2, 10001, 'Other sensor');
    $selection = json_decode($m->attributes['SyncListCache'], true);
    $selection[] = array_replace($selection[0], ['ObjectID' => 20002]);
    $m->attributes['SyncListCache'] = json_encode($selection);
    $m->ApplyChanges();
    connectPeer();
    obj(48001, 0, 10002, 'Utility Room');
    obj(48002, 0, 10002, 'Utility Room Door');
    obj(48003, 2, 48001, 'State', 'Rem_20001', 'RS_REF:local:20001');
    obj(48004, 2, 48002, 'State', 'DoorState', 'RS_REF:local:20001');
    obj(48005, 2, 48002, 'Old State', 'Rem_20001', 'RS_REF:local:20001');
    obj(48006, 6, 10003, 'Old dashboard link');
    obj(48007, 6, 10003, 'Current dashboard link');
    $GLOBALS['objects'][48006]['TargetID'] = 48003;
    $GLOBALS['objects'][48007]['TargetID'] = 48004;
    event($m, 61);
    flushLane($m);
    check(GetValue(48004) === 61 && GetValue(48003) === '' && GetValue(48005) === '' && !rows($m),
        'The full relative path, including the leaf name, selects exactly one existing mirror.');
    check(IPS_GetParent(48004) === 48002 && IPS_GetObject(48004)['ObjectIdent'] === 'DoorState'
        && $GLOBALS['objects'][48006]['TargetID'] === 48003 && $GLOBALS['objects'][48007]['TargetID'] === 48004
        && IPS_GetChildrenIDs(48002) === [48004, 48005],
        'Duplicate routing preserves the existing objects, idents, parents and link targets.');

    IPS_SetName(21001, 'Utility Room');
    event($m, 62);
    flushLane($m);
    check(GetValue(48003) === 62 && GetValue(48004) === 61
        && IPS_GetParent(48003) === 48001 && !rows($m),
        'A previously cached mirror ID gives way to a unique exact match when the source path changes.');

    obj(48008, 2, 48001, 'State', 'SecondState', 'RS_REF:local:20001');
    ob_start();
    $m->InstallRemoteScripts('Berlin');
    ob_end_clean();
    event($m, 63);
    event($m, 64, true, 20002);
    flushLane($m);
    check(GetValue(48003) === 62 && GetValue(48008) === ''
        && GetValue(IPS_GetObjectIDByIdent('Rem_20002', 10002)) === 64,
        'Two exact paths reject only the ambiguous variable and a healthy variable in the same batch updates.');
    $pending = array_values(rows($m));
    check(count($pending) === 1 && $pending[0]['LocalID'] === 20001 && $pending[0]['Value'] === 63,
        'An unresolved duplicate keeps only its own event pending in Full History.');

    $m = fresh();
    connectPeer();
    obj(48001, 2, 10002, 'Object 20001', 'CurrentState', 'RS_REF:local:20001');
    obj(48002, 2, 10002, 'Old State', 'Rem_20001', 'RS_REF:local:20001');
    $selection = json_decode($m->attributes['SyncListCache'], true);
    $selection[0]['Active'] = false;
    $selection[0]['Delete'] = true;
    $m->attributes['SyncListCache'] = json_encode($selection);
    $m->ApplyChanges();
    event($m, 65);
    flushLane($m);
    check(IPS_ObjectExists(48001) && IPS_ObjectExists(48002) && stats($m)['FailedBatches'] === 1,
        'An exact match among duplicates never selects a mirror for deletion.');

    $m = fresh(true);
    $m->properties['AutoCreate'] = true;
    obj(21001, 0, 10001, 'Utility Room');
    obj(21002, 0, 10001, 'Landing');
    obj(20001, 2, 21001, 'State');
    $m->ApplyChanges();
    connectPeer();
    event($m, 71);
    $earlyPacket = array_values(rows($m))[0];
    flushLane($m);
    $oldRoom = IPS_GetChildrenIDs(10002)[0];
    $mirror = IPS_GetObjectIDByIdent('Rem_20001', $oldRoom);
    obj(48001, 6, 10003, 'Dashboard link');
    $GLOBALS['objects'][48001]['TargetID'] = $mirror;
    IPS_SetName(20001, 'Door State');
    event($m, 72);
    flushLane($m);
    check(IPS_GetName($mirror) === 'Door State' && IPS_GetParent($mirror) === $oldRoom && GetValue($mirror) === 72,
        'An aligned mirror follows a source rename in place.');

    obj(48002, 0, 10002, 'Landing');
    $GLOBALS['failParentChange'] = [$mirror, 48002];
    IPS_SetName(20001, 'Contact State');
    IPS_SetParent(20001, 21002);
    event($m, 73);
    flushLane($m);
    check(IPS_GetName($mirror) === 'Door State' && IPS_GetParent($mirror) === $oldRoom && GetValue($mirror) === 73 && !rows($m),
        'A failed second layout operation rolls back the first and still delivers the value.');

    // Simulate a receiver process stopping after the first step of the saved move.
    IPS_SetName($mirror, 'Contact State');
    $receiverID = IPS_GetInstanceListByModuleID(RECEIVER_GUID)[0];
    $beforeReload = $GLOBALS['modules'][$receiverID];
    $receiver = new RemoteSyncReceiver();
    $receiver->InstanceID = $receiverID;
    $receiver->attributes = $beforeReload->attributes;
    $receiver->properties = $beforeReload->properties;
    $receiver->Create();
    event($m, 74);
    flushLane($m);
    check(IPS_GetParent($mirror) === 48002 && IPS_GetName($mirror) === 'Contact State'
        && GetValue($mirror) === 74 && $GLOBALS['objects'][48001]['TargetID'] === $mirror
        && !IPS_GetChildrenIDs($oldRoom), 'After a receiver restart, a pending layout move completes on the same linked object.');

    $earlyPacket['EventID'] = bin2hex(random_bytes(16));
    $earlyPacket['Reliable'] = false;
    $earlyPacket['Timestamp'] -= 1;
    $receiver->Receive(json_encode(['Protocol' => 2, 'Source' => $earlyPacket['Source'], 'Batch' => [$earlyPacket], 'AutoCreate' => true]));
    check(IPS_GetParent($mirror) === 48002 && IPS_GetName($mirror) === 'Contact State',
        'An older packet cannot undo a newer recorded layout.');

    obj(48003, 2, 48002, 'Occupied');
    IPS_SetName(20001, 'Occupied');
    event($m, 75);
    flushLane($m);
    check(GetValue($mirror) === 75 && IPS_GetName($mirror) === 'Contact State' && GetValue(48003) === '',
        'A destination collision preserves the existing layout and delivers the value to its owner.');

    obj(21003, 0, 10001, 'Entry');
    IPS_SetParent(20001, 21003);
    IPS_SetName(20001, 'Entry contact');
    event($m, 76);
    flushLane($m);
    check(IPS_GetName(IPS_GetParent($mirror)) === 'Entry' && IPS_GetName($mirror) === 'Entry contact'
        && GetValue($mirror) === 76 && $GLOBALS['objects'][48001]['TargetID'] === $mirror,
        'Auto-Create supplies the new destination folder and moves the linked mirror without allocating a new variable.');

    IPS_SetName($mirror, 'Berlin custom name');
    IPS_SetName(20001, 'Source renamed again');
    event($m, 77);
    flushLane($m);
    check(IPS_GetName($mirror) === 'Berlin custom name' && GetValue($mirror) === 77,
        'An operator-customized receiver layout stays in place and keeps syncing values.');

    $m=fresh();connectPeer();obj(48001,3,10003,'RemoteSync Gateway','RS_Gateway');IPS_SetScriptContent(48001,'custom site script');
    $m->ProvisionTargets();event($m,44);flushLane($m);
    check(IPS_GetScriptContent(48001)==='custom site script' && GetValue(mirrorID())===44,'Custom gateway preserved; companion uses its own adapter.');

    $m=fresh(true);connectPeer();$export=$m->ExportConfig();
    check(json_decode($m->ValidateConfigurationBackup($export),true)['valid'],'Configuration validates.');
    $backup=json_decode($m->ExportConfigurationBackup(),true);
    check(isset($backup['sourceHash'], $backup['payload']['schema']),'Backup envelope available.');
    $bad=json_decode($export,true);$bad['Properties']['Unexpected']=true;
    check(!$m->ImportConfig(json_encode($bad))['status'] && $export===$m->ExportConfig(),'Invalid import leaves configuration intact.');
    event($m,76);$m->SetSynchronization(false);
    check(!$m->ImportConfig($export)['status'] && count(rows($m))===1,'Import preserves pending history.');
    $m->SetSynchronization(true);forceDue($m);flushLane($m);$m->SetSynchronization(false);
    check($m->ImportConfig($export)['status'] && !$m->properties['SyncEnabled'],'Validated import preserves paused destination.');
    $m->properties['DebugMode']=true;$m->Log('test log');$debug=IPS_GetObjectIDByIdent('Debug',321);
    check(str_contains(GetValue($debug),'test log'),'Debug variable captures diagnostic.');
    $m->ClearDebug();check(GetValue($debug)==='','Debug variable clears.');
    throws(fn()=>RemoteSyncConnectionV2::credentials('{"URL":"http://remote.invalid","User":"u","PW":"p"}'),'Plain HTTP rejected.');
    check($GLOBALS['curlOptions'][CURLOPT_SSL_VERIFYPEER]===true && $GLOBALS['curlOptions'][CURLOPT_SSL_VERIFYHOST]===2,'Peer and hostname verification enabled.');
    $vault=new IPSModule();$vault->InstanceID=42;$vault->Create();$vault->properties=['PortalEnabled'=>true,'PortalOrigin'=>'https://fixture.invalid'];
    $GLOBALS['moduleIDs'][42]=RemoteSyncSecrets::OLD;$m->properties['WebEnabled']=true;
    $_SERVER['HTTP_HOST']='fixture.invalid';$_SERVER['REQUEST_METHOD']='GET';$_GET=[];$GLOBALS['signedIn']=false;
    ob_start();$m->ProcessHookData();$login=ob_get_clean();
    check(http_response_code()===401 && str_contains($login,'Sign in'),'Web administration requires existing Secrets sign-in.');
    $GLOBALS['signedIn']=true;$_COOKIE['SEC_PORTAL_V2_42']='fixture-session';$_GET['action']='status';
    ob_start();$m->ProcessHookData();$response=ob_get_clean();$status=json_decode($response,true);
    if (!is_array($status)) throw new RuntimeException('Invalid administration status response.');
    check(!empty($status['csrf']) && $status['version']==='1.14.7','Authenticated status supplies version and session token.');
    $_SERVER['HTTP_ORIGIN']='https://fixture.invalid';
    invoke($m,'verifyWebMutation',42,['csrf'=>$status['csrf']]);check(true,'Session-bound mutation token accepted.');
    throws(fn()=>invoke($m,'verifyWebMutation',42,['csrf'=>'invalid']),'Invalid token rejected.');
    check(!invoke($m,'importNative',$m->ExportConfig(),'old-revision')['status'],'Stale web save rejected.');

    $m = fresh(true);
    $m->properties['DebugMode'] = true;
    $m->ApplyChanges();
    $senderDebug = IPS_GetObjectIDByIdent('Debug', $m->InstanceID);
    check(str_contains(GetValue($senderDebug), 'Sender configuration applied.'), 'Enabled sender Debug log records configuration application.');
    connectPeer();
    $peer = $GLOBALS['rpcHandler'];
    $GLOBALS['rpcHandler'] = static function ($method, $params) use ($peer) {
        $reply = $peer($method, $params);
        return $method === 'RSR_Configure' ? null : $reply;
    };
    event($m, 89);
    flushLane($m);
    $receiverID = IPS_GetInstanceListByModuleID(RECEIVER_GUID)[0];
    $receiverDebug = IPS_GetObjectIDByIdent('Debug', $receiverID);
    check(str_contains(GetValue($receiverDebug), 'saved the receiver binding and returned int (2)'), 'Receiver writes its setup outcome to its own Debug log.');
    check(str_contains(GetValue($senderDebug), 'receiver #' . $receiverID . ' returned null.'), 'Sender records a null reply and the receiver ID.');
    check(count(rows($m)) === 1 && !mirrorID(), 'A null reply retains pending history.');
    $testReply = 'private reply data';
    $GLOBALS['rpcHandler'] = static function ($method, $params) use ($peer, $testReply) {
        $reply = $peer($method, $params);
        return $method === 'RSR_Configure' ? $testReply : $reply;
    };
    $m->ProvisionTargets();
    check(str_contains(GetValue($senderDebug), 'returned string (18 bytes)') && !str_contains(GetValue($senderDebug), $testReply), 'Unexpected response text is not copied to diagnostics.');
    $rx = $GLOBALS['modules'][$receiverID];
    throws(fn() => $rx->Configure('', '{}'), 'Configure still rejects invalid binding data.');
    check(str_contains(GetValue($receiverDebug), 'failed while validating the sender and roots'), 'Receiver records the failing setup stage.');

    foreach ([true, 3, '02', '2.0', '2 '] as $invalidReply) {
        $GLOBALS['rpcHandler'] = static function ($method, $params) use ($peer, $invalidReply) {
            $reply = $peer($method, $params);
            return $method === 'RSR_Configure' ? $invalidReply : $reply;
        };
        forceDue($m);
        flushLane($m);
        check(count(rows($m)) === 1 && !mirrorID(), 'Unsupported protocol replies retain pending history.');
    }

    $GLOBALS['rpcHandler'] = static function ($method, $params) use ($peer) {
        $reply = $peer($method, $params);
        return $method === 'RSR_Configure' ? '2' : $reply;
    };
    forceDue($m);
    flushLane($m);
    $mirror = mirrorID();
    check($mirror && !rows($m) && GetValue($mirror) === 89, 'Symcon string protocol reply enables delivery and acknowledges pending history.');
    check(str_contains(GetValue($senderDebug), 'receiver #' . $receiverID . ' returned string ("2").'), 'Accepted string reply keeps its original type in the diagnostic.');

    connectPeer();
    ob_start();
    $m->InstallRemoteScripts('Berlin');
    $result = ob_get_clean();
    event($m, 90);
    forceDue($m);
    flushLane($m);
    check(str_starts_with($result, 'Success:') && !rows($m) && mirrorID() === $mirror && GetValue($mirror) === 90, 'An integer protocol reply still delivers to the same mirror.');

    $rx->ApplyChanges();
    $waiting = 'Receiver ready. Configured mapping count is 1. Waiting for a batch.';
    check(str_contains(GetValue($receiverDebug), $waiting) && in_array($waiting, $GLOBALS['logs'], true), 'Reload records that the configured receiver is waiting in both logs.');

    event($m, 91);
    forceDue($m);
    flushLane($m);
    $finished = 'RSR_Receive finished. Items 1, accepted 1, rejected 0, already recorded 0. Source -> mirror IDs: 20001 -> ' . $mirror . '.';
    check(str_contains(GetValue($receiverDebug), $finished) && in_array($finished, $GLOBALS['logs'], true), 'First batch after the reload logs the same mirror ID to both sinks.');
    check(!rows($m) && GetValue($mirror) === 91, 'First batch after the reload still updates the original mirror.');

    $quietLog = GetValue($receiverDebug);
    event($m, 92);
    forceDue($m);
    flushLane($m);
    check(GetValue($receiverDebug) === $quietLog && GetValue($mirror) === 92, 'Later successful batches deliver quietly by default.');

    $rx->properties['DebugMode'] = true;
    event($m, 93);
    forceDue($m);
    flushLane($m);
    check(substr_count(GetValue($receiverDebug), $finished) > substr_count($quietLog, $finished), 'Verbose tracing records each completed batch.');

    $GLOBALS['rejectValue'] = 94;
    event($m, 94);
    forceDue($m);
    flushLane($m);
    $rejected = 'RSR_Receive rejected source variable #20001 for remote root #10002 while writing the mirror value (RuntimeException).';
    check(str_contains(GetValue($receiverDebug), $rejected) && in_array($rejected, $GLOBALS['logs'], true), 'Both logs record the rejected item and failing step.');
    check(count(rows($m)) === 1 && GetValue($mirror) === 93, 'Rejected history remains queued.');
    unset($GLOBALS['rejectValue']);

    $opaque = 'synthetic private payload for log verification';
    throws(fn() => $rx->Receive($opaque), 'Invalid packet still fails.');
    $badPacket = 'RSR_Receive failed while decoding the batch (JsonException).';
    check(str_contains(GetValue($receiverDebug), $badPacket) && in_array($badPacket, $GLOBALS['logs'], true), 'Both logs record the stage for rejected packets.');
    check(!str_contains(GetValue($receiverDebug), $opaque) && !str_contains(implode("\n", $GLOBALS['logs']), $opaque), 'Receiver diagnostics omit packet contents.');
    check(!array_filter($GLOBALS['locks']),'No leaked semaphore.');
    echo 'PASS migration, controls and administration: '.$GLOBALS['testCount']." assertions\n";
})();
