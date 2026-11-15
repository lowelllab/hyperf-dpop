<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Command;

use Acme\HyperfDpop\Factory;
use Hyperf\Command\Command;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class GatewayConfigCommand extends Command
{
    protected string $description = 'Render the HAProxy configuration from dpop.php';

    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct('dpop:gateway-config');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the HAProxy configuration to this file');
    }

    public function handle(): int
    {
        try {
            $config = Factory::gatewayConfig($this->container);
            $path = $this->input->getOption('output');
            if ($path === null) {
                $this->output->write($config, false, OutputInterface::OUTPUT_RAW);
            } else {
                $this->writeConfig($path, $config);
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->output->getErrorStyle()->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW);
            return 1;
        }
        return 0;
    }

    private function writeConfig(string $path, string $config): void
    {
        $directory = realpath(dirname($path));
        if ($path === '' || $directory === false || !is_dir($directory) || is_dir($path)) {
            throw new \InvalidArgumentException('Choose an output file in an existing directory');
        }
        $permissions = is_file($path) ? @fileperms($path) : (0666 & ~umask());
        if ($permissions === false) {
            throw new \RuntimeException('Cannot read permissions for the output file');
        }

        $temporary = @tempnam($directory, '.dpop-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create temporary HAProxy configuration');
        }
        try {
            // tempnam can fall back to the system directory. Keep replacement on the same filesystem.
            if (dirname($temporary) !== $directory || @file_put_contents($temporary, $config) !== strlen($config)
                || !@chmod($temporary, $permissions & 0777) || !@rename($temporary, $path)) {
                throw new \RuntimeException('Cannot write HAProxy configuration to the output file');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
