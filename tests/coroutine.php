<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Acme\Dpop\{Authenticator, SystemClock};
use Acme\Dpop\Nonce\HmacNonceManager;
use Acme\Dpop\Protocol\{ProofVerifier, Base64Url};
use Acme\Dpop\ValueObject\{SecurityPolicy, TokenBinding};
use Acme\HyperfDpop\Contract\TokenBindingResolverInterface;
use Acme\HyperfDpop\Http\{ExternalUriResolver, ClientSource, ErrorResponseFactory};
use Acme\HyperfDpop\Middleware\DpopMiddleware;
use Acme\HyperfDpop\AuthenticationObserver;
use DpopTest\Support\{ProofFactory, MemoryReplay, TrackingBudget};
use Hyperf\HttpMessage\Server\{Request, Response};
use Hyperf\HttpMessage\Uri\Uri;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Psr\Http\Server\RequestHandlerInterface;

Swoole\Coroutine\run(function():void{
    $ready=new Swoole\Coroutine\Channel(1);$done=new Swoole\Coroutine\Channel(1);$factory=new ProofFactory();$budget=new TrackingBudget();$policy=new SecurityPolicy();
    $resolver=new class($factory->jkt(),$ready) implements TokenBindingResolverInterface{
        public function __construct(private string $jkt,private Swoole\Coroutine\Channel $ready){}
        public function resolve(string $accessToken,ServerRequestInterface $request):TokenBinding{
            $this->ready->push(true);
            @Swoole\Coroutine::sleep(10); // Expected cancellation, not an ignored authentication failure.
            return new TokenBinding($this->jkt,'cancelled-fixture');
        }
    };
    $auth=new Authenticator($policy,new ProofVerifier($policy),new HmacNonceManager(str_repeat('n',32)),new MemoryReplay(),new SystemClock(),'test:api');
    $middleware=new DpopMiddleware($auth,$policy,$resolver,new ExternalUriResolver('https://api.example'),new ClientSource(),$budget,new ErrorResponseFactory(),new AuthenticationObserver());
    $handler=new class implements RequestHandlerInterface{public function handle(ServerRequestInterface $request):ResponseInterface{throw new RuntimeException('Cancelled authentication reached business');}};
    $request=(new Request('GET',new Uri('https://api.example/items')))->withHeader('Authorization','DPoP access')->withHeader('DPoP',$factory->make(['ath'=>Base64Url::encode(hash('sha256','access',true))]));
    $id=Swoole\Coroutine::create(function()use($middleware,$request,$handler,$done):void{$done->push($middleware->process($request,$handler)->getStatusCode());});
    if($ready->pop(2)!==true || !Swoole\Coroutine::cancel($id)){throw new RuntimeException('Cancellation setup failed');}
    if($done->pop(2)!==503 || $budget->active!==0){throw new RuntimeException('Cancelled authentication leaked capacity or identity');}
    echo "Swoole cancellation: no business execution; authentication budget released\n";
});
