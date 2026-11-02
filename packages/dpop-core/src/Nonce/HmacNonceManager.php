<?php
declare(strict_types=1);
namespace Acme\Dpop\Nonce;

use Acme\Dpop\Contract\NonceManagerInterface;
use Acme\Dpop\Protocol\Base64Url;
use Acme\Dpop\Protocol\StrictJson;

final readonly class HmacNonceManager implements NonceManagerInterface
{
    public function __construct(#[\SensitiveParameter] private string $key, private int $ttl = 300, #[\SensitiveParameter] private ?string $previousKey = null)
    {
        if (strlen($key) < 32 || ($previousKey !== null && strlen($previousKey) < 32) || $ttl < 30 || $ttl > 600) { throw new \InvalidArgumentException('Unsafe nonce configuration'); }
    }

    public function issue(string $jkt, string $context, int $now): string
    {
        $payload = Base64Url::encode(json_encode(['jkt' => $jkt, 'ctx' => hash('sha256', $context), 'iat' => $now, 'exp' => $now + $this->ttl, 'salt' => Base64Url::encode(random_bytes(16))], JSON_THROW_ON_ERROR));
        return $payload . '.' . Base64Url::encode(hash_hmac('sha256', $payload, $this->key, true));
    }

    public function verify(#[\SensitiveParameter] string $nonce, string $jkt, string $context, int $now): bool
    {
        try {
            if (strlen($nonce) > 1024) { return false; }
            $parts = explode('.', $nonce);
            if (count($parts) !== 2) { return false; }
            [$payload, $mac] = $parts;
            $signature = Base64Url::decode($mac);
            $valid = hash_equals(hash_hmac('sha256', $payload, $this->key, true), $signature);
            if ($this->previousKey !== null) { $valid = hash_equals(hash_hmac('sha256', $payload, $this->previousKey, true), $signature) || $valid; }
            if (!$valid) { return false; }
            $data = StrictJson::object(Base64Url::decode($payload));
            return ($data->jkt ?? null) === $jkt && ($data->ctx ?? null) === hash('sha256', $context)
                && is_int($data->iat ?? null) && is_int($data->exp ?? null) && $data->iat <= $now
                && $now < $data->exp && $data->exp - $data->iat <= $this->ttl;
        } catch (\Throwable) { return false; }
    }

    public function __debugInfo(): array { return ['ttl' => $this->ttl]; }
}
