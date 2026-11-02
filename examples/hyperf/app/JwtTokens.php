<?php
declare(strict_types=1);
namespace DpopExample;

use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\Protocol\StrictJson;
use Acme\Dpop\ValueObject\TokenBinding;
use Jose\Component\Core\{AlgorithmManager, JWK};
use Jose\Component\Signature\{JWSBuilder, JWSVerifier};
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Serializer\CompactSerializer;

final class JwtTokens
{
    public function __construct(private readonly ?string $issuerKeyPath = null) {}
    private function issuerKey(): JWK
    {
        try {
            $contents = @file_get_contents($this->issuerKeyPath ?? dirname(__DIR__,3) . '/build/example-issuer-key.json');
            if ($contents === false) { throw new \RuntimeException(); }
            $key = new JWK(json_decode($contents, true, 16, JSON_THROW_ON_ERROR));
            if ($key->get('kty') !== 'EC' || $key->get('crv') !== 'P-256') { throw new \RuntimeException(); }
            foreach (['x', 'y', 'd'] as $field) {
                $value = $key->get($field);
                if (!is_string($value) || strlen(\Acme\Dpop\Protocol\Base64Url::decode($value)) !== 32) { throw new \RuntimeException(); }
            }
            if (@openssl_pkey_get_private(\Jose\Component\Core\Util\ECKey::convertPrivateKeyToPEM($key)) === false) { throw new \RuntimeException(); }
            return $key;
        } catch (\Throwable) { throw new DpopException('dpop_security_unavailable'); }
    }
    public function issue(string $subject, string $jkt): string
    {
        $claims = ['iss'=>'https://issuer.example','aud'=>'example-api','sub'=>$subject,'iat'=>time(),'exp'=>time()+300,'cnf'=>['jkt'=>$jkt]];
        $jws = (new JWSBuilder(new AlgorithmManager([new ES256()])))->create()->withPayload(json_encode($claims, JSON_THROW_ON_ERROR))->addSignature($this->issuerKey(), ['alg'=>'ES256','typ'=>'JWT'])->build();
        return (new CompactSerializer())->serialize($jws, 0);
    }
    public function resolve(#[\SensitiveParameter] string $token): TokenBinding
    {
        $issuerKey = $this->issuerKey()->toPublic();
        try {
            $jws = (new CompactSerializer())->unserialize($token);
            if ($jws->getSignature(0)->getProtectedHeaderParameter('alg') !== 'ES256'
                || !(new JWSVerifier(new AlgorithmManager([new ES256()])))->verifyWithKey($jws, $issuerKey, 0)) { throw new \RuntimeException(); }
            $claims = StrictJson::object($jws->getPayload());
            if (($claims->iss ?? null) !== 'https://issuer.example' || ($claims->aud ?? null) !== 'example-api' || !is_int($claims->exp ?? null) || $claims->exp <= time()) { throw new \RuntimeException(); }
            return new TokenBinding($claims->cnf->jkt, $claims->sub);
        } catch (\Throwable) { throw new DpopException('invalid_token'); }
    }
}
