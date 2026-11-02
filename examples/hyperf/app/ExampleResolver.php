<?php
declare(strict_types=1);
namespace DpopExample;

use Acme\Dpop\ValueObject\TokenBinding;
use Acme\HyperfDpop\Contract\{TokenBindingResolverInterface, RefreshBindingResolverInterface};
use Psr\Http\Message\ServerRequestInterface;

final readonly class ExampleResolver implements TokenBindingResolverInterface, RefreshBindingResolverInterface
{
    public function __construct(private State $state, private JwtTokens $jwt) {}
    public function resolve(#[\SensitiveParameter] string $accessToken, ServerRequestInterface $request): TokenBinding
    {
        return str_starts_with($request->getUri()->getPath(), '/jwt/') ? $this->jwt->resolve($accessToken) : $this->state->find($accessToken);
    }
    public function resolveRefresh(#[\SensitiveParameter] string $refreshToken, ServerRequestInterface $request): TokenBinding { return $this->state->find($refreshToken, true); }
}
