<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$engine=getenv('REMOTESYNC_BACKUP_ENGINE')?:__DIR__.'/reference/central-backup/Engine.php';
if(!is_file($engine))throw new RuntimeException('Set REMOTESYNC_BACKUP_ENGINE to the current central ConfigurationBackup/Engine.php.');
require_once $engine;
function IPS_GetModule($id){return ['Prefix'=>'RS','ModuleName'=>'RemoteSync'];}
function RS_ExportConfigurationBackup($id):string{return $GLOBALS['modules'][$id]->ExportConfigurationBackup();}
function RS_ValidateConfigurationBackup($id,$json):string{
    $reply=json_decode($GLOBALS['modules'][$id]->ValidateConfigurationBackup($json),true,64,JSON_THROW_ON_ERROR);
    if($GLOBALS['wrongHash']??false)$reply['payloadHash']=str_repeat('0',64);
    return json_encode($reply,JSON_THROW_ON_ERROR);
}
function captured(RemoteSync $m):array{
    return MCBBackupProtocol::capture($m->InstanceID,'{C6D3D6A3-0442-4217-9150-70588661C02D}',$m->properties,$m->attributes);
}
function directoryIdentity(string $path):array{
    $out=[];
    if(is_dir($path))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS))as$file)
        if($file->isFile())$out[$file->getPathname()]=hash_file('sha256',$file->getPathname());
    ksort($out);return $out;
}
$m=fresh(true);
$m->properties['LocalServerKey']='café/庭/123';
$before=serialize([$m->properties,$m->attributes,$m->timers,$m->buffers,$m->writes,$m->timerWrites,$GLOBALS['objects'],$GLOBALS['requests'],$GLOBALS['reads']]);
$files=directoryIdentity(IPS_GetKernelDir());
$native=$m->ExportConfig();
$capture=captured($m);
check(($capture['validation']['valid']??false)===true,'Central backup engine accepts the actual RemoteSync validator.');
check($capture['validation']['scope']==='format','Validation scope uses the protocol literal.');
check(MCBEngine::canonical($capture['envelope']['payload'])===MCBEngine::canonical(json_decode($native,true)),'Backup payload equals ordinary native export.');
$guid='{C6D3D6A3-0442-4217-9150-70588661C02D}';
$settings=['objects'=>['ID321'=>['data'=>['moduleID'=>$guid,'configuration'=>$m->properties,'attributes'=>$m->attributes]]]];
$snapshot=MCBEngine::snapshot($settings,[$guid=>['name'=>'RemoteSync']],[321],'fixture',time(),[],[],[321=>$capture]);
check($snapshot['warnings']===[]&&$snapshot['instances'][321]['coverage']==='native-format-validated','Central snapshot reports validated native coverage without warnings.');
check(serialize([$m->properties,$m->attributes,$m->timers,$m->buffers,$m->writes,$m->timerWrites,$GLOBALS['objects'],$GLOBALS['requests'],$GLOBALS['reads']])===$before&&directoryIdentity(IPS_GetKernelDir())===$files,'Export and validation do not mutate runtime, files, timers, devices or Secrets.');
check(!str_contains(json_encode($capture),'test-password'),'Excluded credentials are absent.');
$different=$m->properties;$different['DebugMode']=!$different['DebugMode'];
throws(fn()=>MCBBackupProtocol::capture(321,$guid,$different,$m->attributes),'A genuinely different saved property must fail source binding.');
$different=$m->attributes;$different['SyncListCache']='[]';
throws(fn()=>MCBBackupProtocol::capture(321,$guid,$m->properties,$different),'A genuinely different saved selection must fail source binding.');
$GLOBALS['wrongHash']=true;
check(captured($m)['validation']['valid']===false,'The central engine still rejects a wrong validator payload hash.');
unset($GLOBALS['wrongHash']);
$bad=json_decode($native,true);unset($bad['Properties']['Targets']);
$v=MCBBackupProtocol::validate(['id'=>321,'validate'=>'RS_ValidateConfigurationBackup'],$bad);
check(!$v['valid']&&$v['issues'],'Invalid native settings retain a specific format rejection.');
$envelope=$capture['envelope'];$envelope['moduleID']='{WRONG}';
throws(fn()=>MCBBackupProtocol::envelope($envelope,$guid),'Wrong module IDs stay rejected.');
$vectors=[4.0,4.5,1.0,20.0,0.0];
foreach($vectors as$value){
    $v=['properties'=>['Value'=>$value,'Label'=>'café/庭','List'=>[2,1],'Numeric'=>'123','Enabled'=>true],'attributes'=>['Saved'=>'x']];
    check(invoke($m,'canonical',$v)===MCBEngine::canonical($v),'Canonical numeric and nested metadata agree with the independent engine.');
}
echo 'PASS backup protocol: '.$GLOBALS['testCount']." assertions\n";
