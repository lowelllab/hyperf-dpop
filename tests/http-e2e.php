<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Acme\Dpop\Protocol\Base64Url;
use DpopTest\Support\ProofFactory;

$root=dirname(__DIR__);
require $root.'/tools/setup-example.php';
$server=proc_open([PHP_BINARY,$root.'/examples/hyperf/bin/hyperf.php','start'],[0=>['pipe','r'],1=>['file',$root.'/build/http-server.log','a'],2=>['file',$root.'/build/http-server.log','a']],$pipes,$root);
if(!is_resource($server)){throw new RuntimeException('Could not start example');}
fclose($pipes[0]);
$cases=[];$nonces=[];

/** No token, proof, response body or nonce is printed or written to evidence. */
function httpRequest(string $method,string $path,array $headers=[],?array $body=null): array
{
    $curl=curl_init('http://127.0.0.1:9509'.$path);$responseHeaders=[];
    $headerLines=['Content-Type: application/json','Connection: close'];foreach($headers as $key=>$value){$headerLines[]=$key.': '.$value;}
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headerLines,CURLOPT_CONNECTTIMEOUT_MS=>500,CURLOPT_TIMEOUT_MS=>5000,CURLOPT_HEADERFUNCTION=>static function($curl,$line)use(&$responseHeaders){$colon=strpos($line,':');if($colon!==false){$responseHeaders[strtolower(substr($line,0,$colon))]=trim(substr($line,$colon+1));}return strlen($line);}]);
    if($body!==null){curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}
    $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    return ['status'=>$status,'headers'=>$responseHeaders,'data'=>is_string($raw)?json_decode($raw,true):null];
}

function expectCase(string $name,array $response,int $status,?string $error=null): void
{
    global $cases;
    if($response['status']!==$status || ($error!==null && ($response['data']['error']??null)!==$error)){throw new RuntimeException($name.' failed: HTTP '.$response['status'].' (expected '.$status.')');}
    $cases[]=['case'=>$name,'status'=>$status,'result'=>'PASS'];
}

function callDpop(ProofFactory $factory,string $method,string $path,?string $token=null,?array $body=null,array $changes=[],bool $retryNonce=true,string $scheme='DPoP'): array
{
    global $nonces;
    $purpose=$token===null?'token':'resource';$cacheKey=$factory->jkt().':'.$purpose;
    for($attempt=0;$attempt<2;++$attempt){
        $claims=['htm'=>$method,'htu'=>'https://api.example'.explode('?',$path,2)[0]];
        if(isset($nonces[$cacheKey])){$claims['nonce']=$nonces[$cacheKey];}
        if($token!==null){$claims['ath']=Base64Url::encode(hash('sha256',$token,true));}
        $proof=$factory->make(array_replace($claims,$changes));$headers=['DPoP'=>$proof];if($token!==null){$headers['Authorization']=$scheme.' '.$token;}
        $response=httpRequest($method,$path,$headers,$body);$response['sent_proof']=$proof;
        if($attempt===0 && $retryNonce && ($response['data']['error']??null)==='use_dpop_nonce' && isset($response['headers']['dpop-nonce'])){$nonces[$cacheKey]=$response['headers']['dpop-nonce'];continue;}
        return $response;
    }
    throw new RuntimeException('Unexpected nonce loop');
}

try{
    $ready=false;$deadline=microtime(true)+30;
    while(microtime(true)<$deadline){if(httpRequest('GET','/health')['status']===200){$ready=true;break;}if(!proc_get_status($server)['running']){break;}usleep(100000);}
    if(!$ready){throw new RuntimeException('Hyperf did not become ready; see build/http-server.log');}
    $unauth=httpRequest('GET','/items');expectCase('unauthenticated-resource-challenge',$unauth,401);
    if(!str_contains($unauth['headers']['www-authenticate']??'','algs="ES256"')){throw new RuntimeException('Missing algorithm challenge');}
    $f=new ProofFactory();$other=new ProofFactory();
    $initial=httpRequest('POST','/token',['DPoP'=>$f->make(['htm'=>'POST','htu'=>'https://api.example/token'])],['subject'=>'fixture-main']);expectCase('token-nonce-before-issuance',$initial,400,'use_dpop_nonce');
    $issued=callDpop($f,'POST','/token',body:['subject'=>'fixture-main']);expectCase('opaque-token-issuance',$issued,200);$access=$issued['data']['access_token'];$refresh=$issued['data']['refresh_token'];
    $resource=callDpop($f,'GET','/items?limit=5',$access);expectCase('opaque-resource-and-query',$resource,200);
    expectCase('proof-replay',httpRequest('GET','/items',['Authorization'=>'DPoP '.$access,'DPoP'=>$resource['sent_proof']]),401,'invalid_dpop_proof');
    expectCase('wrong-client-key',callDpop($other,'GET','/items',$access),401,'invalid_token');
    expectCase('method-case-sensitive',callDpop($f,'GET','/items',$access,changes:['htm'=>'get']),401,'invalid_dpop_proof');
    expectCase('path-case-sensitive',callDpop($f,'GET','/Items',$access,changes:['htu'=>'https://api.example/items']),401,'invalid_dpop_proof');
    expectCase('wrong-ath',callDpop($f,'GET','/items',$access,changes:['ath'=>'wrong']),401,'invalid_dpop_proof');
    expectCase('invalid-nonce',callDpop($f,'GET','/items',$access,changes:['nonce'=>'stale'],retryNonce:false),401,'use_dpop_nonce');
    expectCase('cross-purpose-nonce',callDpop($f,'GET','/items',$access,changes:['nonce'=>$nonces[$f->jkt().':token']],retryNonce:false),401,'use_dpop_nonce');
    expectCase('bearer-downgrade',callDpop($f,'GET','/items',$access,scheme:'Bearer'),401,'invalid_token');
    expectCase('refresh-wrong-key',callDpop($other,'POST','/refresh',body:['refresh_token'=>$refresh]),400,'invalid_dpop_proof');
    $rotated=callDpop($f,'POST','/refresh',body:['refresh_token'=>$refresh]);expectCase('same-key-refresh',$rotated,200);$next=$rotated['data']['access_token'];
    expectCase('old-access-invalidated',callDpop($f,'GET','/items',$access),401,'invalid_token');
    expectCase('old-refresh-invalidated',callDpop($f,'POST','/refresh',body:['refresh_token'=>$refresh]),400,'invalid_grant');
    expectCase('rotated-access',callDpop($f,'GET','/items',$next),200);
    $jwt=callDpop($f,'POST','/jwt/token');expectCase('jwt-issuance',$jwt,200);$jwtAccess=$jwt['data']['access_token'];
    expectCase('jwt-resource',callDpop($f,'GET','/jwt/items',$jwtAccess),200);
    $pieces=explode('.',$jwtAccess);$signature=Base64Url::decode($pieces[2]);$signature[0]=chr(ord($signature[0])^1);$pieces[2]=Base64Url::encode($signature);
    expectCase('jwt-signature-forgery',callDpop($f,'GET','/jwt/items',implode('.',$pieces)),401,'invalid_token');
    expectCase('revoke',callDpop($f,'POST','/revoke',$next,[]),200);
    expectCase('revoked-access',callDpop($f,'GET','/items',$next),401,'invalid_token');

    $clients=[];
    foreach(['a','b'] as $suffix){$client=new ProofFactory();$session=callDpop($client,'POST','/token',body:['subject'=>'fixture-parallel-'.$suffix]);expectCase('parallel-session-'.$suffix,$session,200);$token=$session['data']['access_token'];expectCase('parallel-prime-'.$suffix,callDpop($client,'GET','/items',$token),200);$clients[]=[$client,$token,'fixture-parallel-'.$suffix];}
    $multi=curl_multi_init();$handles=[];$started=microtime(true);
    for($i=0;$i<32;++$i){[$client,$token,$subject]=$clients[$i%2];$proof=$client->make(['ath'=>Base64Url::encode(hash('sha256',$token,true)),'nonce'=>$nonces[$client->jkt().':resource']]);$curl=curl_init('http://127.0.0.1:9509/items');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Connection: close','Authorization: DPoP '.$token,'DPoP: '.$proof]]);curl_multi_add_handle($multi,$curl);$handles[]=[$curl,$subject];}
    do{$result=curl_multi_exec($multi,$running);if($running){curl_multi_select($multi,0.1);}}while($running && $result===CURLM_OK);
    $workers=[];$durations=[];
    foreach($handles as [$curl,$subject]){$data=json_decode(curl_multi_getcontent($curl),true);if(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200 || ($data['subject']??null)!==$subject){throw new RuntimeException('Concurrent subject isolation failed');}$workers[$data['worker']]=true;$durations[]=curl_getinfo($curl,CURLINFO_TOTAL_TIME)*1000;curl_multi_remove_handle($multi,$curl);}
    if(count($workers)<2){throw new RuntimeException('Both Hyperf workers were not exercised');}
    sort($durations);$observation=['requests'=>32,'workers'=>count($workers),'elapsed_seconds'=>round(microtime(true)-$started,3),'p50_ms'=>round($durations[15],2),'p95_ms'=>round($durations[30],2),'meaning'=>'local concurrency smoke observation, not production capacity certification'];
    $cases[]=['case'=>'multiworker-coroutine-subject-isolation','result'=>'PASS'];
    $report=['date'=>'2026-11-02','php'=>PHP_VERSION,'swoole'=>phpversion('swoole'),'cases'=>$cases,'observation'=>$observation];
    file_put_contents($root.'/build/http-e2e-result.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    echo count($cases)." HTTP scenarios passed; 32 concurrent requests used ".count($workers)." workers with isolated identities\n";
    echo json_encode($observation,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}finally{
    proc_terminate($server,SIGTERM);$deadline=microtime(true)+5;
    do{$state=proc_get_status($server);if(!$state['running']){break;}usleep(100000);}while(microtime(true)<$deadline);
    if(proc_get_status($server)['running']){proc_terminate($server,SIGKILL);}proc_close($server);
}
