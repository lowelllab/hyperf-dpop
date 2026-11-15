<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Http;

use Acme\Dpop\Exception\DpopException;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MtlsGuard
{
    public const HEADER = 'X-DPoP-MTLS-Verified';

    /** @var list<string> */
    private array $trustedPeers;

    /** @param array<array-key, mixed> $trustedPeers */
    public function __construct(public bool $enabled = false, array $trustedPeers = [])
    {
        $addresses = [];
        foreach ($trustedPeers as $address) {
            $packed = is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;
            if ($packed === false) {
                throw new \InvalidArgumentException('mtls_trusted_proxies requires exact IP addresses');
            }
            $addresses[] = $packed;
        }
        if ($enabled && $addresses === []) {
            throw new \InvalidArgumentException('Configure mtls_trusted_proxies when mTLS is enabled');
        }
        $this->trustedPeers = $addresses;
    }

    public function assertVerified(ServerRequestInterface $request): void
    {
        if (!$this->enabled) {
            return;
        }
        $address = $request->getServerParams()['remote_addr'] ?? null;
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false
            || !in_array(inet_pton($address), $this->trustedPeers, true)) {
            throw new DpopException('dpop_security_unavailable');
        }
        HeaderReader::assertGatewayMtls($request);
    }
}
