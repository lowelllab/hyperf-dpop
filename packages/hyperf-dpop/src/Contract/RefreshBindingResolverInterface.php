<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Contract;

use Acme\Dpop\ValueObject\TokenBinding;
use Psr\Http\Message\ServerRequestInterface;

interface RefreshBindingResolverInterface
{
    /** Read-only; the host MUST recheck token validity and binding under lock/CAS when rotating. */
    public function resolveRefresh(#[\SensitiveParameter] string $refreshToken, ServerRequestInterface $request): TokenBinding;
}
