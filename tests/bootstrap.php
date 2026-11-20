<?php
declare(strict_types=1);
// In-process Symcon and cURL doubles. Run with php -n; no network, live variables or credentials.
const VM_UPDATE=10603,KR_READY=10103,IPS_KERNELMESSAGE=10100,KL_MESSAGE=0,KL_WARNING=1,KL_ERROR=2;
foreach(['CURLOPT_URL','CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_POSTFIELDS','CURLOPT_HTTPHEADER','CURLOPT_TIMEOUT','CURLOPT_CONNECTTIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_USERPWD','CURLOPT_FOLLOWLOCATION','CURLINFO_HTTP_CODE'] as $i=>$key)if(!defined($key))define($key,100+$i);
$GLOBALS['suiteRoot']=sys_get_temp_dir().'/rs-tests-'.bin2hex(random_bytes(8));mkdir($GLOBALS['suiteRoot'],0700,true);
$GLOBALS['modules']=[];$GLOBALS['objects']=[];$GLOBALS['profiles']=[];$GLOBALS['values']=[];$GLOBALS['logs']=[];$GLOBALS['locks']=[];$GLOBALS['jobs']=[];$GLOBALS['requests']=[];$GLOBALS['nextID']=50000;$GLOBALS['testCount']=0;$GLOBALS['run']=0;
class IPSModule {
 public int $InstanceID=321;public array $properties=[],$attributes=[],$timers=[],$buffers=[],$writes=[],$timerWrites=[],$messages=[];
 public function Create(){$GLOBALS['modules'][$this->InstanceID]=$this;}public function ApplyChanges(){}public function Destroy(){}
 protected function GetBuffer($k){return $this->buffers[$k]??'';}protected function SetBuffer($k,$v){if($v==='')unset($this->buffers[$k]);else $this->buffers[$k]=$v;}protected function GetBufferList(){return array_keys($this->buffers);}
 protected function RegisterPropertyBoolean($k,$v){$this->properties[$k]??=$v;}protected function RegisterPropertyInteger($k,$v){$this->properties[$k]??=$v;}protected function RegisterPropertyString($k,$v){$this->properties[$k]??=$v;}
 protected function RegisterAttributeInteger($k,$v){$this->attributes[$k]??=$v;}protected function RegisterAttributeBoolean($k,$v){$this->attributes[$k]??=$v;}protected function RegisterAttributeString($k,$v){$this->attributes[$k]??=$v;}
 protected function ReadPropertyInteger($k){return $this->properties[$k];}protected function ReadPropertyBoolean($k){return $this->properties[$k];}protected function ReadPropertyString($k){return $this->properties[$k];}
 protected function ReadAttributeString($k){return $this->attributes[$k];}protected function WriteAttributeString($k,$v){$this->attributes[$k]=$v;$this->writes[$k]=($this->writes[$k]??0)+1;}
 protected function RegisterTimer($k,$v,$s){$this->timers[$k]=$v;}protected function SetTimerInterval($k,$v){$this->timers[$k]=$v;$this->timerWrites[$k]=($this->timerWrites[$k]??0)+1;}
 protected function RegisterMessage($a,$b){$this->messages[$a][]=$b;}protected function GetMessageList(){return $this->messages;}protected function UnregisterMessage($a,$b){unset($this->messages[$a]);}
 protected function LogMessage($m,$severity){$GLOBALS['logs'][]=$m;}protected function SendDebug($a,$b,$c){}protected function SetStatus($s){}protected function UpdateFormField($a,$b,$c){}
 protected function MaintainVariable($ident,$name,$type,$profile,$position,$keep){$id=IPS_GetObjectIDByIdent($ident,$this->InstanceID);if(!$keep){if($id)IPS_DeleteVariable($id);return;}if(!$id){$id=IPS_CreateVariable($type);IPS_SetParent($id,$this->InstanceID);IPS_SetIdent($id,$ident);IPS_SetName($id,$name);}}
}
function obj(int $id,int $type,int $parent=0,string $name='',string $ident='',string $info=''):void{$GLOBALS['objects'][$id]=['ObjectID'=>$id,'HasChildren'=>false,'ObjectType'=>$type,'ParentID'=>$parent,'ObjectName'=>$name?:'Object '.$id,'ObjectIdent'=>$ident,'ObjectInfo'=>$info,'VariableType'=>1,'VariableCustomProfile'=>'','VariableProfile'=>''];if(isset($GLOBALS['objects'][$parent]))$GLOBALS['objects'][$parent]['HasChildren']=true;}
function IPS_GetKernelDir(){return $GLOBALS['kernel'].'/';}function IPS_GetKernelRunlevel(){return KR_READY;}
function IPS_SemaphoreEnter($k,$ms){if($GLOBALS['locks'][$k]??false)return false;return $GLOBALS['locks'][$k]=true;}function IPS_SemaphoreLeave($k){$GLOBALS['locks'][$k]=false;}
function IPS_RunScriptText($code){if($GLOBALS['dispatchFail']??false)return false;$GLOBALS['jobs'][]=$code;return true;}
function IPS_LogMessage($sender,$message){$GLOBALS['logs'][]=$message;}function IPS_ObjectExists($id){return isset($GLOBALS['objects'][$id]);}function IPS_InstanceExists($id){return isset($GLOBALS['modules'][$id])||($GLOBALS['objects'][$id]['ObjectType']??-1)===1;}
function IPS_GetInstanceListByModuleID($id){return array_keys(array_filter($GLOBALS['moduleIDs']??[],static fn($module,$object)=>$module===$id && IPS_ObjectExists($object),ARRAY_FILTER_USE_BOTH));}function IPS_HasChanges($id){return $GLOBALS['pendingChanges'][$id]??false;}
function IPS_GetConfiguration($id){return json_encode($GLOBALS['modules'][$id]->properties,JSON_THROW_ON_ERROR);}function IPS_SetProperty($id,$key,$value){$GLOBALS['modules'][$id]->properties[$key]=$value;}
function IPS_GetProperty($id,$key){return $GLOBALS['modules'][$id]->properties[$key];}function IPS_ApplyChanges($id){$GLOBALS['modules'][$id]->ApplyChanges();}
function IPS_GetInstance($id){return ['ModuleInfo'=>['ModuleID'=>$GLOBALS['moduleIDs'][$id]??'{C6D3D6A3-0442-4217-9150-70588661C02D}']];}
function IPS_GetParent($id){return IPS_GetObject($id)['ParentID'];}function IPS_GetVariable($id){return IPS_GetObject($id);}function IPS_GetName($id){return IPS_GetObject($id)['ObjectName'];}
function IPS_GetObject($id){if(!isset($GLOBALS['objects'][$id]))throw new RuntimeException('Object missing.');return $GLOBALS['objects'][$id];}
function IPS_GetObjectIDByIdent($ident,$parent){foreach($GLOBALS['objects'] as $id=>$obj)if($obj['ParentID']===$parent&&$obj['ObjectIdent']===$ident)return $id;return 0;}
function IPS_GetChildrenIDs($parent){return array_keys(array_filter($GLOBALS['objects'],fn($o)=>$o['ParentID']===$parent));}
function GetValue($id){return $GLOBALS['values'][$id]??'';}function SetValue($id,$v){if(($GLOBALS['rejectValue']??null)===$v)throw new RuntimeException('Injected SetValue failure.');$GLOBALS['values'][$id]=$v;$GLOBALS['valueWrites'][$id]=($GLOBALS['valueWrites'][$id]??0)+1;if(($GLOBALS['throwAfterSet']??0)===$id){unset($GLOBALS['throwAfterSet']);throw new RuntimeException('Interrupted before receipt');}return true;}
function IPS_CreateCategory(){$id=$GLOBALS['nextID']++;obj($id,0);return $id;}function IPS_CreateVariable($type){$id=$GLOBALS['nextID']++;obj($id,2);$GLOBALS['objects'][$id]['VariableType']=$type;return $id;}
function IPS_CreateScript($type){$id=$GLOBALS['nextID']++;obj($id,3);return $id;}function IPS_SetParent($id,$v){$GLOBALS['objects'][$id]['ParentID']=$v;}function IPS_SetInfo($id,$v){$GLOBALS['objects'][$id]['ObjectInfo']=$v;}
function IPS_SetName($id,$v){$GLOBALS['objects'][$id]['ObjectName']=$v;}function IPS_SetIdent($id,$v){$other=IPS_GetObjectIDByIdent($v,IPS_GetParent($id));if($other&&$other!==$id)throw new RuntimeException('Ident occupied.');$GLOBALS['objects'][$id]['ObjectIdent']=$v;}
function IPS_DeleteVariable($id){unset($GLOBALS['objects'][$id],$GLOBALS['values'][$id]);return true;}function IPS_SetVariableCustomProfile($id,$p){$GLOBALS['objects'][$id]['VariableCustomProfile']=$p;}function IPS_SetVariableCustomAction($id,$a){$GLOBALS['objects'][$id]['VariableCustomAction']=$a;return true;}
function IPS_VariableProfileExists($name){return isset($GLOBALS['profiles'][$name]);}function IPS_GetVariableProfile($name){return $GLOBALS['profiles'][$name];}
function IPS_CreateVariableProfile($n,$t){$GLOBALS['profiles'][$n]=['ProfileType'=>$t];}function IPS_SetVariableProfileIcon(...$a){}function IPS_SetVariableProfileText(...$a){}function IPS_SetVariableProfileValues(...$a){}function IPS_SetVariableProfileDigits(...$a){}function IPS_SetVariableProfileAssociation(...$a){}
function SEC_GetSecret($id,$key){$GLOBALS['reads']=($GLOBALS['reads']??0)+1;if($GLOBALS['secretFail']??false)throw new RuntimeException('Synthetic secret refused');return $GLOBALS['secret'];}
function SEC_GetKeys($id){if($GLOBALS['secretFail']??false)throw new RuntimeException('Synthetic secret refused');return '["local","remote"]';}
function curl_init($url=null){return new stdClass();}function curl_setopt_array($h,$o){$h->options=$o;$GLOBALS['curlOptions']=$o;return true;}
function curl_exec($h){$r=json_decode($h->options[CURLOPT_POSTFIELDS],true);$GLOBALS['requests'][]=$r;if(isset($GLOBALS['duringRequest'])){$f=$GLOBALS['duringRequest'];unset($GLOBALS['duringRequest']);$f();}if($GLOBALS['transportFail']??false)return false;if(isset($GLOBALS['rawResponse']))return $GLOBALS['rawResponse'];if(isset($GLOBALS['rpcError']))return json_encode(['jsonrpc'=>'2.0','id'=>$r['id'],'error'=>$GLOBALS['rpcError']]);$result=isset($GLOBALS['rpcHandler'])?($GLOBALS['rpcHandler'])($r['method'],$r['params']):null;return json_encode(['jsonrpc'=>'2.0','id'=>$r['id'],'result'=>$result]);}
function curl_getinfo($h,$key){return $GLOBALS['httpStatus']??200;}function curl_errno($h){return ($GLOBALS['transportFail']??false)?7:0;}function curl_close($h){}
function check(bool $ok,string $message):void{$GLOBALS['testCount']++;if(!$ok)throw new RuntimeException('FAIL: '.$message);}
function throws(callable $f,string $message):void{try{$f();}catch(Throwable $e){check(true,$message);return;}check(false,$message);}
function invoke($m,string $method,...$args){return (new ReflectionMethod($m,$method))->invoke($m,...$args);}
function fresh(bool $history=false):RemoteSync{
 $GLOBALS['kernel']=$GLOBALS['suiteRoot'].'/sender-'.++$GLOBALS['run'];mkdir($GLOBALS['kernel'],0700,true);$GLOBALS['locks']=[];$GLOBALS['jobs']=[];$GLOBALS['requests']=[];$GLOBALS['objects']=[];$GLOBALS['values']=[];$GLOBALS['valueWrites']=[];$GLOBALS['logs']=[];$GLOBALS['reads']=0;
 foreach(['rawResponse','rpcError','rpcHandler','duringRequest','rejectValue'] as $k)unset($GLOBALS[$k]);$GLOBALS['secretFail']=false;$GLOBALS['transportFail']=false;$GLOBALS['dispatchFail']=false;$GLOBALS['httpStatus']=200;$GLOBALS['pendingChanges']=[];
 obj(321,1);obj(10001,0);obj(10002,0);obj(10003,0);obj(20001,2,10001);obj(42,1);$GLOBALS['values'][20001]=42;
 $m=new RemoteSync();$m->Create();$m->properties['LocalPasswordModuleID']=42;$m->properties['LocalServerKey']='local';$m->properties['LaneCount']=1;$m->properties['BatchIntervalMs']=0;
 $m->properties['Targets']=json_encode([['Name'=>'Berlin','RemoteKey'=>'remote','RemoteScriptRootID'=>10003,'RemoteSecretsID'=>42]]);
 $m->properties['Roots']=json_encode([['LocalRootID'=>10001,'TargetFolder'=>'Berlin','RemoteRootID'=>10002]]);
 $m->attributes['SyncListCache']=json_encode([['ObjectID'=>20001,'LocalRootID'=>10001,'Folder'=>'Berlin','Active'=>true,'FullHistory'=>$history,'Immediate'=>false,'Action'=>false,'Delete'=>false]]);
 $GLOBALS['secret']=json_encode(['URL'=>'remote.invalid','User'=>'user','Pass'=>'test-password']);$m->ApplyChanges();return $m;
}

const RECEIVER_GUID = '{D07F247A-9FC0-49BF-961D-D2D54D0B9648}';
function IPS_GetModuleList(){return [RECEIVER_GUID];}
function SEC_IsPortalAuthenticated($id){return $GLOBALS['signedIn']??false;}
function IPS_CreateInstance($module){
    if($module!==RECEIVER_GUID)throw new RuntimeException('Unexpected module.');
    $id=$GLOBALS['nextID']++;obj($id,1);$GLOBALS['moduleIDs'][$id]=$module;
    $rx=new RemoteSyncReceiver();$rx->InstanceID=$id;$rx->Create();return $id;
}
function IPS_GetScriptContent($id){return $GLOBALS['scripts'][$id]??'';}
function IPS_SetScriptContent($id,$code){$GLOBALS['scripts'][$id]=$code;return true;}
function RSR_Configure($id,$source,$settings){return $GLOBALS['modules'][$id]->Configure($source,$settings);}
function RSR_Receive($id,$packet){return $GLOBALS['modules'][$id]->Receive($packet);}
function connectPeer(?callable $intercept=null):void{
    $GLOBALS['rpcHandler']=static function($method,$args)use($intercept){
        if($intercept){$result=$intercept($method,$args);if($result!==null)return $result;}
        if ($method==='IPS_GetKernelVersion') return '8.0';
        $permitted=['IPS_GetModuleList','IPS_GetInstanceListByModuleID','IPS_GetChildrenIDs','IPS_GetObject','IPS_CreateInstance','IPS_GetInstance','IPS_SetParent','IPS_SetIdent','IPS_SetName','RSR_Configure','RSR_Receive'];
        if(!in_array($method,$permitted,true))throw new RuntimeException('Unexpected fixture RPC '.$method);
        return $method(...$args);
    };
}
function event(RemoteSync $m,$value,bool $changed=true,int $id=20001):void{$m->MessageSink(time(),$id,VM_UPDATE,[$value,$changed]);}
function rows(RemoteSync $m):array{return json_decode($m->GetSyncState(),true)['buffer']['Berlin'][1]??[];}
function stats(RemoteSync $m):array{return json_decode($m->GetTrafficStatistics(),true);}
function forceDue(RemoteSync $m):void{
    foreach($m->buffers as $k=>$v)if(str_starts_with($k,'RSM_')){$meta=json_decode($v,true);$meta['due']=$meta['scheduled']=0;$m->buffers[$k]=json_encode($meta);}
    $m->attributes['_DeliveryRetry']='{}';
}
function flushLane(RemoteSync $m):void{$m->FlushBuffer('Berlin',1);}
function mirrorID():int{return (int)IPS_GetObjectIDByIdent('Rem_20001',10002);}
require __DIR__.'/../RemoteSync/module.php';
require __DIR__.'/../RemoteSyncReceiver/module.php';
