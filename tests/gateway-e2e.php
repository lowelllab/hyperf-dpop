<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Acme\Dpop\Protocol\Base64Url;
use DpopTest\Support\{Environment, ProofFactory};

$root = dirname(__DIR__);
$dir = $root . '/build/gateway';
$origin = 'https://api.example:9511';
$action = $argv[1] ?? 'client';

if ($action === 'serve') {
    require $root . '/tools/setup-example.php';
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    $config = "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=api.example\n[ca]\nbasicConstraints=critical,CA:true\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:api.example\n";
    file_put_contents($dir . '/openssl.cnf', $config);
    $options = ['config' => $dir . '/openssl.cnf', 'digest_alg' => 'sha256'];
    $caKey = openssl_pkey_new(['private_key_bits' => 2048]);
    $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'Gateway fixture CA'], $caKey, $options), null, $caKey, 1, $options + ['x509_extensions' => 'ca']);
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'api.example'], $key, $options), $ca, $caKey, 1, $options + ['x509_extensions' => 'server']);
    if (!$cert || !openssl_x509_export_to_file($ca, $dir . '/ca.pem') || !openssl_x509_export_to_file($cert, $dir . '/server.pem') || !openssl_pkey_export_to_file($key, $dir . '/server.key')) {
        throw new RuntimeException('Cannot create temporary gateway TLS fixture');
    }
    file_put_contents($dir . '/tls.pem', file_get_contents($dir . '/server.pem') . file_get_contents($dir . '/server.key'));
    chmod($dir . '/tls.pem', 0600); chmod($dir . '/server.key', 0600);
    $nativeCode = <<<'PHP'
require '/workspace/vendor/autoload.php';
$s = new Swoole\Http\Server('127.0.0.1', 9512, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
$s->set(['worker_num'=>1,'log_file'=>'/dev/null','open_http2_protocol'=>true,'ssl_cert_file'=>'/workspace/build/gateway/server.pem','ssl_key_file'=>'/workspace/build/gateway/server.key']);
$s->on('request', static function($native, $response) {
    $request = Hyperf\HttpMessage\Server\Request::loadFromSwooleRequest($native);
    if (($native->server['request_uri'] ?? '') === '/guard-proxy') {
        Hyperf\Context\RequestContext::set($request);
        $request = new Hyperf\HttpServer\Request();
    }
    try {
        Acme\HyperfDpop\Http\HeaderReader::access($request);
        Acme\HyperfDpop\Http\HeaderReader::proof($request, 8192);
        $response->end('{"ok":true}');
    } catch (Acme\Dpop\Exception\DpopException $error) {
        $response->status($error->error === 'dpop_security_unavailable' ? 503 : 400);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['error'=>$error->error]));
    }
}); $s->start();
PHP;
    $children = [];
    foreach ([[PHP_BINARY, '-r', $nativeCode], [PHP_BINARY, $root . '/examples/hyperf/bin/hyperf.php', 'start']] as $command) {
        $process = proc_open($command, [0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']], $pipes, $root);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start gateway fixture'); }
        fclose($pipes[0]); $children[] = $process;
    }
    $running = true;
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
    try { while ($running) { foreach ($children as $child) { if (!proc_get_status($child)['running']) { throw new RuntimeException('Fixture child stopped'); } } usleep(200000); } }
    finally { foreach ($children as $child) { proc_terminate($child); proc_close($child); } }
    exit;
}

$cases = []; $observations = [];
$check = static function (string $name, bool $ok, array $details = []) use (&$cases): void {
    $cases[] = ['case'=>$name, 'result'=>$ok?'PASS':'FAIL'] + $details;
    if (!$ok) { throw new RuntimeException('Gateway case failed: ' . $name . ' ' . json_encode($details)); }
};
$request = static function (string $method, string $path, array $headers, ?array $body, int $protocol, int $port = 9511) use ($dir): array {
    $curl = curl_init('https://api.example:' . $port . $path); $out = [];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTP_VERSION=>$protocol,CURLOPT_RESOLVE=>['api.example:'.$port.':127.0.0.1'],CURLOPT_PROXY=>'',CURLOPT_CAINFO=>$dir.'/ca.pem',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_TIMEOUT=>8,CURLOPT_PATH_AS_IS=>true,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'],$headers),CURLOPT_HEADERFUNCTION=>static function($curl,$line)use(&$out){$colon=strpos($line,':');if($colon!==false){$out[strtolower(substr($line,0,$colon))]=trim(substr($line,$colon+1));}return strlen($line);}]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
    $raw = curl_exec($curl);
    return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'protocol'=>curl_getinfo($curl,CURLINFO_HTTP_VERSION),'curl_error'=>curl_errno($curl),'verify'=>curl_getinfo($curl,CURLINFO_SSL_VERIFYRESULT),'headers'=>$out,'data'=>is_string($raw)?json_decode($raw,true):null];
};
$expect = static function (string $name, array $response, int $status, int $protocol, ?string $error = null) use ($check): void {
    $check($name, $response['status']===$status && $response['protocol']===$protocol && $response['curl_error']===0 && $response['verify']===0 && ($error===null || ($response['data']['error']??null)===$error), ['status'=>$response['status'],'expected'=>$status,'negotiated_http_version'=>$response['protocol'],'expected_http_version'=>$protocol,'error'=>$response['data']['error']??null]);
};
$rawRequest = static function (string $path, array $headers, string $trailers, string $body = '{}', int $port = 9511) use ($dir): array {
    $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'api.example','cafile'=>$dir.'/ca.pem','alpn_protocols'=>'http/1.1']]);
    $socket = stream_socket_client('tls://127.0.0.1:'.$port,$errno,$error,3,STREAM_CLIENT_CONNECT,$context);
    if (!is_resource($socket)) { throw new RuntimeException('Raw TLS client unavailable'); }
    stream_set_timeout($socket, 8);
    $headers = array_merge(['Host: api.example:9511','Connection: close','Transfer-Encoding: chunked'], $headers);
    $extension = $port === 9512 ? '' : ';fixture=yes';
    $raw = "POST ".$path." HTTP/1.1\r\n".implode("\r\n",$headers)."\r\n\r\n".dechex(strlen($body)).$extension."\r\n".$body."\r\n0\r\n".$trailers."\r\n";
    for ($offset=0,$length=strlen($raw);$offset<$length;) { $written=fwrite($socket,substr($raw,$offset));if(!$written){throw new RuntimeException('Raw request write failed');}$offset+=$written; }
    $response = stream_get_contents($socket);$metadata=stream_get_meta_data($socket);fclose($socket);
    $parts = explode("\r\n\r\n",$response,2);preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $parts[0], $status);
    return ['status'=>(int)($status[1]??0),'protocol'=>CURL_HTTP_VERSION_1_1,'curl_error'=>0,'verify'=>0,'data'=>json_decode($parts[1]??'',true),'timed_out'=>$metadata['timed_out'],'eof'=>$metadata['eof'],'response_bytes'=>strlen($response)];
};
$failure = null;
try {
    $ready = false; $deadline = microtime(true) + 40;
    do { $health=$request('GET','/health',[],null,CURL_HTTP_VERSION_1_1); if($health['status']===200){$ready=true;break;} usleep(100000); } while(microtime(true)<$deadline);
    $check('gateway-ready', $ready);
    foreach (['http1'=>CURL_HTTP_VERSION_1_1, 'http2'=>CURL_HTTP_VERSION_2_0] as $label=>$protocol) {
        $f = new ProofFactory();
        $tokenProof = fn(?string $nonce=null, string $path='/token') => $f->make(array_filter(['htm'=>'POST','htu'=>$origin.$path,'nonce'=>$nonce],static fn($v)=>$v!==null));
        $challenge=$request('POST','/token',['DPoP: '.$tokenProof()],['subject'=>'fixture-gateway'],$protocol);
        $expect($label.'-token-nonce',$challenge,400,$protocol,'use_dpop_nonce'); $tokenNonce=$challenge['headers']['dpop-nonce'];
        $issued=$request('POST','/token',['DPoP: '.$tokenProof($tokenNonce)],['subject'=>'fixture-gateway'],$protocol);
        $expect($label.'-issuance',$issued,200,$protocol);$access=$issued['data']['access_token'];$refresh=$issued['data']['refresh_token'];
        $ath=Base64Url::encode(hash('sha256',$access,true));
        $resourceProof=fn(?string $nonce=null)=>$f->make(array_filter(['htu'=>$origin.'/items','ath'=>$ath,'nonce'=>$nonce],static fn($v)=>$v!==null));
        $challenge=$request('GET','/items',['Authorization: DPoP '.$access,'DPoP: '.$resourceProof()],null,$protocol);
        $expect($label.'-resource-nonce',$challenge,401,$protocol,'use_dpop_nonce');$nonce=$challenge['headers']['dpop-nonce'];
        $good=$resourceProof($nonce);
        $before=(int)Environment::pdo()->query('SELECT COUNT(*) FROM example_sessions')->fetchColumn();
        foreach (['resource'=>['GET','/items',null,$good], 'token'=>['POST','/token',['subject'=>'fixture-duplicate'],$tokenProof($tokenNonce)], 'refresh'=>['POST','/refresh',['refresh_token'=>$refresh],$tokenProof($tokenNonce,'/refresh')]] as $scope=>[$method,$path,$body,$proof]) {
            foreach ([
                'auth-same'=>['Authorization: DPoP '.$access,'Authorization: DPoP '.$access,'DPoP: '.$proof],
                'auth-valid-last'=>['Authorization: DPoP bogus','Authorization: DPoP '.$access,'DPoP: '.$proof],
                'auth-valid-first'=>['Authorization: DPoP '.$access,'Authorization: DPoP bogus','DPoP: '.$proof],
                'auth-case'=>['authorization: DPoP '.$access,'AUTHORIZATION: DPoP '.$access,'DPoP: '.$proof],
                'auth-empty'=>['Authorization;','Authorization: DPoP '.$access,'DPoP: '.$proof],
                'auth-comma'=>['Authorization: DPoP '.$access.',DPoP '.$access,'DPoP: '.$proof],
                'proof-same'=>['Authorization: DPoP '.$access,'DPoP: '.$proof,'DPoP: '.$proof],
                'proof-valid-last'=>['Authorization: DPoP '.$access,'DPoP: bad','DPoP: '.$proof],
                'proof-valid-first'=>['Authorization: DPoP '.$access,'DPoP: '.$proof,'DPoP: bad'],
                'proof-case'=>['Authorization: DPoP '.$access,'dpop: '.$proof,'DPoP: '.$proof],
                'proof-empty'=>['Authorization: DPoP '.$access,'DPoP;','DPoP: '.$proof],
                'proof-comma'=>['Authorization: DPoP '.$access,'DPoP: '.$proof.','.$proof],
            ] as $name=>$headers) {
                $r=$request($method,$path,$headers,$body,$protocol);
                $expect($label.'-'.$scope.'-'.$name,$r,400,$protocol,'invalid_request');
                $check($label.'-'.$scope.'-'.$name.'-no-store',($r['headers']['cache-control']??'')==='no-store');
            }
        }
        $check($label.'-duplicate-token-no-issuance',(int)Environment::pdo()->query('SELECT COUNT(*) FROM example_sessions')->fetchColumn()===$before);
        if ($label === 'http1') {
            $wireProof=$f->make(['htm'=>'POST','htu'=>$origin.'/items','ath'=>$ath,'nonce'=>$nonce]);
            $wire=$rawRequest('/items',['Authorization: DPoP bogus','DPoP: '.$wireProof],'Authorization: DPoP '.$access."\r\n");
            $expect('http1-hidden-auth-cannot-replace-initial',$wire,401,$protocol,'invalid_token');
            $wire=$rawRequest('/items',['Authorization: DPoP '.$access,'DPoP: '.$wireProof],'Authorization: DPoP bogus'."\r\n".'DPoP: bad'."\r\n");
            $expect('http1-initial-auth-and-proof-preserved',$wire,200,$protocol);
            $wire=$rawRequest('/items',[], 'Authorization: DPoP '.$access."\r\n".'DPoP: '.$wireProof."\r\n");
            $expect('http1-trailer-only-credentials-rejected',$wire,401,$protocol);
            $wire=$rawRequest('/items',['Authorization: DPoP '.$access,'DPoP: '.$resourceProof($nonce),'Trailer: X-Checksum, aUtHoRiZaTiOn'],'Authorization: DPoP '.$access."\r\n");
            $expect('http1-forbidden-trailer-declaration',$wire,400,$protocol,'invalid_request');
            $wire=$rawRequest('/guard',['Authorization: DPoP first','DPoP: a.b.c'],'Authorization: DPoP last'."\r\n",port:9512);
            if ($wire['status']===0 && $wire['timed_out']) {
                // Swoole's network framing can wait before invoking the PHP
                // handler. This is not an authentication PASS or a 400 result.
                $observations[] = ['case'=>'native-http1-trailer-framing','result'=>'LIMITATION','status'=>0,'timed_out'=>true,'mitigation'=>'gateway drops trailers; backend must not be publicly reachable'];
            } else { $expect('native-http1-hidden-trailer-rejected',$wire,400,$protocol,'invalid_request'); }
            $expect('native-http1-alive-after-forbidden-trailer',$request('GET','/guard',['Authorization: DPoP fixture','DPoP: a.b.c'],null,$protocol,9512),200,$protocol);
        }
        $headers=['Authorization: DPoP '.$access,'DPoP: '.$good];
        $expect($label.'-rejected-proof-still-usable',$request('GET','/items',$headers,null,$protocol),200,$protocol);
        $expect($label.'-successful-proof-replay',$request('GET','/items',$headers,null,$protocol),401,$protocol,'invalid_dpop_proof');
        $rotated=$request('POST','/refresh',['DPoP: '.$tokenProof($tokenNonce,'/refresh')],['refresh_token'=>$refresh],$protocol);
        $expect($label.'-refresh-after-rejections',$rotated,200,$protocol);
        $next=$rotated['data']['access_token'];$nextProof=$f->make(['htu'=>$origin.'/items','ath'=>Base64Url::encode(hash('sha256',$next,true)),'nonce'=>$nonce]);
        $result=$request('GET','/items',['Authorization: DPoP '.$next,'DPoP: '.$nextProof],null,$protocol);
        $expect($label.'-rotated-resource',$result,200,$protocol);
        $check($label.'-subject-preserved',($result['data']['subject']??null)==='fixture-gateway');
        $oldProof=$resourceProof($nonce);$expect($label.'-old-access-invalid',$request('GET','/items',['Authorization: DPoP '.$access,'DPoP: '.$oldProof],null,$protocol),401,$protocol,'invalid_token');
        $nativeHeaders=['Authorization: DPoP fixture','DPoP: a.b.c'];
        $native=$request('GET','/guard',$nativeHeaders,null,$protocol,9512);
        $expect($label.'-native-single',$native,$label==='http1'?200:503,$protocol,$label==='http1'?null:'dpop_security_unavailable');
        foreach (['auth'=>['Authorization: DPoP first','Authorization: DPoP last','DPoP: a.b.c'],'proof'=>['Authorization: DPoP fixture','DPoP: a.b.c','DPoP: a.b.c']] as $name=>$duplicate) {
            $expect($label.'-native-duplicate-'.$name,$request('GET','/guard',$duplicate,null,$protocol,9512),$label==='http1'?400:503,$protocol,$label==='http1'?'invalid_request':'dpop_security_unavailable');
        }
        $expect($label.'-native-proxy-single',$request('GET','/guard-proxy',$nativeHeaders,null,$protocol,9512),$label==='http1'?200:503,$protocol,$label==='http1'?null:'dpop_security_unavailable');
        $expect($label.'-native-proxy-duplicate',$request('GET','/guard-proxy',['Authorization: DPoP first','Authorization: DPoP last','DPoP: a.b.c'],null,$protocol,9512),$label==='http1'?400:503,$protocol,$label==='http1'?'invalid_request':'dpop_security_unavailable');
    }
    echo count($cases) . " gateway/native checks passed with explicit HTTP/1.1 and HTTP/2; " . count($observations) . " native runtime observations (not PASS)\n";
} catch (Throwable $e) { $failure=$e; fwrite(STDERR,$e->getMessage()."\n"); }
finally { file_put_contents($dir.'/result.json',json_encode(['cases'=>$cases,'observations'=>$observations,'failure'=>$failure?->getMessage(),'gateway_config_sha256'=>hash_file('sha256',$root.'/packages/hyperf-dpop/publish/haproxy.cfg'),'backend_protocol'=>'HTTP/1.1','scope'=>'isolated actual Hyperf + HAProxy; no production deployment'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)); }
if ($failure !== null) { exit(1); }
