<?php
declare(strict_types=1);
namespace Acme\Dpop\Protocol;

use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\SecurityPolicy;
use Acme\Dpop\ValueObject\VerifiedProof;
use Jose\Component\Core\{AlgorithmManager, JWK};
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;

final readonly class ProofVerifier
{
    public function __construct(private SecurityPolicy $policy) {}

    /** @return array{VerifiedProof, ?string} */
    public function verify(#[\SensitiveParameter] string $jwt, string $method, string $uri, int $now, ?string $expectedJkt = null, #[\SensitiveParameter] ?string $accessToken = null): array
    {
        try {
            if ($jwt === '' || strlen($jwt) > $this->policy->proofMaxBytes) { throw new DpopException('invalid_dpop_proof'); }
            $parts = explode('.', $jwt);
            if (count($parts) !== 3 || strlen(Base64Url::decode($parts[2])) !== 64) { throw new DpopException('invalid_dpop_proof'); }
            $header = StrictJson::object(Base64Url::decode($parts[0]));
            $claims = StrictJson::object(Base64Url::decode($parts[1]));
            if (($header->typ ?? null) !== 'dpop+jwt' || ($header->alg ?? null) !== 'ES256'
                || !(($header->jwk ?? null) instanceof \stdClass)
                || (property_exists($header, 'kid') && (!is_string($header->kid) || strlen($header->kid) > 256))
                || array_diff(array_keys(get_object_vars($header)), ['typ', 'alg', 'jwk', 'kid']) !== []) { throw new DpopException('invalid_dpop_proof'); }
            $jkt = JwkThumbprint::calculate($header->jwk);
            $jws = (new CompactSerializer())->unserialize($jwt);
            if (!(new JWSVerifier(new AlgorithmManager([new ES256()])))->verifyWithKey($jws, new JWK(get_object_vars($header->jwk)), 0)) { throw new DpopException('invalid_dpop_proof'); }
            if (!is_int($claims->iat ?? null) || !is_string($claims->jti ?? null) || strlen($claims->jti) < 16 || strlen($claims->jti) > 256
                || ($claims->htm ?? null) !== $method || !is_string($claims->htu ?? null)
                || UriCanonicalizer::canonicalize($claims->htu, true) !== UriCanonicalizer::canonicalize($uri)) { throw new DpopException('invalid_dpop_proof'); }
            if ((property_exists($claims, 'exp') && !is_int($claims->exp))
                || (property_exists($claims, 'nbf') && !is_int($claims->nbf))) { throw new DpopException('invalid_dpop_proof'); }
            $proof = new VerifiedProof($jkt, $claims->jti, $claims->iat, $claims->exp ?? null, $claims->nbf ?? null);
            $this->policy->assertProofTime($proof, $now);
            if ($accessToken !== null && (!is_string($claims->ath ?? null) || !hash_equals(Base64Url::encode(hash('sha256', $accessToken, true)), $claims->ath))) { throw new DpopException('invalid_dpop_proof'); }
            if ($expectedJkt !== null && !hash_equals($expectedJkt, $jkt)) { throw new DpopException($accessToken !== null ? 'invalid_token' : 'invalid_dpop_proof'); }
            return [$proof, is_string($claims->nonce ?? null) ? $claims->nonce : null];
        } catch (DpopException $e) { throw $e; }
        catch (\Throwable) { throw new DpopException('invalid_dpop_proof'); }
    }
}
