<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/SecretsSession.php';

trait RemoteSyncAdministration
{
    private function createAdministration(): void
    {
        $this->RegisterPropertyBoolean('WebEnabled',false);
        $this->RegisterPropertyInteger('PortalInstanceID',0);$this->RegisterPropertyInteger('DocumentationInstanceID',0);
        $this->RegisterAttributeString('WebCSRFKey','');
        $this->RegisterTimer('RegisterWebHook',0,'RS_RegisterWebHook($_IPS[\'TARGET\']);');
    }
    private function applyAdministration(): void
    {
        $this->MaintainVariable('Debug','Debug log',3,'',900,true);
        if($this->ReadAttributeString('WebCSRFKey')==='')$this->WriteAttributeString('WebCSRFKey',bin2hex(random_bytes(32)));
        if(IPS_GetKernelRunlevel()===KR_READY)$this->RegisterWebHook();
    }
    public function GetWebURL(): string {return '/hook/remote_sync_'.$this->InstanceID;}
    public function RegisterWebHook(): void
    {
        $this->SetTimerInterval('RegisterWebHook',0);
        $this->manageWebHook($this->ReadPropertyBoolean('WebEnabled'));
    }
    private function removeWebHook(): void {$this->manageWebHook(false);}
    private function manageWebHook(bool $enabled): void
    {
        $controllers=IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');if(!$controllers)return;
        $lock='ALPORT.WebhookControl';if(!IPS_SemaphoreEnter($lock,10000)){$this->SetTimerInterval('RegisterWebHook',5000);return;}
        try {
            $id=$controllers[0];if(IPS_HasChanges($id)){$this->SetTimerInterval('RegisterWebHook',5000);return;}
            $hooks=json_decode(IPS_GetProperty($id,'Hooks'),true,32,JSON_THROW_ON_ERROR);if(!is_array($hooks))throw new RuntimeException('Invalid WebHook registry.');
            $new=[];$found=false;foreach($hooks as $hook){
                if(($hook['Hook']??'')!==$this->GetWebURL()){$new[]=$hook;continue;}
                if((int)($hook['TargetID']??0)!==$this->InstanceID)throw new RuntimeException('Administration WebHook belongs to another instance.');
                $found=true;if($enabled)$new[]=$hook;
            }
            if($enabled&&!$found)$new[]=['Hook'=>$this->GetWebURL(),'TargetID'=>$this->InstanceID];
            if($new!==$hooks){IPS_SetProperty($id,'Hooks',json_encode($new,JSON_THROW_ON_ERROR));IPS_ApplyChanges($id);}
        }catch(Throwable $e){$this->Log('Administration WebHook registration failed. Check WebHook Control configuration.',KL_ERROR);}
        finally{IPS_SemaphoreLeave($lock);}
    }
    public function AddToPortal(): string
    {
        $id=$this->ReadPropertyInteger('PortalInstanceID');
        try {
            if(!$id||!IPS_InstanceExists($id)||strtoupper(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'')!=='{922E5A10-A048-463D-A973-97B4BBD9096B}')throw new RuntimeException('Select a Portal instance first.');
            if(!$this->ReadPropertyBoolean('WebEnabled'))throw new RuntimeException('Enable the administration page first.');
            if(IPS_HasChanges($id))throw new RuntimeException('Apply or discard pending Portal changes first.');
            $rows=json_decode(IPS_GetProperty($id,'AdditionalPages'),true,32,JSON_THROW_ON_ERROR);if(!is_array($rows))throw new RuntimeException('Invalid Portal page list.');
            $entry=['Name'=>'RemoteSync','Description'=>'Replication, delivery status and configuration','Cluster'=>'smart','Hook'=>$this->GetWebURL(),'TargetID'=>$this->InstanceID];$found=false;
            foreach($rows as &$row)if(($row['Hook']??'')===$entry['Hook']){$row=$entry;$found=true;}unset($row);if(!$found)$rows[]=$entry;
            IPS_SetProperty($id,'AdditionalPages',json_encode($rows,JSON_THROW_ON_ERROR));IPS_ApplyChanges($id);return 'RemoteSync added to Portal.';
        }catch(Throwable $e){return $e->getMessage();}
    }
    private function appendDebug(string $message,int $severity): void
    {
        $lock='RS_Debug_'.$this->InstanceID;if(!IPS_SemaphoreEnter($lock,1000))return;
        try{$id=@IPS_GetObjectIDByIdent('Debug',$this->InstanceID);if(!$id)return;$line=date('c').' ['.$severity.'] '.str_replace(["\r","\n"],' ',$message)."\n";$value=(string)GetValue($id).$line;
            if(strlen($value)>65536){$value=substr($value,-65536);$p=strpos($value,"\n");if($p!==false)$value=substr($value,$p+1);}SetValue($id,$value);
        }finally{IPS_SemaphoreLeave($lock);}
    }
    public function ClearDebug(): void {$lock='RS_Debug_'.$this->InstanceID;if(!IPS_SemaphoreEnter($lock,1000))return;try{$id=@IPS_GetObjectIDByIdent('Debug',$this->InstanceID);if($id)SetValue($id,'');}finally{IPS_SemaphoreLeave($lock);}}
    private function sessionFingerprint(int $vault): string
    {
        $backend=RemoteSyncSecrets::backend($vault);
        if($backend){$matches=[];preg_match_all('/(?:^|;\s*)__Host-SECB_'.$backend.'=([A-Za-z0-9_-]{43})(?=;|$)/',$_SERVER['HTTP_COOKIE']??'',$matches);if(count($matches[1])!==1)throw new RuntimeException('Session cookie missing or ambiguous.');return $matches[1][0];}
        $cookie=$_COOKIE['SEC_PORTAL_V2_'.$vault]??'';if(!is_string($cookie)||$cookie===''||strlen($cookie)>4096)throw new RuntimeException('Session cookie unavailable.');return $cookie;
    }
    private function csrf(int $vault,int $slot): string {return hash_hmac('sha256',$this->GetWebURL().'|'.$vault.'|'.$this->sessionFingerprint($vault).'|'.$slot,$this->ReadAttributeString('WebCSRFKey'));}
    private function verifyWebMutation(int $vault,array $body): void
    {
        $origin=$_SERVER['HTTP_ORIGIN']??'';$host=$_SERVER['HTTP_HOST']??'';
        if($origin===''||!hash_equals('https://'.$host,$origin))throw new RuntimeException('Request origin mismatch. Reload the page.');
        $token=$body['csrf']??'';$slot=(int)floor(time()/3600);
        if(!is_string($token)||(!hash_equals($this->csrf($vault,$slot),$token)&&!hash_equals($this->csrf($vault,$slot-1),$token)))throw new RuntimeException('Session verification failed. Reload the page.');
    }
    public function SetSynchronization(bool $Enabled): void
    {
        if(IPS_HasChanges($this->InstanceID))throw new RuntimeException('Apply or discard pending console changes first.');
        $lock = 'RS_Config_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 10000)) throw new RuntimeException('Configuration busy.');
        try {
            if ($Enabled) $this->validatedConfig($this->ExportConfig());
            IPS_SetProperty($this->InstanceID,'SyncEnabled',$Enabled);
            IPS_ApplyChanges($this->InstanceID);
        } finally { IPS_SemaphoreLeave($lock); }
    }
    public function ProcessHookData()
    {
        header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
        try {
            if(!$this->ReadPropertyBoolean('WebEnabled')){http_response_code(404);echo 'Administration page disabled.';return;}
            $vault=RemoteSyncSecrets::resolve($this->ReadPropertyInteger('LocalPasswordModuleID'));
            if(!$vault){http_response_code(503);echo 'Select a Secrets instance with Portal/passkey access in the RemoteSync console configuration.';return;}
            if(!RemoteSyncSecrets::authenticated($vault)){
                http_response_code(401);header('Content-Type: text/html; charset=utf-8');$login=RemoteSyncSecrets::login($vault,$this->GetWebURL(),$_SERVER);
                echo '<!doctype html><meta name="viewport" content="width=device-width"><title>RemoteSync sign in</title><p>Sign in with your existing passkey to administer RemoteSync.</p><a href="'.htmlspecialchars($login,ENT_QUOTES,'UTF-8').'">Sign in</a>';return;
            }
            $method=$_SERVER['REQUEST_METHOD']??'GET';$action=$_GET['action']??'';
            if($method==='POST'){
                $raw=file_get_contents('php://input',false,null,0,8*1024*1024+1);if(strlen($raw)>8*1024*1024)throw new RuntimeException('Request too large.');
                $body=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($body))throw new RuntimeException('Invalid request.');$this->verifyWebMutation($vault,$body);
                $action=$body['action']??'';$message='Done.';
                switch($action){
                    case 'save':
                        if(!is_string($body['revision']??null)||!hash_equals(hash('sha256',$this->ExportConfig()),$body['revision']))throw new RuntimeException('Configuration changed elsewhere. Refresh before saving.');
                        $r=$this->importNative(json_encode($body['config']??null,JSON_THROW_ON_ERROR),$body['revision']);if(!$r['status'])throw new RuntimeException(implode(' ',$r['messages']));$message=implode(' ',$r['messages']);break;
                    case 'validate':$message=json_decode($this->ValidateConfigurationBackup(json_encode($body['config']??null,JSON_THROW_ON_ERROR)),true);break;
                    case 'enable':$this->SetSynchronization(true);$message='Synchronization enabled. Queued history will resume.';break;
                    case 'pause':$this->SetSynchronization(false);$message='Synchronization paused. New source events are not captured while paused; pending history is retained.';break;
                    case 'sync':$this->ProcessSync();$message='Current values queued.';break;
                    case 'clearDebug':$this->ClearDebug();break;
                    case 'resetCounters':$this->ResetTrafficStatistics();break;
                    case 'portal':$message=$this->AddToPortal();break;
                    case 'install':ob_start();try{$this->InstallRemoteScripts((string)($body['folder']??''));$message=ob_get_contents();}finally{ob_end_clean();}if(str_starts_with($message,'Error:'))throw new RuntimeException($message);break;
                    default:throw new RuntimeException('Unknown action.');
                }
                header('Content-Type: application/json');echo json_encode(['ok'=>true,'message'=>$message],JSON_THROW_ON_ERROR);return;
            }
            if($method!=='GET'){http_response_code(405);return;}
            if($action==='export'){header('Content-Type: application/json');header('Content-Disposition: attachment; filename="RemoteSync-'.$this->InstanceID.'.json"');echo $this->ExportConfig();return;}
            if($action==='status'){
                $config=json_decode($this->ExportConfig(),true);$debug=@IPS_GetObjectIDByIdent('Debug',$this->InstanceID);$rows=json_decode($config['Attributes']['SyncListCache'],true);$known=[];
                foreach($rows as $row)$known[$row['Folder'].'|'.$row['LocalRootID'].'|'.$row['ObjectID']]=true;
                foreach(json_decode($config['Properties']['Roots'],true) as $root){$vars=[];$this->GetRecursiveVariables($root['LocalRootID'],$vars);foreach($vars as $id){$key=$root['TargetFolder'].'|'.$root['LocalRootID'].'|'.$id;if(!isset($known[$key]))$rows[]=['Folder'=>$root['TargetFolder'],'LocalRootID'=>$root['LocalRootID'],'ObjectID'=>$id,'Name'=>IPS_GetName($id),'Active'=>false,'FullHistory'=>false,'Immediate'=>false,'Action'=>false,'Delete'=>false];}}
                header('Content-Type: application/json');echo json_encode(['ok'=>true,'version'=>self::VERSION,'config'=>$config,'selections'=>$rows,'revision'=>hash('sha256',$this->ExportConfig()),'csrf'=>$this->csrf($vault,(int)floor(time()/3600)),
                    'delivery'=>json_decode($this->GetDeliveryStatus(),true),'traffic'=>json_decode($this->GetTrafficStatistics(),true),'debug'=>$debug?GetValue($debug):'',
                    'portal'=>$this->ReadPropertyInteger('PortalInstanceID')?'/hook/alarm_portal_'.$this->ReadPropertyInteger('PortalInstanceID'):'','documentation'=>$this->ReadPropertyInteger('DocumentationInstanceID')?'/hook/alarm_documentation_'.$this->ReadPropertyInteger('DocumentationInstanceID'):''],JSON_THROW_ON_ERROR);return;
            }
            if($action!=='')throw new RuntimeException('Unknown page action.');
            $nonce=bin2hex(random_bytes(16));header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");header('Content-Type: text/html; charset=utf-8');
            echo str_replace('__NONCE__',$nonce,file_get_contents(__DIR__.'/web.html'));
        }catch(Throwable $e){http_response_code(400);header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>$e instanceof JsonException?'Invalid JSON.':$e->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE);}
    }
}
