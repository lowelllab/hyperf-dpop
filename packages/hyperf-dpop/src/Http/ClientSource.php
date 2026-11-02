<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Http;

use Psr\Http\Message\ServerRequestInterface;

final readonly class ClientSource
{
    /** @param list<string> $trustedProxies Exact IP addresses only; no implicit private-network trust. */
    public function __construct(private array $trustedProxies = [])
    {
        foreach ($trustedProxies as $ip) { if (filter_var($ip, FILTER_VALIDATE_IP) === false) { throw new \InvalidArgumentException('Invalid trusted proxy'); } }
    }

    public function resolve(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['remote_addr'] ?? 'unknown';
        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) { return 'unknown'; }
        if (in_array($remote, $this->trustedProxies, true) && count($request->getHeader('X-Real-IP')) === 1) {
            $forwarded = $request->getHeaderLine('X-Real-IP');
            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) { return (string) inet_ntop(inet_pton($forwarded)); }
        }
        return (string) inet_ntop(inet_pton($remote));
    }
}
