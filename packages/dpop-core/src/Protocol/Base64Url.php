<?php
declare(strict_types=1);
namespace Acme\Dpop\Protocol;

use Acme\Dpop\Exception\DpopException;

final class Base64Url
{
    public static function encode(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
    public static function decode(#[\SensitiveParameter] string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) { throw new DpopException('invalid_dpop_proof'); }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);
        if ($bytes === false || self::encode($bytes) !== $value) { throw new DpopException('invalid_dpop_proof'); }
        return $bytes;
    }
}
