<?php
declare(strict_types=1);
namespace DpopTest;

use Acme\Dpop\{Authenticator, SystemClock};
use Acme\Dpop\Contract\ReplayStoreInterface;
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\Nonce\HmacNonceManager;
use Acme\Dpop\Protocol\Base64Url;
use Acme\Dpop\ValueObject\TokenBinding;
use Acme\HyperfDpop\ConfigProvider;
use Acme\HyperfDpop\Contract\{TokenBindingResolverInterface, ExternalUriResolverInterface};
use Acme\HyperfDpop\Admission\{AdmissionBudgetInterface, RedisAdmissionBudget};
use Acme\HyperfDpop\Http\ErrorResponseFactory;
use Acme\HyperfDpop\Middleware\DpopMiddleware;
use Acme\HyperfDpop\TokenEndpoint;
use DpopTest\Support\{ProofFactory, TrackingBudget, MemoryReplay, Environment};
use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\{Container, Definition\DefinitionSource};
use Hyperf\HttpMessage\Server\{Request,Response};
use Hyperf\HttpMessage\Uri\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ServerRequestInterface,ResponseInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class ConfigurationTest extends TestCase
{
    private function container(ProofFactory $proof,array $options=[]): Container
    {
        if(!defined('BASE_PATH')){define('BASE_PATH',dirname(__DIR__).'/examples/hyperf');}
        $definitions=(new ConfigProvider())()['dependencies'];
        $definitions[ConfigInterface::class]=static fn()=>new Config(['dpop'=>array_replace(['domain'=>'test:custom','nonce_key'=>str_repeat('n',32)],$options)]);
        $definitions[ReplayStoreInterface::class]=MemoryReplay::class;
        $definitions[AdmissionBudgetInterface::class]=TrackingBudget::class;
        $definitions[ExternalUriResolverInterface::class]=static fn()=>new class implements ExternalUriResolverInterface {
            public function resolve(ServerRequestInterface $request):string{return 'https://edge.example/public'.substr($request->getUri()->getPath(),strlen('/internal'));}
        };
        $definitions[TokenBindingResolverInterface::class]=static fn()=>new class($proof->jkt()) implements TokenBindingResolverInterface {
            public function __construct(private string $jkt){}
            public function resolve(string $accessToken,ServerRequestInterface $request):TokenBinding{return new TokenBinding($this->jkt,'fixture-custom');}
        };
        return new Container(new DefinitionSource($definitions));
    }

    public function testCustomUriResolverWorksForResourceAndIssuanceThroughRealDi():void
    {
        $f=new ProofFactory();$container=$this->container($f);$nonces=new HmacNonceManager(str_repeat('n',32));
        $resourceProof=$f->make(['htu'=>'https://edge.example/public/items','nonce'=>$nonces->issue($f->jkt(),'test:custom:resource|https://edge.example',time()),'ath'=>Base64Url::encode(hash('sha256','access',true))]);
        $request=(new Request('GET',new Uri('http://internal/internal/items')))->withHeader('Authorization','DPoP access')->withHeader('DPoP',$resourceProof);
        $handler=new class implements RequestHandlerInterface {public function handle(ServerRequestInterface $request):ResponseInterface{return (new Response())->withContent($request->getAttribute('dpop.context')->binding->subject);}};
        $response=$container->get(DpopMiddleware::class)->process($request,$handler);
        self::assertSame(200,$response->getStatusCode());self::assertSame('fixture-custom',(string)$response->getBody());
        $tokenProof=$f->make(['htm'=>'POST','htu'=>'https://edge.example/public/token','nonce'=>$nonces->issue($f->jkt(),'test:custom:token|https://edge.example',time())]);
        $tokenRequest=(new Request('POST',new Uri('http://internal/internal/token')))->withHeader('DPoP',$tokenProof);
        self::assertSame($f->jkt(),$container->get(TokenEndpoint::class)->issuance($tokenRequest)->jkt);
    }

    public function testUnsafePolicyFailsThroughActualFactory():void
    {
        $this->expectException(\InvalidArgumentException::class);$this->container(new ProofFactory(),['replay_ttl'=>10])->get(Authenticator::class);
    }

    public function testEmptyNonceKeyFailsThroughActualFactory():void
    {
        $this->expectException(\InvalidArgumentException::class);$this->container(new ProofFactory(),['nonce_key'=>''])->get(Authenticator::class);
    }

    public function testRetryAfterReflectsConfiguredShortWindow():void
    {
        $budget=new RedisAdmissionBudget(Environment::redis(),'audit-'.bin2hex(random_bytes(6)),1,10,1);
        for($attempt=0;$attempt<3;++$attempt){
            try{$release=$budget->enter('source-'.$attempt,$attempt===0?'resource':'token');$release();}
            catch(DpopException $e){self::assertSame('rate_limited',$e->error);self::assertSame('1',(new ErrorResponseFactory())->create($e)->getHeaderLine('Retry-After'));return;}
        }
        self::fail('No rate rejection observed in three adjacent requests');
    }
}
