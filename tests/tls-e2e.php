<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tools/setup-example.php';

use Acme\Dpop\Protocol\Base64Url;
use DpopTest\Support\ProofFactory;

$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/dpop-tls-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);
$config=<<<'CONFIG'
[req]
distinguished_name=dn
prompt=no
[dn]
CN=api.example
[ca]
basicConstraints=critical,CA:true
keyUsage=critical,keyCertSign,cRLSign
[server]
basicConstraints=critical,CA:false
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:api.example
CONFIG;
file_put_contents($dir.'/openssl.cnf',$config);$options=['config'=>$dir.'/openssl.cnf','digest_alg'=>'sha256'];
$caKey=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$caCsr=openssl_csr_new(['commonName'=>'Isolated DPoP Test CA'],$caKey,$options);
$ca=openssl_csr_sign($caCsr,null,$caKey,1,$options+['x509_extensions'=>'ca']);
$serverKey=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$serverCsr=openssl_csr_new(['commonName'=>'api.example'],$serverKey,$options);
$certificate=openssl_csr_sign($serverCsr,$ca,$caKey,1,$options+['x509_extensions'=>'server']);
if(!$ca || !$certificate || !openssl_x509_export_to_file($ca,$dir.'/ca.pem') || !openssl_x509_export_to_file($certificate,$dir.'/server.pem') || !openssl_pkey_export_to_file($serverKey,$dir.'/server.key')){throw new RuntimeException('TLS fixture generation failed');}
chmod($dir.'/server.key',0600);
$env=array_replace(getenv(),['DPOP_EXAMPLE_TLS_CERT'=>$dir.'/server.pem','DPOP_EXAMPLE_TLS_KEY'=>$dir.'/server.key']);
$process=proc_open([PHP_BINARY,$root.'/examples/hyperf/bin/hyperf.php','start'],[0=>['pipe','r'],1=>['file',$root.'/build/review2-tls-server.log','w'],2=>['file',$root.'/build/review2-tls-server.log','a']],$pipes,$root,$env);
if(!is_resource($process)){throw new RuntimeException('TLS server unavailable');}fclose($pipes[0]);$cases=[];
$request=static function(string $method,string $path,array $headers=[],?array $body=null,bool $trusted=true,string $host='api.example')use($dir):array{
    $curl=curl_init('https://'.$host.':9510'.$path);$responseHeaders=[];$lines=['Content-Type: application/json','Connection: close'];foreach($headers as $name=>$value){$lines[]=$name.': '.$value;}
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$lines,CURLOPT_RESOLVE=>[$host.':9510:127.0.0.1'],CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT_MS=>500,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PATH_AS_IS=>true,CURLOPT_HEADERFUNCTION=>static function($curl,$line)use(&$responseHeaders){$colon=strpos($line,':');if($colon!==false){$responseHeaders[strtolower(substr($line,0,$colon))]=trim(substr($line,$colon+1));}return strlen($line);}]);
    if($trusted){curl_setopt($curl,CURLOPT_CAINFO,$dir.'/ca.pem');}if($body!==null){curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}
    $raw=curl_exec($curl);return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'curl_error'=>curl_errno($curl),'verify'=>curl_getinfo($curl,CURLINFO_SSL_VERIFYRESULT),'headers'=>$responseHeaders,'data'=>is_string($raw)?json_decode($raw,true):null];
};
$expect=static function(string $name,array $response,int $status,?string $error=null)use(&$cases):void{if($response['status']!==$status || ($error!==null && ($response['data']['error']??null)!==$error)){throw new RuntimeException('TLS case failed: '.$name.' HTTP '.$response['status']);}$cases[]=['case'=>$name,'status'=>$status,'result'=>'PASS'];};
try{
    $ready=false;$deadline=microtime(true)+30;while(microtime(true)<$deadline){$health=$request('GET','/health');if($health['status']===200){$ready=true;break;}if(!proc_get_status($process)['running']){break;}usleep(100000);}if(!$ready){throw new RuntimeException('TLS server not ready; see build/review2-tls-server.log');}
    $expect('trusted-ca-and-hostname',$health,200);if($health['verify']!==0){throw new RuntimeException('TLS was not verified');}
    // libcurl error 60: peer verification failed (PHP exposes different constant aliases across builds).
    $untrusted=$request('GET','/health',trusted:false);if($untrusted['curl_error']!==60){throw new RuntimeException('Untrusted CA unexpectedly allowed');}$expect('untrusted-ca-rejected',$untrusted,0);
    $wrongHost=$request('GET','/health',host:'wrong.example');if($wrongHost['curl_error']!==60){throw new RuntimeException('Wrong hostname unexpectedly allowed');}$expect('wrong-tls-hostname-rejected',$wrongHost,0);
    $f=new ProofFactory();$origin='https://api.example:9510';
    $challenge=$request('POST','/token',['DPoP'=>$f->make(['htm'=>'POST','htu'=>$origin.'/token'])],['subject'=>'fixture-tls']);$expect('tls-token-nonce',$challenge,400,'use_dpop_nonce');
    $token=$request('POST','/token',['DPoP'=>$f->make(['htm'=>'POST','htu'=>$origin.'/token','nonce'=>$challenge['headers']['dpop-nonce']])],['subject'=>'fixture-tls']);$expect('tls-token-issuance',$token,200);$access=$token['data']['access_token'];$ath=Base64Url::encode(hash('sha256',$access,true));
    $challenge=$request('GET','/items',['Authorization'=>'DPoP '.$access,'DPoP'=>$f->make(['htu'=>$origin.'/items','ath'=>$ath])]);$expect('tls-resource-nonce',$challenge,401,'use_dpop_nonce');$nonce=$challenge['headers']['dpop-nonce'];
    foreach(['/items'=>'/items','/groups/team%40chat'=>'/groups/team%40chat','/groups/team%2Fchat'=>'/groups/team%2Fchat','/groups/%7Eteam'=>'/groups/~team'] as $path=>$signedPath){$response=$request('GET',$path,['Authorization'=>'DPoP '.$access,'DPoP'=>$f->make(['htu'=>$origin.$signedPath,'nonce'=>$nonce,'ath'=>$ath])]);$expect('tls-path-'.$path,$response,200);}
    $response=$request('GET','/groups/team%40chat',['Authorization'=>'DPoP '.$access,'DPoP'=>$f->make(['htu'=>$origin.'/groups/team@chat','nonce'=>$nonce,'ath'=>$ath])]);$expect('reserved-encoding-not-equivalent',$response,401,'invalid_dpop_proof');
    $proof=$f->make(['htu'=>$origin.'/items','nonce'=>$nonce,'ath'=>$ath]);$multi=curl_multi_init();$handles=[];
    for($i=0;$i<12;++$i){$curl=curl_init($origin.'/items');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_RESOLVE=>['api.example:9510:127.0.0.1'],CURLOPT_PROXY=>'',CURLOPT_CAINFO=>$dir.'/ca.pem',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Connection: close','Authorization: DPoP '.$access,'DPoP: '.$proof]]);curl_multi_add_handle($multi,$curl);$handles[]=$curl;}
    do{$status=curl_multi_exec($multi,$running);if($running){curl_multi_select($multi,0.1);}}while($running && $status===CURLM_OK);
    $accepted=0;$rejected=0;foreach($handles as $curl){$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$data=json_decode(curl_multi_getcontent($curl),true);if($code===200){++$accepted;}elseif($code===401 && ($data['error']??null)==='invalid_dpop_proof'){++$rejected;}else{throw new RuntimeException('Unexpected TLS replay-race outcome');}curl_multi_remove_handle($multi,$curl);}
    if($accepted!==1 || $rejected!==11){throw new RuntimeException('Concurrent TLS proof replay accepted more than once');}$cases[]=['case'=>'tls-multiworker-proof-race','accepted'=>$accepted,'rejected'=>$rejected,'result'=>'PASS'];
    file_put_contents($root.'/build/review2-tls-result.json',json_encode(['cases'=>$cases,'tls_peer_verification'=>true,'tls_hostname_verification'=>true,'global_trust_store_changed'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo count($cases)." verified TLS scenarios passed; reserved paths preserved; same proof 12-way race: 1 accepted, 11 rejected\n";
}finally{
    proc_terminate($process,SIGTERM);$deadline=microtime(true)+5;do{if(!proc_get_status($process)['running']){break;}usleep(100000);}while(microtime(true)<$deadline);if(proc_get_status($process)['running']){proc_terminate($process,SIGKILL);}proc_close($process);
}
