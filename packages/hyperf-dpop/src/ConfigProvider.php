<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

use Acme\Dpop\Authenticator;
use Acme\Dpop\SystemClock;
use Acme\Dpop\Contract\{ReplayStoreInterface, NonceManagerInterface};
use Acme\Dpop\ValueObject\SecurityPolicy;
use Acme\HyperfDpop\Admission\AdmissionBudgetInterface;
use Acme\HyperfDpop\Contract\{ErrorResponseFactoryInterface, AuthenticationObserverInterface};
use Acme\HyperfDpop\Contract\ExternalUriResolverInterface;
use Acme\HyperfDpop\Http\{ExternalUriResolver, ClientSource, ErrorResponseFactory};
use Acme\HyperfDpop\Store\RedisExecutorInterface;
use Psr\Clock\ClockInterface;

final class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                SecurityPolicy::class => static fn (\Psr\Container\ContainerInterface $c) => Factory::policy($c),
                ClockInterface::class => SystemClock::class,
                NonceManagerInterface::class => static fn (\Psr\Container\ContainerInterface $c) => Factory::nonce($c),
                ReplayStoreInterface::class => static fn (\Psr\Container\ContainerInterface $c) => Factory::replay($c),
                Authenticator::class => static fn (\Psr\Container\ContainerInterface $c) => Factory::authenticator($c),
                ExternalUriResolver::class => static fn (\Psr\Container\ContainerInterface $c) => Factory::uris($c),
                ExternalUriResolverInterface::class => static fn (\Psr\Container\ContainerInterface $c) => $c->get(ExternalUriResolver::class),
                ClientSource::class => static fn (\Psr\Container\ContainerInterface $c) => Factory::source($c),
                RedisExecutorInterface::class => static fn (\Psr\Container\ContainerInterface $c) => Factory::redis($c),
                AdmissionBudgetInterface::class => static fn (\Psr\Container\ContainerInterface $c) => Factory::budget($c),
                ErrorResponseFactoryInterface::class => ErrorResponseFactory::class,
                AuthenticationObserverInterface::class => AuthenticationObserver::class,
            ],
            'listeners' => [BootstrapValidator::class],
            'commands' => class_exists(\Hyperf\Command\Command::class) ? [Command\DoctorCommand::class] : [],
            'publish' => [
                ['id' => 'config', 'description' => 'DPoP configuration; fill keys and resolver before enabling', 'source' => __DIR__ . '/../publish/dpop.php', 'destination' => BASE_PATH . '/config/autoload/dpop.php'],
                ['id' => 'schema', 'description' => 'Explicit MySQL DDL; never run automatically', 'source' => __DIR__ . '/../publish/dpop-proofs.sql', 'destination' => BASE_PATH . '/dpop-proofs.sql'],
            ],
        ];
    }
}
