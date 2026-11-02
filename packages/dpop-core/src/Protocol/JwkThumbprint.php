<?php
declare(strict_types=1);
namespace Acme\Dpop\Protocol;

use Acme\Dpop\Exception\DpopException;

final class JwkThumbprint
{
    public static function calculate(\stdClass $jwk): string
    {
        $fields = get_object_vars($jwk);
        if (($fields['kty'] ?? null) !== 'EC' || ($fields['crv'] ?? null) !== 'P-256'
            || array_diff(array_keys($fields), ['kty', 'crv', 'x', 'y', 'alg', 'use', 'key_ops', 'kid', 'ext']) !== []) {
            throw new DpopException('invalid_dpop_proof');
        }
        foreach (['x', 'y'] as $coordinate) {
            if (!is_string($fields[$coordinate] ?? null) || strlen(Base64Url::decode($fields[$coordinate])) !== 32) { throw new DpopException('invalid_dpop_proof'); }
        }
        if ((property_exists($jwk, 'alg') && $jwk->alg !== 'ES256') || (property_exists($jwk, 'use') && $jwk->use !== 'sig')
            || (property_exists($jwk, 'key_ops') && $jwk->key_ops !== ['verify'])
            || (property_exists($jwk, 'kid') && (!is_string($jwk->kid) || strlen($jwk->kid) > 256))
            || (property_exists($jwk, 'ext') && !is_bool($jwk->ext))) {
            throw new DpopException('invalid_dpop_proof');
        }
        return Base64Url::encode(hash('sha256', json_encode(['crv' => 'P-256', 'kty' => 'EC', 'x' => $jwk->x, 'y' => $jwk->y], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true));
    }
}
