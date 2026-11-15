<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

use Acme\Dpop\Authenticator;
use Acme\Dpop\SystemClock;
use Acme\Dpop\Contract\{ReplayStoreInterface, NonceManagerInterface};
use Acme\Dpop\ValueObject\SecurityPolicy;
use Acme\HyperfDpop\Admission\AdmissionBudgetInterface;
use Acme\HyperfDpop\Contract\{ErrorResponseFactoryInterface, AuthenticationObserverInterface, TokenBindingResolverInterface};
use Acme\HyperfDpop\Contract\ExternalUriResolverInterface;
use Acme\HyperfDpop\Http\{ExternalUriResolver, ClientSource, ErrorResponseFactory, MtlsGuard};
use Acme\HyperfDpop\Middleware\DpopMiddleware;
use Acme\HyperfDpop\Store\RedisExecutorInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

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
                MtlsGuard::class => static fn (ContainerInterface $c) => Factory::mtls($c),
                DpopMiddleware::class => static fn (ContainerInterface $c) => new DpopMiddleware(
                    $c->get(Authenticator::class),
                    $c->get(SecurityPolicy::class),
                    $c->get(TokenBindingResolverInterface::class),
                    $c->get(ExternalUriResolverInterface::class),
                    $c->get(ClientSource::class),
                    $c->get(AdmissionBudgetInterface::class),
                    $c->get(ErrorResponseFactoryInterface::class),
                    $c->get(AuthenticationObserverInterface::class),
                    $c->get(MtlsGuard::class),
                ),
                TokenEndpoint::class => static fn (ContainerInterface $c) => new TokenEndpoint(
                    $c->get(Authenticator::class),
                    $c->get(SecurityPolicy::class),
                    $c->get(ExternalUriResolverInterface::class),
                    $c->get(ClientSource::class),
                    $c->get(AdmissionBudgetInterface::class),
                    $c->get(AuthenticationObserverInterface::class),
                    $c->get(MtlsGuard::class),
                ),
            ],
            'listeners' => [BootstrapValidator::class],
            'commands' => class_exists(\Hyperf\Command\Command::class) ? [Command\DoctorCommand::class, Command\GatewayConfigCommand::class] : [],
            'publish' => [
                ['id' => 'config', 'description' => 'DPoP configuration; fill keys and resolver before enabling', 'source' => __DIR__ . '/../publish/dpop.php', 'destination' => BASE_PATH . '/config/autoload/dpop.php'],
                ['id' => 'schema', 'description' => 'Explicit MySQL DDL; never run automatically', 'source' => __DIR__ . '/../publish/dpop-proofs.sql', 'destination' => BASE_PATH . '/dpop-proofs.sql'],
                ['id' => 'gateway', 'description' => 'HAProxy duplicate authentication header guard; configure and deploy explicitly', 'source' => __DIR__ . '/../publish/haproxy.cfg', 'destination' => BASE_PATH . '/haproxy-dpop.cfg'],
            ],
        ];
    }
}
