<?php
declare(strict_types=1);

/** Versioned module-owned configuration exports. Never invokes an import or a device action. */
final class MCBBackupProtocol
{
    public const SCHEMA='Symcon.ConfigurationBackup.v1';
    public const VALIDATION='Symcon.ConfigurationBackupValidation.v1';
    public static function capability(string $module): ?array
    {
        if (!function_exists('IPS_GetModule')) return null;
        try { $meta=IPS_GetModule($module); } catch (Throwable $e) { return null; }
        $prefix=$meta['Prefix']??'';
        if (!is_string($prefix)||!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$prefix)) return null;
        $export=$prefix.'_ExportConfigurationBackup';$validate=$prefix.'_ValidateConfigurationBackup';
        if (!function_exists($export)) return null;
        return ['export'=>$export,'validate'=>function_exists($validate)?$validate:null,'name'=>$meta['ModuleName']??$module];
    }
    public static function envelope(array $data,string $module): void
    {
        $keys=['schema','moduleID','format','payload','sourceHash','attributeNames','restoreMode','exclusions','notes'];
        if (array_diff(array_keys($data),$keys)||array_diff($keys,array_keys($data))||($data['schema']??null)!==self::SCHEMA
            ||strtoupper((string)($data['moduleID']??''))!==$module||!is_array($data['payload'])||array_is_list($data['payload'])
            ||!is_string($data['format'])||$data['format']===''||strlen($data['format'])>120
            ||!is_string($data['sourceHash'])||!preg_match('/^[a-f0-9]{64}$/D',$data['sourceHash'])
            ||$data['restoreMode']!=='manual-native-import') throw new RuntimeException('Invalid configuration backup protocol envelope.');
        foreach (['attributeNames','exclusions','notes'] as $key) {
            if (!is_array($data[$key])||!array_is_list($data[$key])||count($data[$key])>64) throw new RuntimeException('Invalid configuration backup metadata.');
            foreach ($data[$key] as $value) if (!is_string($value)||strlen($value)>500||preg_match('/[\x00-\x1f\x7f]/',$value)) throw new RuntimeException('Invalid configuration backup metadata.');
        }
    }
    public static function validate(array $cap,array $payload): array
    {
        if ($cap['validate']===null) return ['valid'=>false,'scope'=>'format','issues'=>['Module exports configuration but provides no configuration validator.']];
        $json=MCBEngine::json($payload);$raw=($cap['validate'])($cap['id'],$json);
        if (!is_string($raw)||strlen($raw)>65536) throw new RuntimeException('Invalid configuration validation reply.');
        $v=MCBEngine::decode($raw);
        if (($v['schema']??null)!==self::VALIDATION||!is_bool($v['valid']??null)||($v['scope']??null)!=='format'
            ||!is_string($v['payloadHash']??null)||!hash_equals(hash('sha256',MCBEngine::canonical($payload)),$v['payloadHash'])
            ||!is_array($v['issues']??null)||!array_is_list($v['issues'])||count($v['issues'])>32
            ||($v['valid']&&$v['issues'])) throw new RuntimeException('Invalid configuration validation reply.');
        foreach (['issues','checks','notes'] as $key) {
            if (!is_array($v[$key]??null)||!array_is_list($v[$key])||count($v[$key])>64) throw new RuntimeException('Invalid configuration validation reply.');
            foreach ($v[$key] as $value) if (!is_string($value)||strlen($value)>500||preg_match('/[\x00-\x1f\x7f]/',$value)) throw new RuntimeException('Invalid configuration validation reply.');
        }
        // Keep only protocol metadata; never allow an accidental configuration echo in the report.
        return array_intersect_key($v,array_flip(['schema','valid','scope','payloadHash','issues','checks','notes']));
    }
    public static function capture(int $id,string $module,array $properties,array $attributes): ?array
    {
        $cap=self::capability($module);if ($cap===null) return null;$cap['id']=$id;
        try {
            $raw=($cap['export'])($id);
            if (!is_string($raw)||strlen($raw)>8388608) throw new RuntimeException('Configuration export exceeds protocol bounds.');
            $data=MCBEngine::decode($raw);self::envelope($data,$module);
        } catch (Throwable $e) { return ['issue'=>'Configuration export is missing or invalid; this backup cannot restore this instance.']; }
        $supplements=[];foreach ($data['attributeNames'] as $name) $supplements[$name]=$attributes[$name]??'';
        if (!hash_equals(hash('sha256',MCBEngine::canonical(['properties'=>$properties,'attributes'=>$supplements])),$data['sourceHash'])) throw new RuntimeException('Saved settings differ from module configuration export for instance '.$id.'. Wait for Symcon to save settings.');
        try { $validation=self::validate($cap,$data['payload']); }
        catch (Throwable $e) { $validation=['valid'=>false,'scope'=>'format','issues'=>['Module configuration validator did not return a valid result for this export.']]; }
        return ['envelope'=>$data,'validation'=>$validation];
    }
    public static function sourceNotes(array $rule,array $source): array
    {
        return MCBSourceContract::reviewed($rule,$source)?[]:['Installed source identity changed; code fingerprint alone is not a restore warning.'];
    }
    public static function legacyRisks(array $entry): array
    {
        if ($entry['native']===null) return [];
        try {
            $expected=MCBAdapters::expected($entry);$risks=[];
            foreach ($expected as $key=>$value) {
                if (!array_key_exists($key,$entry['properties'])) continue;
                $old=$entry['properties'][$key];
                if (gettype($old)!==gettype($value)) $risks[]='Native import expects a different value type for setting '.$key.'.';
            }
            return $risks;
        } catch (Throwable $e) { return ['Native restore configuration cannot be reconstructed from the saved settings.']; }
    }
}
