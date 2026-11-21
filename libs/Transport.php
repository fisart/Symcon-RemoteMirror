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
        $temporaryCAFile = null;
        try {
            if ($count) $count(strlen($body));
            $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);

            // Retry only an issuer failure during TLS, before cURL sent any HTTP request bytes.
            if ($raw === false && $errno === 60 && $status === 0 && function_exists('openssl_x509_checkpurpose')
                && (int)curl_getinfo($ch, CURLINFO_REQUEST_SIZE) === 0
                && in_array((int)curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT), [2, 20, 21], true)) {
                $completeCAFile = static function ($handle) use (&$temporaryCAFile): bool {
                    // Match PHP cURL's configured CA file precedence.
                    $caFile = (string)ini_get('openssl.cafile');
                    if ($caFile === '') $caFile = (string)ini_get('curl.cainfo');
                    if ($caFile === '' && defined('CURLINFO_CAINFO')) {
                        $caFile = (string)curl_getinfo($handle, CURLINFO_CAINFO);
                    }
                    if ($caFile === '' || !is_file($caFile)) return false;
                    $roots = @file_get_contents($caFile, false, null, 0, 2097153);
                    if (!is_string($roots) || $roots === '' || strlen($roots) > 2097152) return false;

                    $issuerFile = __DIR__ . '/certificates/letsencrypt-issuers.pem';
                    $issuerPEM = @file_get_contents($issuerFile, false, null, 0, 65537);
                    if (!is_string($issuerPEM) || strlen($issuerPEM) > 65536) return false;
                    $issuerPEM = str_replace("\r\n", "\n", $issuerPEM);
                    $expectedHash = '3b91762c45be2dbc4a5fe358b03ce40df2541c6c857fc8329c4a6dc4c3f09d7e';
                    if (!hash_equals($expectedHash, hash('sha256', $issuerPEM))) return false;

                    $tempFile = @tempnam(sys_get_temp_dir(), 'rs-ca-');
                    if ($tempFile === false) return false;
                    $temporaryCAFile = $tempFile;
                    if (@file_put_contents($tempFile, $roots) !== strlen($roots)) return false;

                    preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $issuerPEM, $matches);
                    $verifiedIssuers = [];
                    foreach ($matches[0] as $pem) {
                        $cert = @openssl_x509_parse($pem);
                        // Only allow CA certificates that chain to the same root snapshot used by the retry.
                        if (str_contains($cert['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
                            && @openssl_x509_checkpurpose($pem, X509_PURPOSE_ANY, [$tempFile], $issuerFile) === true) {
                            $verifiedIssuers[] = $pem;
                        }
                    }
                    if ($verifiedIssuers === []) return false;
                    $intermediates = "\n" . implode("\n", $verifiedIssuers) . "\n";
                    return @file_put_contents($tempFile, $intermediates, FILE_APPEND) === strlen($intermediates);
                };

                if ($completeCAFile($ch) && curl_setopt($ch, CURLOPT_CAINFO, $temporaryCAFile)) {
                    if ($count) $count(strlen($body));
                    $raw = curl_exec($ch);
                    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno = curl_errno($ch);
                }
            }

            if ($raw===false) throw new RuntimeException('Remote transport failed (cURL '.$errno.'). Check reachability and the HTTPS certificate. Outcome may be unknown.');
            if ($status!==200) throw new RuntimeException('Remote endpoint returned HTTP '.$status.'.');
            try {$r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('Remote endpoint returned invalid JSON.');}
            if (!is_array($r)||($r['jsonrpc']??'')!=='2.0'||($r['id']??null)!==$id||array_key_exists('result',$r)===array_key_exists('error',$r)) throw new RuntimeException('Invalid JSON-RPC response envelope.');
            if (array_key_exists('error',$r)) throw new RuntimeException('Remote API rejected '.$method.' (code '.(int)($r['error']['code']??0).').',(int)($r['error']['code']??0));
            return $r['result'];
        } finally {
            curl_close($ch);
            if ($temporaryCAFile !== null) @unlink($temporaryCAFile);
        }
    }
}
}
