<?php
declare(strict_types=1);
namespace Acme\HyperfDpop;

use Acme\Dpop\{Authenticator, SystemClock};
use Acme\Dpop\Contract\{NonceManagerInterface, ReplayStoreInterface};
use Acme\Dpop\Nonce\HmacNonceManager;
use Acme\Dpop\Protocol\ProofVerifier;
use Acme\Dpop\ValueObject\SecurityPolicy;
use Acme\HyperfDpop\Admission\{AdmissionBudgetInterface, RedisAdmissionBudget};
use Acme\HyperfDpop\Http\{ClientSource, ExternalUriResolver};
use Acme\HyperfDpop\Store\{HyperfRedisExecutor, RedisExecutorInterface, RedisReplayStore, MySqlReplayStore};
use Hyperf\Contract\ConfigInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

final class Factory
{
    /** @return array<string, mixed> */
    private static function config(ContainerInterface $c): array
    {
        $options = $c->get(ConfigInterface::class)->get('dpop', []);
        if (!is_array($options)) { throw new \InvalidArgumentException('Invalid dpop configuration'); }
        return $options;
    }

    /** @param array<string,mixed> $values */
    private static function number(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? $default;
        if (!is_int($value)) { throw new \InvalidArgumentException('DPoP integer configuration required: ' . $key); }
        return $value;
    }

    public static function policy(ContainerInterface $c): SecurityPolicy
    {
        $o = self::config($c);
        return new SecurityPolicy(self::number($o, 'past_seconds', 60), self::number($o, 'future_seconds', 30), self::number($o, 'replay_ttl', 120), self::number($o, 'clock_skew', 5), self::number($o, 'proof_max_bytes', 8192), self::number($o, 'nonce_ttl', 300));
    }

    public static function nonce(ContainerInterface $c): NonceManagerInterface
    {
        $o = self::config($c);
        return new HmacNonceManager((string) ($o['nonce_key'] ?? ''), $c->get(SecurityPolicy::class)->nonceTtl, isset($o['previous_nonce_key']) && $o['previous_nonce_key'] !== '' ? (string) $o['previous_nonce_key'] : null);
    }

    public static function authenticator(ContainerInterface $c): Authenticator
    {
        return new Authenticator($c->get(SecurityPolicy::class), $c->get(ProofVerifier::class), $c->get(NonceManagerInterface::class), $c->get(ReplayStoreInterface::class), $c->get(ClockInterface::class), (string) (self::config($c)['domain'] ?? ''));
    }

    public static function uris(ContainerInterface $c): ExternalUriResolver
    {
        $o = self::config($c);
        return new ExternalUriResolver((string) ($o['origin'] ?? ''), (string) ($o['internal_prefix'] ?? ''), (string) ($o['external_prefix'] ?? ''));
    }

    public static function source(ContainerInterface $c): ClientSource
    {
        $proxies = self::config($c)['trusted_proxies'] ?? [];
        if (!is_array($proxies)) { throw new \InvalidArgumentException('Invalid trusted proxies'); }
        return new ClientSource($proxies);
    }

    public static function redis(ContainerInterface $c): RedisExecutorInterface
    {
        if (!class_exists(\Hyperf\Redis\Redis::class)) { throw new \RuntimeException('Install hyperf/redis for the shared admission budget'); }
        return new HyperfRedisExecutor($c->get(\Hyperf\Redis\Redis::class));
    }

    public static function replay(ContainerInterface $c): ReplayStoreInterface
    {
        $o = self::config($c);
        if (($o['replay_driver'] ?? 'mysql') === 'redis') { return new RedisReplayStore($c->get(RedisExecutorInterface::class), (string) ($o['namespace'] ?? 'dpop')); }
        if (($o['replay_driver'] ?? 'mysql') !== 'mysql' || !extension_loaded('pdo_mysql')) { throw new \InvalidArgumentException('Unsupported replay driver or missing pdo_mysql'); }
        $dsn = (string) ($o['mysql_dsn'] ?? '');
        $user = (string) ($o['mysql_user'] ?? '');
        $password = (string) ($o['mysql_password'] ?? '');
        if (!str_starts_with($dsn, 'mysql:') || $user === '') { throw new \InvalidArgumentException('Configure dedicated MySQL replay credentials'); }
        return new MySqlReplayStore(static fn (): \PDO => new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_PERSISTENT => false, \PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_TIMEOUT => 3]), (string) ($o['mysql_table'] ?? 'dpop_proofs'));
    }

    public static function budget(ContainerInterface $c): AdmissionBudgetInterface
    {
        $o = self::config($c);
        return new RedisAdmissionBudget($c->get(RedisExecutorInterface::class), (string) ($o['namespace'] ?? 'dpop'), self::number($o, 'global_limit', 2000), self::number($o, 'source_limit', 120), self::number($o, 'budget_window', 60), self::number($o, 'max_concurrency', 16));
    }
}
