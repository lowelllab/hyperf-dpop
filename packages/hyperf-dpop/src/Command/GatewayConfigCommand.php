<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Command;

use Acme\HyperfDpop\Factory;
use Hyperf\Command\Command;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GatewayConfigCommand extends Command
{
    protected string $description = 'Render the HAProxy configuration from dpop.php';

    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct('dpop:gateway-config');
    }

    public function handle(): int
    {
        try {
            $config = Factory::gatewayConfig($this->container);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->output->getErrorStyle()->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW);
            return 1;
        }
        $this->output->write($config, false, OutputInterface::OUTPUT_RAW);
        return 0;
    }
}
