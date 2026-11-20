<?php
declare(strict_types=1);

if (!class_exists('RemoteSyncConnectionV2', false)) {
final class RemoteSyncConnectionV2
{
    public static function credentials(string $json): array
    {
        $c=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($c)) throw new RuntimeException('Connection secret must be a JSON object.');
        $url=$c['URL']??$c['url']??$c['Url']??'';
        $user=$c['User']??$c['user']??$c['Username']??'';
        $pass=$c['Pass']??$c['PW']??$c['pw']??$c['Password']??'';
        if (!is_string($url)||!is_string($user)||!is_string($pass)||$user===''||$pass==='') throw new RuntimeException('Connection secret requires URL, User and Pass (or PW/Password).');
        $url=trim($url);if (!preg_match('~^[a-z][a-z0-9+.-]*://~i',$url)) $url='https://'.$url;
        $p=parse_url($url);
        if (!is_array($p)||strtolower($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array(rtrim($p['path']??'','/'),['','/api'],true)) throw new RuntimeException('Use an HTTPS host with optional port and /api/ path; no embedded credentials or query.');
        $url='https://'.$p['host'].(isset($p['port'])?':'.$p['port']:'').'/api/';
        return ['url'=>$url,'user'=>$user,'pass'=>$pass];
    }
    public static function call(array $connection,string $method,array $params,int $timeout=120,?callable $count=null)
    {
        $id = random_int(1, 2147483647);
        $body=json_encode(['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params],JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        $ch=curl_init($connection['url']);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_USERPWD=>$connection['user'].':'.$connection['pass'],
            CURLOPT_TIMEOUT=>max(30,min(600,$timeout)),CURLOPT_CONNECTTIMEOUT=>30,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
        try {
            if ($count) $count(strlen($body));
            $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);
            if ($raw===false) throw new RuntimeException('Remote transport failed (cURL '.$errno.'). Check reachability and the HTTPS certificate. Outcome may be unknown.');
            if ($status!==200) throw new RuntimeException('Remote endpoint returned HTTP '.$status.'.');
            try {$r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('Remote endpoint returned invalid JSON.');}
            if (!is_array($r)||($r['jsonrpc']??'')!=='2.0'||($r['id']??null)!==$id||array_key_exists('result',$r)===array_key_exists('error',$r)) throw new RuntimeException('Invalid JSON-RPC response envelope.');
            if (array_key_exists('error',$r)) throw new RuntimeException('Remote API rejected '.$method.' (code '.(int)($r['error']['code']??0).').',(int)($r['error']['code']??0));
            return $r['result'];
        } finally {curl_close($ch);}
    }
}
}
