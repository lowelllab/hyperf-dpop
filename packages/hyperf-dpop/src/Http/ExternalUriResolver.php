<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Http;

use Acme\Dpop\Protocol\UriCanonicalizer;
use Acme\Dpop\Exception\DpopException;
use Psr\Http\Message\ServerRequestInterface;

/** Uses configured origin and explicit prefix mapping; ignores untrusted forwarded origins. */
final readonly class ExternalUriResolver implements \Acme\HyperfDpop\Contract\ExternalUriResolverInterface
{
    public function __construct(private string $origin, private string $internalPrefix = '', private string $externalPrefix = '')
    {
        if (UriCanonicalizer::origin($origin) !== $origin || UriCanonicalizer::canonicalize($origin) !== $origin . '/') { throw new \InvalidArgumentException('Configure an HTTPS origin without path/query'); }
        foreach ([$internalPrefix, $externalPrefix] as $prefix) {
            if ($prefix !== '' && preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$~D', $prefix) !== 1) { throw new \InvalidArgumentException('Invalid path prefix'); }
        }
    }

    public function resolve(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath() ?: '/';
        if ($this->internalPrefix !== '' && $path !== $this->internalPrefix && !str_starts_with($path, $this->internalPrefix . '/')) { throw new DpopException('dpop_security_unavailable'); }
        return $this->origin . $this->externalPrefix . substr($path, strlen($this->internalPrefix));
    }
}
