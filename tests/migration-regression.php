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
    check(!empty($status['csrf']) && $status['version']==='1.14.1','Authenticated status supplies version and session token.');
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
        return $method === 'RSR_Configure' ? '2' : $reply;
    };
    event($m, 89);
    flushLane($m);
    $receiverID = IPS_GetInstanceListByModuleID(RECEIVER_GUID)[0];
    $receiverDebug = IPS_GetObjectIDByIdent('Debug', $receiverID);
    check(str_contains(GetValue($receiverDebug), 'saved the receiver binding and returned int (2)'), 'Receiver writes its setup outcome to its own Debug log.');
    check(str_contains(GetValue($senderDebug), 'receiver #' . $receiverID . ' returned string ("2"). Expected int (2).'), 'Sender records the actual reply type and safe protocol value.');
    check(count(rows($m)) === 1 && !mirrorID(), 'Diagnostic logging preserves the strict protocol check and retains pending history.');

    $GLOBALS['rpcHandler'] = static function ($method, $params) use ($peer) {
        $reply = $peer($method, $params);
        return $method === 'RSR_Configure' ? null : $reply;
    };
    $m->ProvisionTargets();
    check(str_contains(GetValue($senderDebug), 'returned null. Expected int (2).'), 'Null setup reply is distinguishable from a string.');
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
    connectPeer();
    forceDue($m);
    flushLane($m);
    check(!rows($m) && GetValue(mirrorID()) === 89, 'An integer protocol reply still completes delivery.');
    check(!array_filter($GLOBALS['locks']),'No leaked semaphore.');
    echo 'PASS migration, controls and administration: '.$GLOBALS['testCount']." assertions\n";
})();
