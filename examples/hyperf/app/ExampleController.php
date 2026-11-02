<?php
declare(strict_types=1);
namespace DpopExample;

use Acme\Dpop\Exception\DpopException;
use Acme\HyperfDpop\{TokenEndpoint, DpopContext};
use Acme\HyperfDpop\Contract\ErrorResponseFactoryInterface;
use Hyperf\HttpMessage\Server\Response;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

/** Loopback-only synthetic identity demonstration; not a production login implementation. */
final readonly class ExampleController
{
    public function __construct(private TokenEndpoint $tokens, private ErrorResponseFactoryInterface $errors, private State $state, private ExampleResolver $resolver, private JwtTokens $jwt) {}
    private function json(array $data): ResponseInterface { return (new Response())->withHeader('Content-Type','application/json')->withHeader('Cache-Control','no-store')->withContent(json_encode($data,JSON_THROW_ON_ERROR)); }
    public function health(): ResponseInterface { return $this->json(['ok'=>true,'workers'=>2]); }
    public function token(ServerRequestInterface $request): ResponseInterface
    {
        try { $proof = $this->tokens->issuance($request); $body = $request->getParsedBody(); $subject = $body['subject'] ?? ''; if (!is_string($subject) || !preg_match('/^fixture-[a-z0-9-]{1,64}$/D',$subject)) { throw new DpopException('invalid_request'); } return $this->json($this->state->issue($subject,$proof->jkt)); }
        catch (DpopException $e) { return $this->errors->create($e,true); }
    }
    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        try { $body=$request->getParsedBody(); $refresh=$body['refresh_token'] ?? null; if (!is_string($refresh)) { throw new DpopException('invalid_request'); } $proof=$this->tokens->refresh($request,$refresh,$this->resolver); return $this->json($this->state->rotate($refresh,$proof->jkt)); }
        catch (DpopException $e) { return $this->errors->create($e,true); }
    }
    public function jwtToken(ServerRequestInterface $request): ResponseInterface
    {
        try { $proof=$this->tokens->issuance($request); return $this->json(['access_token'=>$this->jwt->issue('fixture-jwt',$proof->jkt),'token_type'=>'DPoP']); }
        catch (DpopException $e) { return $this->errors->create($e,true); }
    }
    public function resource(ServerRequestInterface $request): ResponseInterface
    {
        $context=$request->getAttribute(DpopContext::ATTRIBUTE);
        if (!$context instanceof DpopContext) { throw new \RuntimeException('Missing request authentication'); }
        \Swoole\Coroutine::sleep(0.02);
        return $this->json(['subject'=>$context->binding->subject,'worker'=>getmypid()]);
    }
    public function revoke(ServerRequestInterface $request): ResponseInterface { $context=$request->getAttribute(DpopContext::ATTRIBUTE); $this->state->revoke($context->binding->subject); return $this->json(['revoked'=>true]); }
}
