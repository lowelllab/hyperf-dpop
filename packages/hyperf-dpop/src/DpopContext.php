<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

use Acme\Dpop\ValueObject\{TokenBinding, VerifiedProof};

final readonly class DpopContext
{
    public const ATTRIBUTE = 'dpop.context';
    public function __construct(public TokenBinding $binding, public VerifiedProof $proof) {}
}
