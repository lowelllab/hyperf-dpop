<?php
declare(strict_types=1);
namespace Acme\Dpop;

use Acme\Dpop\Contract\{NonceManagerInterface, ReplayStoreInterface};
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\Protocol\{ProofVerifier, UriCanonicalizer};
use Acme\Dpop\ValueObject\{SecurityPolicy, TokenBinding, VerifiedProof};
use Psr\Clock\ClockInterface;

final readonly class Authenticator
{
    public function __construct(private SecurityPolicy $policy, private ProofVerifier $verifier, private NonceManagerInterface $nonces, private ReplayStoreInterface $replay, private ClockInterface $clock, private string $domain)
    {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $domain) !== 1) { throw new \InvalidArgumentException('Invalid security domain'); }
    }

    public function resource(#[\SensitiveParameter] string $proof, string $method, string $uri, #[\SensitiveParameter] string $access, TokenBinding $binding): VerifiedProof
    {
        if ($access === '') { throw new DpopException('invalid_token'); }
        return $this->authenticate($proof, $method, $uri, $binding->jkt, $access);
    }

    /** A refresh binding is trusted read-only state; the host must recheck with CAS when rotating. */
    public function issuance(#[\SensitiveParameter] string $proof, string $method, string $uri, ?string $refreshJkt = null): VerifiedProof
    {
        return $this->authenticate($proof, $method, $uri, $refreshJkt, null);
    }

    private function authenticate(#[\SensitiveParameter] string $jwt, string $method, string $uri, ?string $jkt, #[\SensitiveParameter] ?string $token): VerifiedProof
    {
        $now = $this->clock->now()->getTimestamp();
        [$proof, $nonce] = $this->verifier->verify($jwt, $method, $uri, $now, $jkt, $token);
        $purpose = $token === null ? 'token' : 'resource';
        $domain = $this->domain . ':' . $purpose;
        $context = $domain . '|' . UriCanonicalizer::origin($uri);
        if ($nonce === null || !$this->nonces->verify($nonce, $proof->jkt, $context, $now)) {
            throw new DpopException('use_dpop_nonce', $this->nonces->issue($proof->jkt, $context, $now));
        }
        $this->replay->reserve($domain, $proof, $now, $this->policy);
        return $proof;
    }
}
