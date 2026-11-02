<?php
declare(strict_types=1);
namespace DpopTest;

use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\{SecurityPolicy, VerifiedProof};
use Acme\HyperfDpop\Admission\RedisAdmissionBudget;
use Acme\HyperfDpop\Store\MySqlReplayStore;
use DpopTest\Support\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    public static function setUpBeforeClass(): void { Environment::schema(); }
    public static function drivers(): array { return [['mysql'], ['redis']]; }
    private function proof(int $offset = 0): VerifiedProof { return new VerifiedProof(str_repeat('a', 43), bin2hex(random_bytes(16)), time() + $offset); }

    #[DataProvider('drivers')]
    public function testOneProofCannotBeAcceptedTwice(string $driver): void
    {
        $store = Environment::store($driver); $proof = $this->proof();
        $store->reserve('test:resource', $proof, time(), new SecurityPolicy());
        $this->expectExceptionMessage('invalid_dpop_proof');
        $store->reserve('test:resource', $proof, time(), new SecurityPolicy());
    }

    #[DataProvider('drivers')]
    public function testStoreRechecksIat(string $driver): void
    {
        $this->expectExceptionMessage('invalid_dpop_proof');
        Environment::store($driver)->reserve('test:resource', $this->proof(-61), time(), new SecurityPolicy());
    }

    #[DataProvider('drivers')]
    public function testStoreDetectsClockSkew(string $driver): void
    {
        $this->expectExceptionMessage('dpop_security_unavailable');
        Environment::store($driver)->reserve('test:resource', $this->proof(), time() - 20, new SecurityPolicy());
    }

    #[DataProvider('drivers')]
    public function testSecurityDomainsAreIndependent(string $driver): void
    {
        $store = Environment::store($driver); $proof = $this->proof();
        $store->reserve('test:resource', $proof, time(), new SecurityPolicy());
        $store->reserve('test:token', $proof, time(), new SecurityPolicy());
        self::assertTrue(true);
    }

    public function testDatabaseAcceptanceSurvivesHostRollback(): void
    {
        $host = Environment::pdo();
        $host->exec('CREATE TABLE IF NOT EXISTS dpop_business_fixture (id VARCHAR(64) PRIMARY KEY) ENGINE=InnoDB');
        $host->beginTransaction();
        $host->prepare('INSERT INTO dpop_business_fixture VALUES (?)')->execute([bin2hex(random_bytes(16))]);
        $proof = $this->proof(); $store = Environment::store('mysql');
        $store->reserve('test:resource', $proof, time(), new SecurityPolicy());
        $host->rollBack();
        $this->expectExceptionMessage('invalid_dpop_proof');
        $store->reserve('test:resource', $proof, time(), new SecurityPolicy());
    }

    public function testBorrowedHostTransactionIsRejectedWithoutRollingItBack(): void
    {
        $host = Environment::pdo(); $host->beginTransaction();
        try {
            (new MySqlReplayStore(static fn () => $host))->reserve('test:resource', $this->proof(), time(), new SecurityPolicy());
            self::fail('Expected rejection');
        } catch (DpopException $e) { self::assertSame('dpop_security_unavailable', $e->error); self::assertTrue($host->inTransaction()); }
        finally { if ($host->inTransaction()) { $host->rollBack(); } }
    }

    public function testDatabaseFailureIsNotAuthenticationSuccess(): void
    {
        $this->expectExceptionMessage('dpop_security_unavailable');
        (new MySqlReplayStore(static fn () => throw new \RuntimeException('synthetic outage')))->reserve('test:resource', $this->proof(), time(), new SecurityPolicy());
    }

    public function testCleanupOnlyDeletesExpiredRowsAndIsBounded(): void
    {
        $pdo = Environment::pdo(); $domain = 'cleanup:' . bin2hex(random_bytes(5));
        $insert = $pdo->prepare('INSERT INTO dpop_proofs VALUES (?,?,?,?,?)');
        for ($i = 0; $i < 3; ++$i) { $insert->execute([$domain, str_repeat('a', 43), random_bytes(32), time()-300, time()-100]); }
        self::assertSame(1, Environment::store('mysql')->purge(1));
        $count = $pdo->prepare('SELECT COUNT(*) FROM dpop_proofs WHERE security_domain=?'); $count->execute([$domain]);
        self::assertGreaterThanOrEqual(2, (int) $count->fetchColumn());
    }

    public function testAdmissionSlotsAreReleasedIdempotently(): void
    {
        $budget = new RedisAdmissionBudget(Environment::redis(), 'test-' . bin2hex(random_bytes(6)), 100, 100, 60, 1);
        $release = $budget->enter('127.0.0.1', 'resource');
        try { $budget->enter('127.0.0.1', 'resource'); self::fail(); }
        catch (DpopException $e) { self::assertSame('rate_limited', $e->error); }
        $release(); $release();
        $next = $budget->enter('127.0.0.1', 'resource'); $next(); self::assertTrue(true);
    }

    public function testGlobalBudgetDoesNotCreateUnboundedSourceKeysAfterLimit(): void
    {
        $budget = new RedisAdmissionBudget(Environment::redis(), 'test-' . bin2hex(random_bytes(6)), 1, 10);
        $release = $budget->enter('source-one', 'token'); $release();
        $this->expectExceptionMessage('rate_limited'); $budget->enter('source-two', 'resource');
    }

    public function testDoctorSchemaProbeIsReadOnlyAndRejectsMissingUniqueIndex(): void
    {
        $pdo = Environment::pdo(); $before = (int) $pdo->query('SELECT COUNT(*) FROM dpop_proofs')->fetchColumn();
        Environment::store('mysql')->probe();
        self::assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM dpop_proofs')->fetchColumn());
        $table = 'bad_schema_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE TABLE `{$table}` AS SELECT * FROM dpop_proofs LIMIT 0");
        try { (new MySqlReplayStore(Environment::pdo(...), $table))->reserve('test:resource', $this->proof(), time(), new SecurityPolicy()); self::fail('Missing unique index must be rejected even if doctor was skipped'); }
        catch (DpopException $e) { self::assertSame('dpop_security_unavailable', $e->error); }
        finally { $pdo->exec("DROP TABLE `{$table}`"); }
    }

    public function testRedisOutageReleasesAdmissionAndFailsClosed(): void
    {
        $broken = new class implements \Acme\HyperfDpop\Store\RedisExecutorInterface {
            public function evaluate(string $script, array $keys, array $arguments): mixed { throw new \RuntimeException('synthetic outage'); }
            public function ping(): bool { return false; }
        };
        $budget = new RedisAdmissionBudget($broken, concurrency: 1);
        for ($i=0;$i<2;++$i) {
            try { $budget->enter('source','resource'); self::fail(); }
            catch (DpopException $e) { self::assertSame('dpop_security_unavailable',$e->error); }
        }
        $this->expectExceptionMessage('dpop_security_unavailable');
        (new \Acme\HyperfDpop\Store\RedisReplayStore($broken))->reserve('test:resource',$this->proof(),time(),new SecurityPolicy());
    }
}
