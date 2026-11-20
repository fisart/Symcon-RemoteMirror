<?php
declare(strict_types=1);

trait RemoteSyncConfiguration
{
    private const CONFIG_FORMAT='RemoteSync.Configuration.v1';
    private const MODULE_ID='{C6D3D6A3-0442-4217-9150-70588661C02D}';
    private function propertyTypes(): array
    {
        return ['SyncEnabled'=>'boolean','DebugMode'=>'boolean','AutoCreate'=>'boolean','ReplicateProfiles'=>'boolean','OnlyChangedValues'=>'boolean','WebEnabled'=>'boolean',
            'LaneCount'=>'integer','BatchIntervalMs'=>'integer','PerformanceInterval'=>'integer','LocalPasswordModuleID'=>'integer','MaxBufferSize'=>'integer','RequestTimeout'=>'integer','PortalInstanceID'=>'integer','DocumentationInstanceID'=>'integer',
            'LocalServerKey'=>'string','Targets'=>'string','Roots'=>'string'];
    }
    private static function canonical($value): string
    {
        $sort=function($v)use(&$sort){if(is_object($v)){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=$sort($x);unset($x);return (object)$a;}if(is_array($v))return array_map($sort,$v);return $v;};
        // Convert associative PHP arrays to JSON objects before sorting; JSON lists retain their order.
        return json_encode($sort(json_decode(json_encode($value,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),false,128,JSON_THROW_ON_ERROR)),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
    }
    public function ExportConfig(): string
    {
        $properties=json_decode(IPS_GetConfiguration($this->InstanceID),true,64,JSON_THROW_ON_ERROR);
        return json_encode(['schema'=>self::CONFIG_FORMAT,'Properties'=>$properties,'Attributes'=>['SyncListCache'=>$this->ReadAttributeString('SyncListCache')]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public function ExportConfigurationBackup(): string
    {
        $payload=json_decode($this->ExportConfig(),false,64,JSON_THROW_ON_ERROR);
        $source=['properties'=>json_decode(IPS_GetConfiguration($this->InstanceID),false,64,JSON_THROW_ON_ERROR),'attributes'=>(object)['SyncListCache'=>$this->ReadAttributeString('SyncListCache')]];
        return json_encode(['schema'=>'Symcon.ConfigurationBackup.v1','moduleID'=>self::MODULE_ID,'format'=>self::CONFIG_FORMAT,'payload'=>$payload,
            'sourceHash'=>hash('sha256',self::canonical($source)),'attributeNames'=>['SyncListCache'],'restoreMode'=>'manual-native-import',
            'exclusions'=>['Credentials and passkey sessions','Runtime counters and debug text','Sender and receiver history journals, delivery cursors, source identity and retry state'],
            'notes'=>['Configuration backup only. Back up the Symcon kernel remotesync and remotesync-received directories separately for disaster recovery of history and identity.','Native import preserves the destination identity and pending history; synchronization is paused until reviewed and enabled.']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    private function validatedConfig(string $json): array
    {
        if(strlen($json)>8*1024*1024)throw new RuntimeException('Configuration exceeds 8 MiB.');
        $d=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($d)||isset($d['schema'])&&$d['schema']!==self::CONFIG_FORMAT)throw new RuntimeException('Unsupported configuration schema. Import the native payload, not the backup envelope.');
        if(!is_array($d['Properties']??null)||!is_array($d['Attributes']??null)||!is_string($d['Attributes']['SyncListCache']??null))throw new RuntimeException('Properties and SyncListCache are required.');
        if(array_diff(array_keys($d),['schema','Properties','Attributes'])||array_diff(array_keys($d['Attributes']),['SyncListCache']))throw new RuntimeException('Unknown configuration fields.');
        $types=$this->propertyTypes();foreach($d['Properties'] as $k=>$v)if(!isset($types[$k])||gettype($v)!==$types[$k])throw new RuntimeException('Unknown property or invalid property type.');
        foreach(['Targets','Roots','LocalPasswordModuleID','LocalServerKey'] as $k)if(!array_key_exists($k,$d['Properties']))throw new RuntimeException('Required properties missing.');
        foreach(['LaneCount'=>[1,10],'BatchIntervalMs'=>[0,5000],'PerformanceInterval'=>[10,3600],'MaxBufferSize'=>[1,50000],'RequestTimeout'=>[30,600],'LocalPasswordModuleID'=>[0,99999],'PortalInstanceID'=>[0,99999],'DocumentationInstanceID'=>[0,99999]] as $k=>$range)if(isset($d['Properties'][$k])&&($d['Properties'][$k]<$range[0]||$d['Properties'][$k]>$range[1]))throw new RuntimeException('Configuration number outside supported range.');
        $targets=json_decode($d['Properties']['Targets'],true,32,JSON_THROW_ON_ERROR);$roots=json_decode($d['Properties']['Roots'],true,32,JSON_THROW_ON_ERROR);$rows=json_decode($d['Attributes']['SyncListCache'],true,32,JSON_THROW_ON_ERROR);
        foreach([$targets,$roots,$rows] as $list)if(!is_array($list)||!array_is_list($list))throw new RuntimeException('Targets, Roots and selections must be lists.');
        $names=[];$mappings=[];$seen=[];
        foreach($targets as $t){if(!is_array($t)||!is_string($t['Name']??null)||$t['Name']===''||isset($names[$t['Name']])||!is_string($t['RemoteKey']??null)||!is_int($t['RemoteScriptRootID']??null)||$t['RemoteScriptRootID']<=0||!is_int($t['RemoteSecretsID']??null)||$t['RemoteSecretsID']<0)throw new RuntimeException('Invalid or duplicate target.');$names[$t['Name']]=true;}
        foreach($roots as $r){if(!is_array($r)||!is_string($r['TargetFolder']??null)||!isset($names[$r['TargetFolder']])||!is_int($r['LocalRootID']??null)||$r['LocalRootID']<=0||!is_int($r['RemoteRootID']??null)||$r['RemoteRootID']<=0)throw new RuntimeException('Invalid root mapping.');$key=$r['TargetFolder'].'|'.$r['LocalRootID'];if(isset($mappings[$key]))throw new RuntimeException('Duplicate root mapping.');$mappings[$key]=true;}
        foreach($rows as $row){
            if(!is_array($row)||!is_string($row['Folder']??null)||!is_int($row['LocalRootID']??null)||!is_int($row['ObjectID']??null)||$row['ObjectID']<=0||!isset($mappings[$row['Folder'].'|'.$row['LocalRootID']]))throw new RuntimeException('Selection has no valid mapping.');
            $key=$row['Folder'].'|'.$row['LocalRootID'].'|'.$row['ObjectID'];if(isset($seen[$key]))throw new RuntimeException('Duplicate selection.');$seen[$key]=true;
            foreach(['Active','FullHistory','Immediate','Action','Delete'] as $flag)if(isset($row[$flag])&&!is_bool($row[$flag]))throw new RuntimeException('Selection flags must be boolean.');
            if(!empty($row['Active'])&&!empty($row['Delete']))throw new RuntimeException('A selection cannot be active and marked for deletion.');
        }
        return $d;
    }
    public function ValidateConfigurationBackup(string $JSON): string
    {
        $issues=[];$hash='';try{$native=json_decode($JSON,false,64,JSON_THROW_ON_ERROR);$hash=hash('sha256',self::canonical($native));$this->validatedConfig($JSON);}catch(Throwable $e){$issues[]='Invalid or unsupported configuration. Check the schema, field types, ranges and mapping references.';}
        return json_encode(['schema'=>'Symcon.ConfigurationBackupValidation.v1','valid'=>!$issues,'scope'=>self::CONFIG_FORMAT,'payloadHash'=>$hash,'issues'=>$issues,
            'checks'=>['Native schema and property allowlist','Mapping and selection consistency'],'notes'=>['Read-only format validation. Local dependency availability is checked during import.']],JSON_THROW_ON_ERROR);
    }
    public function ImportConfig(string $JSONString): array
    {
        return $this->importNative($JSONString, null);
    }

    private function importNative(string $json, ?string $revision): array
    {
        $lock = 'RS_Config_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 10000)) return ['status'=>false,'messages'=>['Configuration busy.']];
        $held = [];
        $old = null;
        try {
            $d = $this->validatedConfig($json);
            $current = $this->ExportConfig();
            if ($revision !== null && !hash_equals(hash('sha256', $current), $revision)) throw new RuntimeException('Configuration changed. Refresh before saving.');
            if ($this->ReadPropertyBoolean('SyncEnabled') || IPS_HasChanges($this->InstanceID)) throw new RuntimeException('Pause capture and apply outstanding console changes before importing.');
            foreach ($this->lanes() as $key => $lane) {
                foreach (['RS_Lock_', 'RS_Queue_'] as $prefix) {
                    $name = $prefix . $this->InstanceID . '_' . $key;
                    if (!IPS_SemaphoreEnter($name, 0)) throw new RuntimeException('Delivery is active. Retry when the worker finishes.');
                    $held[] = $name;
                }
            }
            if ($this->hasPendingDelivery()) throw new RuntimeException('Pending delivery must drain before importing configuration.');
            foreach (json_decode($d['Properties']['Roots'], true) as $root) {
                if (!IPS_ObjectExists($root['LocalRootID'])) throw new RuntimeException('A local root is missing.');
            }
            foreach (json_decode($d['Attributes']['SyncListCache'], true) as $row) {
                if (!IPS_ObjectExists($row['ObjectID']) || IPS_GetObject($row['ObjectID'])['ObjectType'] !== 2 || !$this->IsChildOf($row['ObjectID'], $row['LocalRootID'])) {
                    throw new RuntimeException('A selected variable is missing or outside its local root.');
                }
            }
            $vault = $d['Properties']['LocalPasswordModuleID'];
            if ($vault && !IPS_InstanceExists($vault)) throw new RuntimeException('Configured Secrets instance is missing.');
            $old = json_decode($current, true, 64, JSON_THROW_ON_ERROR);
            foreach ($d['Properties'] as $key => $value) IPS_SetProperty($this->InstanceID, $key, $value);
            IPS_SetProperty($this->InstanceID, 'SyncEnabled', false);
            $this->WriteAttributeString('SyncListCache', $d['Attributes']['SyncListCache']);
            IPS_ApplyChanges($this->InstanceID);
            return ['status'=>true,'messages'=>['Imported. Resume capture when ready.']];
        } catch (Throwable $e) {
            if ($old !== null) {
                foreach ($old['Properties'] as $key => $value) IPS_SetProperty($this->InstanceID, $key, $value);
                $this->WriteAttributeString('SyncListCache', $old['Attributes']['SyncListCache']);
                IPS_ApplyChanges($this->InstanceID);
            }
            return ['status'=>false,'messages'=>[$e->getMessage()]];
        } finally {
            foreach (array_reverse($held) as $name) IPS_SemaphoreLeave($name);
            IPS_SemaphoreLeave($lock);
        }
    }
}
