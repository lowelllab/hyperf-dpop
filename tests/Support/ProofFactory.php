<?php
declare(strict_types=1);
namespace DpopTest\Support;

use Acme\Dpop\Protocol\Base64Url;
use Jose\Component\Core\{AlgorithmManager, JWK};
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

/** Synthetic keys only, independent of production credentials. */
final class ProofFactory
{
    public readonly JWK $key;
    public function __construct() { $this->key = JWKFactory::createECKey('P-256'); }
    public function jkt(): string { return $this->key->thumbprint('sha256'); }
    public function publicJwk(): array { return $this->key->toPublic()->all(); }

    public function make(array $claims = [], array $header = [], ?string $payload = null): string
    {
        $values = array_replace(['htm' => 'GET', 'htu' => 'https://api.example/items', 'iat' => time(), 'jti' => bin2hex(random_bytes(16))], $claims);
        $protected = array_replace(['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $this->publicJwk()], $header);
        $jws = (new JWSBuilder(new AlgorithmManager([new ES256()])))->create()->withPayload($payload ?? json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->addSignature($this->key, $protected)->build();
        return (new CompactSerializer())->serialize($jws, 0);
    }
}
