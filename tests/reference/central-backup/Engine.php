<?php
declare(strict_types=1);
require_once __DIR__.'/SourceContract.php';
require_once __DIR__.'/BackupProtocol.php';

/** Read-only extraction and tamper detection. No Symcon calls in this class. */
final class MCBEngine
{
    public static function json($value): string { return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR); }
    public static function canonical($value): string
    {
        $sort = static function ($v) use (&$sort) {
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v, SORT_STRING);
            foreach ($v as &$child) $child = $sort($child);
            return $v;
        };
        return self::json($sort($value));
    }
    public static function decode(string $json): array
    {
        $v=json_decode($json,true,128,JSON_THROW_ON_ERROR);
        if (!is_array($v)) throw new RuntimeException('Expected a JSON object or array.');
        return $v;
    }
    public static function installationName(string $value): string { return strtolower(trim($value," \t\r\n")); }
    public static function slug(string $slug): string
    {
        $slug=self::installationName($slug);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D',$slug)) throw new InvalidArgumentException('Installation: use 1–40 letters/numbers, hyphens or underscores; start with a letter or number. Capitals are converted automatically.');
        return $slug;
    }
    public static function adapter(array $rule,array $fingerprint): string
    {
        if (MCBSourceContract::validContract($rule,$fingerprint)) return $fingerprint['contract']['adapter'];
        return $rule['sourceAdapters'][$fingerprint['moduleBlob']??'']??$rule['adapter'];
    }
    public static function supplementNames(string $adapter): array
    {
        return match($adapter) {
            'sensor'=>['ClassListBuffer','GroupListBuffer','SensorListBuffer','BedroomListBuffer','GroupMembersBuffer','DispatchTargetsBuffer','GroupDispatchBuffer','DraftSections','ClassStateAttribute','ActiveConfiguration'],
            'state'=>['ActiveSafetySettings'], 'motion'=>['Inventory'], 'remotesync'=>['SyncListCache'], 'restoretest'=>['SampleAttribute'], 'sonos'=>['Assets'],
            'secrets-frontend'=>['DeploymentDraft'], default=>[]
        };
    }
    public static function supplements(string $adapter,array $attributes): array
    {
        $names=self::supplementNames($adapter);
        $out=[];
        foreach ($names as $key) if (array_key_exists($key,$attributes)) $out[$key]=$attributes[$key];
        // Only identity maps belong to a configuration backup, never old COUNT timestamps.
        if (isset($out['ClassStateAttribute'])) {
            $state=self::decode((string)$out['ClassStateAttribute']);
            $out['ClassStateAttribute']=self::json(array_intersect_key($state,array_flip(['IDMap','GroupIDMap'])));
        }
        return $out;
    }
    public static function snapshot(array $settings,array $registry,array $selected,string $installation,int $mtime,array $live,array $fingerprints,array $exports=[]): array
    {
        $installation=self::slug($installation);
        if (!isset($settings['objects']) || !is_array($settings['objects'])) throw new RuntimeException('Unrecognized settings file: objects missing.');
        $wanted=[];foreach ($selected as $id) { if (!is_int($id)||$id<=0) throw new InvalidArgumentException('Invalid selected instance.');$wanted[$id]=true; }
        $instances=[];$warnings=[];$notes=[];
        foreach ($settings['objects'] as $key=>$object) {
            if (!preg_match('/^ID([1-9][0-9]*)$/D',(string)$key,$match)||!is_array($object)) continue;
            $id=(int)$match[1];$data=$object['data']??[];$module=strtoupper((string)($data['moduleID']??''));
            if (!isset($registry[$module]) || ($wanted && !isset($wanted[$id]))) continue;
            $rule=$registry[$module];$config=$data['configuration']??null;
            if (!is_array($config)) throw new RuntimeException('Missing saved configuration for instance '.$id.'.');
            if (isset($live[$id]) && self::canonical($live[$id])!==self::canonical($config)) throw new RuntimeException('Saved settings differ from current properties of instance '.$id.'. Apply/discard edits and wait for Symcon to save settings.');
            $fingerprint=$fingerprints[$module]??[];
            if (isset($exports[$id])) {
                $export=$exports[$id];$envelope=$export['envelope']??null;$validation=$export['validation']??null;
                $risks=isset($export['issue'])?[$export['issue']]:((($validation['valid']??false)===true)?[]:($validation['issues']?:['Native configuration validation failed.']));
                foreach ($risks as $risk) $warnings[]='Instance '.$id.' ('.$rule['name'].'): '.$risk;
                $instances[(string)$id]=['id'=>$id,'name'=>(string)($object['name']??$rule['name']),'moduleID'=>$module,'moduleName'=>$rule['name'],
                    'adapter'=>'module-protocol','properties'=>[],'attributes'=>[],'source'=>$fingerprint,'audited'=>false,
                    'native'=>$envelope['payload']??null,'protocol'=>$envelope,'validation'=>$validation,
                    'coverage'=>$envelope===null?'configuration-omitted':($risks?'native-format-rejected':'native-format-validated')];
                foreach (array_unique(array_merge($envelope['notes']??[],$validation['notes']??[])) as $note) $notes[]='Instance '.$id.': '.$note;
                continue;
            }
            $adapter=self::adapter($rule,$fingerprint);
            $attributes=self::supplements($adapter,$data['attributes']??[]);
            $audited=MCBSourceContract::reviewed($rule,$fingerprint);
            if ($rule['manualRestore']??false) {
                // Never retain raw Secrets properties or attributes when the schema is unknown or invalid.
                $native=null;$reason='Saved settings do not match the native configuration format.';
                $audited=true;
                try { $native=MCBAdapters::export(['adapter'=>$adapter,'properties'=>$config,'attributes'=>$attributes]); }
                catch (InvalidArgumentException | JsonException $e) { $audited=false; }
                if (!$audited) $warnings[]='Instance '.$id.' ('.$rule['name'].'): Secrets configuration omitted. '.$reason.' This backup cannot restore this configuration.';
                $notes[]='Instance '.$id.' ('.$rule['name'].'): '.($audited?'Legacy configuration schema checks passed.':'Configuration schema checks failed.').' Update this module to enable module-owned export and importer validation.';
                foreach (MCBBackupProtocol::sourceNotes($rule,$fingerprint) as $note) $notes[]='Instance '.$id.': '.$note;
                if ($native!==null) {
                    $unknown=array_diff(array_keys($config),array_keys($native['configuration']));
                    if ($unknown) $warnings[]='Instance '.$id.' ('.$rule['name'].'): '.count($unknown).' saved setting(s) are outside the supported legacy configuration export and cannot be restored from it.';
                }
                $instances[(string)$id]=['id'=>$id,'name'=>(string)($object['name']??$rule['name']),'moduleID'=>$module,'moduleName'=>$rule['name'],
                    'adapter'=>$adapter,'properties'=>$native['configuration']??[],
                    'attributes'=>isset($native['deploymentDraft'])?['DeploymentDraft'=>$native['deploymentDraft']]:[],
                    'source'=>$fingerprint,'audited'=>$audited,'native'=>$native,
                    'coverage'=>$audited?'native-configuration-manual-import':'configuration-omitted'];
                continue;
            }
            foreach (MCBBackupProtocol::sourceNotes($rule,$fingerprint) as $note) $notes[]='Instance '.$id.': '.$note;
            $entry=['id'=>$id,'name'=>(string)($object['name']??$rule['name']),'moduleID'=>$module,'moduleName'=>$rule['name'],
                'adapter'=>$adapter,'properties'=>$config,'attributes'=>$attributes,'source'=>$fingerprint,'audited'=>$audited];
            $entry['native']=$adapter==='properties'?null:MCBAdapters::export($entry);
            $entry['coverage']=$adapter==='properties'?'properties-only; no native exporter':($audited?'native-export-covered':'unverified-installed-source');
            foreach (MCBBackupProtocol::legacyRisks($entry) as $risk) $warnings[]='Instance '.$id.' ('.$rule['name'].'): '.$risk;
            $instances[(string)$id]=$entry;
        }
        if (!$instances) throw new RuntimeException('No selected supported module instances in the settings file.');
        foreach ($wanted as $id=>$_) if (!isset($instances[(string)$id])) throw new RuntimeException('Selected instance '.$id.' is absent or excluded. No partial successful backup created.');
        ksort($instances,SORT_NUMERIC);
        $logical=['installation'=>$installation,'instances'=>array_map([self::class,'configurationIdentity'],$instances)];
        return ['schema'=>'MCB.Settings.v1','createdAt'=>gmdate('c'),'installation'=>$installation,'sourceModifiedAt'=>$mtime,
            'symcon'=>$settings['compatibility']??[],'contentHash'=>hash('sha256',self::canonical($logical)),
            'warnings'=>$warnings,'notes'=>$notes,'instances'=>$instances];
    }
    public static function configurationIdentity(array $entry): array
    {
        // Source and validation metadata remain in the generation, but are not settings changes.
        $identity=array_intersect_key($entry,array_flip(['id','moduleID','adapter','properties','attributes']));
        if (($entry['adapter']??'')==='module-protocol') $identity['native']=$entry['native'];
        return $identity;
    }
    public static function wrap(array $snapshot): string
    {
        return self::json(['schema'=>'MCB.Local.v1','sha256'=>hash('sha256',self::canonical($snapshot)),'snapshot'=>$snapshot]);
    }
    public static function unwrap(string $json): array
    {
        $v=self::decode($json);
        if (($v['schema']??'')!=='MCB.Local.v1'||!is_array($v['snapshot']??null)||!is_string($v['sha256']??null)
            ||!hash_equals(hash('sha256',self::canonical($v['snapshot'])),$v['sha256'])) throw new RuntimeException('Backup checksum or format is invalid.');
        if (($v['snapshot']['schema']??'')!=='MCB.Settings.v1'||!is_array($v['snapshot']['instances']??null)) throw new RuntimeException('Unsupported snapshot.');
        return $v['snapshot'];
    }
    public static function encrypt(string $plain,string $base64Key): string
    {
        $key=base64_decode($base64Key,true);
        if ($key===false||strlen($key)!==32) throw new RuntimeException('EncryptionKey must be a base64 encoded 32-byte key.');
        $nonce=random_bytes(12);$tag='';$aad='MCB.Encrypted.v1';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
        if ($cipher===false) throw new RuntimeException('Backup encryption failed.');
        return self::json(['schema'=>$aad,'cipher'=>'AES-256-GCM','nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)]);
    }
    public static function decrypt(string $json,string $base64Key): string
    {
        $v=self::decode($json);$key=base64_decode($base64Key,true);
        if (($v['schema']??'')!=='MCB.Encrypted.v1'||($v['cipher']??'')!=='AES-256-GCM'||$key===false||strlen($key)!==32) throw new RuntimeException('Invalid encrypted backup or encryption key.');
        foreach (['nonce','tag','data'] as $field) { $v[$field]=base64_decode((string)($v[$field]??''),true);if ($v[$field]===false) throw new RuntimeException('Invalid encrypted backup.'); }
        if (strlen($v['nonce'])!==12||strlen($v['tag'])!==16) throw new RuntimeException('Invalid encryption parameters.');
        $plain=openssl_decrypt($v['data'],'aes-256-gcm',$key,OPENSSL_RAW_DATA,$v['nonce'],$v['tag'],$v['schema']);
        if ($plain===false) throw new RuntimeException('Backup authentication failed. Check the original encryption key.');
        return $plain;
    }
    public static function changedProperties(array $before,array $after): array
    {
        $keys=array_unique(array_merge(array_keys($before),array_keys($after)));$out=[];
        foreach ($keys as $key) if (!array_key_exists($key,$before)||!array_key_exists($key,$after)||self::canonical($before[$key])!==self::canonical($after[$key])) $out[]=$key;
        sort($out);return $out;
    }
}
