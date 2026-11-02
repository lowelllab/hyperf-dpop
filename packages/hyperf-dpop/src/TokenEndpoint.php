<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

use Acme\Dpop\Authenticator;
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\{SecurityPolicy, VerifiedProof};
use Acme\HyperfDpop\Admission\AdmissionBudgetInterface;
use Acme\HyperfDpop\Contract\{RefreshBindingResolverInterface, AuthenticationObserverInterface, ExternalUriResolverInterface};
use Acme\HyperfDpop\Http\{ClientSource, HeaderReader};
use Psr\Http\Message\ServerRequestInterface;

final readonly class TokenEndpoint
{
    public function __construct(private Authenticator $auth, private SecurityPolicy $policy, private ExternalUriResolverInterface $uris, private ClientSource $source, private AdmissionBudgetInterface $budget, private AuthenticationObserverInterface $observer) {}

    public function issuance(ServerRequestInterface $request): VerifiedProof { return $this->verify($request, null, null); }
    public function refresh(ServerRequestInterface $request, #[\SensitiveParameter] string $refreshToken, RefreshBindingResolverInterface $resolver): VerifiedProof { return $this->verify($request, $refreshToken, $resolver); }

    private function verify(ServerRequestInterface $request, #[\SensitiveParameter] ?string $refresh, ?RefreshBindingResolverInterface $resolver): VerifiedProof
    {
        $release = null; $started = hrtime(true); $outcome = 'dpop_security_unavailable'; $category = $resolver === null ? 'token' : 'refresh';
        try {
            $release = $this->budget->enter($this->source->resolve($request), $category);
            $jwt = HeaderReader::proof($request, $this->policy->proofMaxBytes);
            $jkt = $resolver?->resolveRefresh($refresh ?? '', $request)->jkt;
            Cancellation::assertActive();
            $result = $this->auth->issuance($jwt, $request->getMethod(), $this->uris->resolve($request), $jkt);
            Cancellation::assertActive();
            $outcome = 'accepted';
            return $result;
        } catch (DpopException $e) { $outcome = $e->error; throw $e; }
        catch (\Throwable) { throw new DpopException('dpop_security_unavailable'); }
        finally {
            if ($release !== null) { $release(); }
            try { $this->observer->record($category, $outcome, (hrtime(true) - $started) / 1e6); } catch (\Throwable) {}
        }
    }
}
