<?php
declare(strict_types=1);
// Exercise the actual worker with in-process Symcon/cURL doubles. No network calls.
const VM_UPDATE=10603, KR_READY=10103, IPS_KERNELMESSAGE=10100, KL_MESSAGE=0, KL_WARNING=1, KL_ERROR=2;
foreach(['CURLOPT_URL','CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_BINARYTRANSFER','CURLOPT_POSTFIELDS','CURLOPT_HTTPHEADER','CURLOPT_TIMEOUT','CURLOPT_CONNECTTIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_IPRESOLVE','CURL_IPRESOLVE_V4']as$i=>$name)if(!defined($name))define($name,100+$i);
$GLOBALS['locks']=[];$GLOBALS['jobs']=[];$GLOBALS['reads']=0;$GLOBALS['logs']=[];$GLOBALS['secret']='';$GLOBALS['transportFail']=false;$GLOBALS['dispatchFail']=false;
class IPSModule {
 public int $InstanceID=321;public array $properties=[],$attributes=[],$timers=[],$buffers=[],$writes=[],$timerWrites=[];
 protected function GetBuffer($k){return $this->buffers[$k]??'';}protected function SetBuffer($k,$v){$this->buffers[$k]=$v;}
 public function Create(){}public function ApplyChanges(){}
 protected function RegisterPropertyBoolean($k,$v){$this->properties[$k]=$v;}protected function RegisterPropertyInteger($k,$v){$this->properties[$k]=$v;}protected function RegisterPropertyString($k,$v){$this->properties[$k]=$v;}
 protected function RegisterAttributeInteger($k,$v){$this->attributes[$k]??=$v;}protected function RegisterAttributeBoolean($k,$v){$this->attributes[$k]??=$v;}protected function RegisterAttributeString($k,$v){$this->attributes[$k]??=$v;}
 protected function ReadPropertyInteger($k){return $this->properties[$k];}protected function ReadPropertyBoolean($k){return $this->properties[$k];}protected function ReadPropertyString($k){return $this->properties[$k];}
 protected function ReadAttributeString($k){return $this->attributes[$k];}protected function WriteAttributeString($k,$v){$this->attributes[$k]=$v;$this->writes[$k]=($this->writes[$k]??0)+1;}
 protected function RegisterTimer($k,$v,$s){$this->timers[$k]=$v;}protected function SetTimerInterval($k,$v){$this->timers[$k]=$v;$this->timerWrites[$k]=($this->timerWrites[$k]??0)+1;}protected function RegisterMessage($a,$b){}
 protected function LogMessage($m,$severity){$GLOBALS['logs'][]=$m;}
}
function IPS_GetKernelRunlevel(){return KR_READY;}
function IPS_SemaphoreEnter($k,$ms){if($GLOBALS['locks'][$k]??false)return false;return $GLOBALS['locks'][$k]=true;}
function IPS_SemaphoreLeave($k){$GLOBALS['locks'][$k]=false;}
function IPS_RunScriptText($s){if($GLOBALS['dispatchFail'])return false;$GLOBALS['jobs'][]=$s;return true;}
function IPS_LogMessage($sender,$message){$GLOBALS['logs'][]=$message;}
function SEC_GetSecret($id,$key){$GLOBALS['reads']++;return $GLOBALS['secret'];}
function curl_init(){return new stdClass();}function curl_setopt_array($h,$options){$GLOBALS['curlOptions']=$options;return true;}
function curl_exec($h){$GLOBALS['requests'][]=$GLOBALS['curlOptions'][CURLOPT_POSTFIELDS];if(isset($GLOBALS['duringRequest'])){$f=$GLOBALS['duringRequest'];unset($GLOBALS['duringRequest']);$f();}return $GLOBALS['transportFail']?false:json_encode(['result'=>$GLOBALS['rpcResult']??'OK']);}
function curl_error($h){return 'Synthetic transport failure';}function curl_errno($h){return 7;}function curl_close($h){}
function IPS_ObjectExists($id){return $id>0;}
function IPS_GetParent($id){return $id===10001?0:10001;}
function IPS_GetVariable($id){return ['VariableType'=>1,'VariableCustomProfile'=>'','VariableProfile'=>''];}
function IPS_GetName($id){return 'Variable '.$id;}
function IPS_GetObject($id){return ['ObjectIdent'=>'Var'.$id];}
function GetValue($id){return $GLOBALS['values'][$id]??42;}
require $argv[1]??__DIR__.'/../RemoteSync/module.php';
