<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Contract;

use Psr\Http\Message\ServerRequestInterface;

interface ExternalUriResolverInterface
{
    /** Return the trusted public HTTPS URI; never trust arbitrary client Host/Forwarded headers. */
    public function resolve(ServerRequestInterface $request): string;
}
