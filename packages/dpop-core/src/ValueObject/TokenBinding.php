<?php
declare(strict_types=1);
namespace Acme\Dpop\ValueObject;

/** Must only be constructed from a token already validated by the host. */
final readonly class TokenBinding
{
    public function __construct(public string $jkt, public string $subject)
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $jkt) !== 1 || $subject === '' || strlen($subject) > 256) {
            throw new \InvalidArgumentException('Invalid trusted token binding');
        }
    }
}
