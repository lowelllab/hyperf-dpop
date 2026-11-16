<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

use Acme\Dpop\Authenticator;
use Acme\HyperfDpop\Admission\AdmissionBudgetInterface;
use Acme\HyperfDpop\Contract\{TokenBindingResolverInterface, ExternalUriResolverInterface};
use Acme\HyperfDpop\Http\{ClientSource, MtlsGuard};
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeServerStart;
use Psr\Container\ContainerInterface;

final readonly class BootstrapValidator implements ListenerInterface
{
    public function __construct(private ContainerInterface $container) {}
    public function listen(): array { return [BeforeServerStart::class]; }
    public function process(object $event): void
    {
        RuntimeRequirements::assertSupported();
        // Resolve configuration only before fork: do not create live DB/socket connections here.
        foreach ([MtlsGuard::class, Authenticator::class, TokenBindingResolverInterface::class, ExternalUriResolverInterface::class, ClientSource::class, AdmissionBudgetInterface::class] as $id) { $this->container->get($id); }
    }
}
