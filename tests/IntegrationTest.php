<?php
declare(strict_types=1);
namespace DpopTest;

use Acme\Dpop\{Authenticator, SystemClock};
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\Nonce\HmacNonceManager;
use Acme\Dpop\Protocol\{Base64Url, ProofVerifier};
use Acme\Dpop\ValueObject\{SecurityPolicy, TokenBinding};
use Acme\HyperfDpop\{AuthenticationObserver, ConfigProvider, DpopContext, Factory, TokenEndpoint};
use Acme\HyperfDpop\Contract\TokenBindingResolverInterface;
use Acme\HyperfDpop\Http\{ExternalUriResolver, ClientSource, ErrorResponseFactory};
use Acme\HyperfDpop\Middleware\DpopMiddleware;
use DpopTest\Support\{ProofFactory, MemoryReplay, TrackingBudget};
use Hyperf\HttpMessage\Server\{Request, Response};
use Hyperf\HttpMessage\Uri\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class IntegrationTest extends TestCase
{
    private function request(string $method='GET',string $path='/items'): ServerRequestInterface { return new Request($method,new Uri('http://untrusted.internal'.$path)); }

    private function middleware(ProofFactory $f, TrackingBudget $budget): DpopMiddleware
    {
        $p=new SecurityPolicy(); $a=new Authenticator($p,new ProofVerifier($p),new HmacNonceManager(str_repeat('n',32)),new MemoryReplay(),new SystemClock(),'test:api');
        $resolver=new class($f->jkt()) implements TokenBindingResolverInterface {
            public function __construct(private string $jkt) {}
            public function resolve(string $accessToken,ServerRequestInterface $request): TokenBinding { return new TokenBinding($this->jkt,'fixture-user'); }
        };
        return new DpopMiddleware($a,$p,$resolver,new ExternalUriResolver('https://api.example'),new ClientSource(),$budget,new ErrorResponseFactory(),new AuthenticationObserver());
    }

    private function handler(\Closure $callback): RequestHandlerInterface
    {
        return new class($callback) implements RequestHandlerInterface {
            public function __construct(private \Closure $callback) {}
            public function handle(ServerRequestInterface $request): ResponseInterface { return ($this->callback)($request); }
        };
    }

    public function testChallengeSuccessAndRequestContext(): void
    {
        $f=new ProofFactory(); $budget=new TrackingBudget(); $m=$this->middleware($f,$budget); $seen=[];
        $handler=$this->handler(function ($request) use (&$seen,$budget) { self::assertSame(0,$budget->active); $seen[]=$request->getAttribute(DpopContext::ATTRIBUTE)->binding->subject; return new Response(); });
        $claims=['ath'=>Base64Url::encode(hash('sha256','access',true))];
        $request=$this->request()->withHeader('Authorization','DPoP access')->withHeader('DPoP',$f->make($claims));
        $challenge=$m->process($request,$handler);
        self::assertSame(401,$challenge->getStatusCode()); self::assertSame('no-store',$challenge->getHeaderLine('Cache-Control')); self::assertSame([],$seen);
        $claims['nonce']=$challenge->getHeaderLine('DPoP-Nonce');
        $result=$m->process($request->withHeader('DPoP',$f->make($claims)),$handler);
        self::assertSame(200,$result->getStatusCode()); self::assertSame(['fixture-user'],$seen); self::assertNull($request->getAttribute(DpopContext::ATTRIBUTE));
    }

    public static function malformed(): array
    {
        return [
            [[],401,'missing_authorization'],
            [['Authorization'=>['DPoP a','DPoP b']],400,'invalid_request'],
            [['Authorization'=>'DPoP a','DPoP'=>['a.b.c','a.b.c']],400,'invalid_request'],
            [['Authorization'=>'DPoP a,b'],400,'invalid_request'],
            [['Authorization'=>'Bearer a'],401,'invalid_token'],
            [['Authorization'=>'DPoP a'],401,'invalid_dpop_proof'],
        ];
    }

    #[DataProvider('malformed')]
    public function testInvalidHeadersNeverReachBusiness(array $headers,int $status,string $error): void
    {
        $f=new ProofFactory(); $budget=new TrackingBudget(); $request=$this->request(); foreach($headers as $key=>$value){$request=$request->withHeader($key,$value);}
        $result=$this->middleware($f,$budget)->process($request,$this->handler(static fn()=>throw new \LogicException('Must not execute')));
        self::assertSame($status,$result->getStatusCode()); self::assertSame(0,$budget->active);
        if($error==='missing_authorization'){self::assertStringNotContainsString('error=', $result->getHeaderLine('WWW-Authenticate'));} else {self::assertSame($error,json_decode((string)$result->getBody(),true)['error']);}
    }

    public function testBusinessExceptionsPropagateAfterBudgetRelease(): void
    {
        $f=new ProofFactory();$budget=new TrackingBudget();$m=$this->middleware($f,$budget);
        $n=(new HmacNonceManager(str_repeat('n',32)))->issue($f->jkt(),'test:api:resource|https://api.example',time());
        $request=$this->request()->withHeader('Authorization','DPoP access')->withHeader('DPoP',$f->make(['ath'=>Base64Url::encode(hash('sha256','access',true)),'nonce'=>$n]));
        try{$m->process($request,$this->handler(static fn()=>throw new \LogicException('host business failure')));self::fail();}
        catch(\LogicException $e){self::assertSame('host business failure',$e->getMessage());self::assertSame(0,$budget->active);}
    }

    public function testOptionsPassesWithoutIdentityOrBudget(): void
    {
        $budget=new TrackingBudget();$called=false;
        $this->middleware(new ProofFactory(),$budget)->process($this->request('OPTIONS'),$this->handler(function($request)use(&$called){$called=true;self::assertNull($request->getAttribute(DpopContext::ATTRIBUTE));return new Response();}));
        self::assertTrue($called);self::assertSame(0,$budget->active);
    }

    public function testUriPrefixMappingPreservesReservedEncodingAndIgnoresForwardedHost(): void
    {
        $request=$this->request('GET','/internal/v1/groups/a%40b')->withHeader('Forwarded','host=evil.example;proto=http')->withHeader('X-Forwarded-Host','evil.example');
        self::assertSame('https://public.example/gateway/v1/groups/a%40b',(new ExternalUriResolver('https://public.example','/internal/v1','/gateway/v1'))->resolve($request));
    }

    public function testSourceOnlyTrustsExplicitProxy(): void
    {
        $request=(new Request('GET',new Uri('http://internal/items')))->withServerParams(['remote_addr'=>'10.0.0.2']);$request=$request->withHeader('X-Real-IP','192.0.2.5');
        self::assertSame('10.0.0.2',(new ClientSource())->resolve($request)); self::assertSame('192.0.2.5',(new ClientSource(['10.0.0.2']))->resolve($request));
    }

    public function testTokenEndpointChallengeUses400AndReleasesBudget(): void
    {
        $f=new ProofFactory();$p=new SecurityPolicy();$budget=new TrackingBudget();$a=new Authenticator($p,new ProofVerifier($p),new HmacNonceManager(str_repeat('n',32)),new MemoryReplay(),new SystemClock(),'test:api');
        $endpoint=new TokenEndpoint($a,$p,new ExternalUriResolver('https://api.example'),new ClientSource(),$budget,new AuthenticationObserver());
        try{$endpoint->issuance($this->request('POST','/token')->withHeader('DPoP',$f->make(['htm'=>'POST','htu'=>'https://api.example/token'])));self::fail();}
        catch(DpopException $e){$response=(new ErrorResponseFactory())->create($e,true);self::assertSame(400,$response->getStatusCode());self::assertNotEmpty($response->getHeaderLine('DPoP-Nonce'));self::assertSame(0,$budget->active);}
    }

    public function testConfigProviderDoesNotRegisterIdentityOrRoutes(): void
    {
        if(!defined('BASE_PATH')){define('BASE_PATH',dirname(__DIR__).'/examples/hyperf');}
        $config=(new ConfigProvider())(); self::assertArrayHasKey('publish',$config);self::assertArrayNotHasKey(TokenBindingResolverInterface::class,$config['dependencies']);self::assertArrayNotHasKey('middlewares',$config);
        foreach($config['publish'] as $item){self::assertFileExists($item['source']);}
    }
}
