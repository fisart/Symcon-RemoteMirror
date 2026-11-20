<?php
declare(strict_types=1);

/** Both supported Secrets generations, with native origin/session policy owned by the backend. */
final class RemoteSyncSecrets
{
    public const OLD='{7C5A3841-3F7B-4D2A-9E1C-5B6D8F9A0E12}';
    public const BACKEND='{52165EDD-B2C0-40EC-8A59-42A97319B6E2}';
    public const FRONTEND='{8D6AE8F7-EA3A-4513-AA14-C3F9613B0E83}';

    private static function origin($value): string
    {
        if (!is_string($value)) return '';$p=parse_url($value);
        if (!is_array($p)||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true)) return '';
        return 'https://'.strtolower($p['host']).((int)($p['port']??443)===443?'':':'.$p['port']);
    }
    public static function backend(int $id): int
    {
        if ($id<=0||!IPS_InstanceExists($id)||IPS_HasChanges($id)) return 0;
        $module=strtoupper(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'');
        if ($module===self::BACKEND) return IPS_GetProperty($id,'Enabled')===true?$id:0;
        if ($module!==self::OLD||!function_exists('SEC_GetReplacementInfo')) return 0;
        $info=json_decode(SEC_GetReplacementInfo($id),true,32,JSON_THROW_ON_ERROR);$backend=$info['backend']??0;
        if (($info['engine']??'')!=='native-only'||($info['ready']??false)!==true||!is_int($backend)||$backend<=0||$backend===$id) return 0;
        if (!IPS_InstanceExists($backend)||strtoupper(IPS_GetInstance($backend)['ModuleInfo']['ModuleID']??'')!==self::BACKEND) return 0;
        return self::backend($backend);
    }
    public static function eligible(int $id): bool
    {
        try {
            if ($id<=0||!IPS_InstanceExists($id)||IPS_HasChanges($id)) return false;
            $backend=self::backend($id);
            if ($backend) {
                if (!function_exists('SECB_IsNativePortalAuthenticated')||!function_exists('SECB_GetNativePortalLoginURL')) return false;
                $origin=self::origin(IPS_GetProperty($backend,'AdminOrigin'));$front=IPS_GetProperty($backend,'FrontendInstanceID');
                return $origin!==''&&is_int($front)&&$front>0&&IPS_InstanceExists($front)&&!IPS_HasChanges($front)
                    &&strtoupper(IPS_GetInstance($front)['ModuleInfo']['ModuleID']??'')===self::FRONTEND
                    &&IPS_GetProperty($front,'Enabled')===true&&IPS_GetProperty($front,'BackendInstanceID')===$backend&&self::origin(IPS_GetProperty($front,'AdminOrigin'))===$origin;
            }
            return !function_exists('SEC_GetReplacementInfo')&&function_exists('SEC_IsPortalAuthenticated')
                &&strtoupper(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'')===self::OLD&&IPS_GetProperty($id,'PortalEnabled')===true&&self::origin(IPS_GetProperty($id,'PortalOrigin'))!=='';
        } catch (Throwable $e) { return false; }
    }
    public static function resolve(int $selected): int
    {
        if ($selected>0) return self::eligible($selected)?$selected:0;
        $eligible=[];
        foreach (array_merge(IPS_GetInstanceListByModuleID(self::BACKEND),IPS_GetInstanceListByModuleID(self::OLD)) as $id) {
            if (!self::eligible((int)$id)) continue;$backend=self::backend((int)$id);$key=$backend?'native:'.$backend:'old:'.$id;
            if (!isset($eligible[$key])) $eligible[$key]=(int)$id;
        }return count($eligible)===1?array_values($eligible)[0]:0;
    }
    public static function login(int $vault,string $return,array $server): string
    {
        $backend=self::backend($vault);
        if (!$backend) return '/hook/secrets_'.$vault.'?portal=1&return='.rawurlencode($return);
        $context=array_intersect_key($server,array_flip(['HTTP_HOST','HTTP_COOKIE','REMOTE_ADDR','HTTPS','REQUEST_SCHEME','HTTP_X_FORWARDED_HOST','HTTP_X_FORWARDED_PROTO','HTTP_FORWARDED']));
        return SECB_GetNativePortalLoginURL($backend,$return,json_encode($context,JSON_THROW_ON_ERROR));
    }
    public static function authenticated(int $vault): bool
    {
        $backend=self::backend($vault);
        return ($backend?SECB_IsNativePortalAuthenticated($backend):SEC_IsPortalAuthenticated($vault))===true;
    }
}
