<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Command;

use Acme\Dpop\Authenticator;
use Acme\Dpop\Contract\ReplayStoreInterface;
use Acme\HyperfDpop\Admission\{AdmissionBudgetInterface, AdmissionBudgetProbeInterface};
use Acme\HyperfDpop\Contract\{TokenBindingResolverInterface, ExternalUriResolverInterface};
use Acme\HyperfDpop\Http\ClientSource;
use Acme\HyperfDpop\Store\ReplayStoreProbeInterface;
use Hyperf\Command\Command;
use Psr\Container\ContainerInterface;

final class DoctorCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container) { parent::__construct('dpop:doctor'); }
    public function handle(): int
    {
        $check = 'configuration';
        try {
            foreach ([Authenticator::class, TokenBindingResolverInterface::class, ExternalUriResolverInterface::class, ClientSource::class, AdmissionBudgetInterface::class] as $id) { $check = $id; $this->container->get($id); }
            $check = 'replay-schema';
            $store = $this->container->get(ReplayStoreInterface::class);
            if (!$store instanceof ReplayStoreProbeInterface) {
                $this->output->writeln('DPoP replay store is custom and cannot be probed. Implement ReplayStoreProbeInterface; health is unverified.');
                return 1;
            }
            $store->probe();
            $check = 'shared-budget-connectivity';
            $budget = $this->container->get(AdmissionBudgetInterface::class);
            if (!$budget instanceof AdmissionBudgetProbeInterface) {
                $this->output->writeln('DPoP admission budget is custom and cannot be probed. Implement AdmissionBudgetProbeInterface; health is unverified.');
                return 1;
            }
            $budget->probe();
            $this->output->writeln('DPoP configuration, resolver, replay schema and shared budget connectivity: OK (read-only)');
            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('DPoP diagnostic failed at ' . $check . ' [' . $e::class . ']. Check configuration and bindings. No secrets are printed.');
            return 1;
        }
    }
}
