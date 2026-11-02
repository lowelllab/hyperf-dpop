<?php
declare(strict_types=1);
namespace Acme\HyperfDpop\Store;

use Acme\Dpop\Contract\ReplayStoreInterface;
use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\{SecurityPolicy, VerifiedProof};
use PDO;
use PDOException;

final class MySqlReplayStore implements ReplayStoreInterface, ReplayStoreProbeInterface
{
    private ?int $schemaCheckedPid = null;
    /** @param \Closure(): PDO $connect Must return a fresh, non-persistent, dedicated connection. */
    public function __construct(private readonly \Closure $connect, private readonly string $table = 'dpop_proofs')
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/D', $table) !== 1) { throw new \InvalidArgumentException('Unsafe replay table name'); }
    }

    public function reserve(string $domain, VerifiedProof $proof, int $verifiedAt, SecurityPolicy $policy): void
    {
        $pdo = null; $ownedTransaction = false;
        try {
            $pdo = ($this->connect)();
            if ($pdo->inTransaction() || (int) $pdo->getAttribute(PDO::ATTR_AUTOCOMMIT) !== 1) { throw new DpopException('dpop_security_unavailable'); }
            if ($this->schemaCheckedPid !== getmypid()) { $this->assertSchema($pdo); $this->schemaCheckedPid = getmypid(); }
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
            $pdo->beginTransaction();
            $ownedTransaction = true;
            $now = $this->now($pdo);
            $this->checkTime($now, $verifiedAt, $proof, $policy);
            $statement = $pdo->prepare("INSERT INTO `{$this->table}` (security_domain,jkt,jti_hash,accepted_at,expires_at) VALUES (?,?,?,?,?)");
            if (!$statement->execute([$domain, $proof->jkt, hash('sha256', $proof->jti, true), $now, $now + $policy->replayTtl])) { throw new DpopException('dpop_security_unavailable'); }
            $this->checkTime($this->now($pdo), $verifiedAt, $proof, $policy);
            if (!$pdo->commit()) { throw new DpopException('dpop_security_unavailable'); }
        } catch (\Throwable $error) {
            if ($ownedTransaction && $pdo instanceof PDO) {
                try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (\Throwable) {}
            }
            if ($error instanceof DpopException) { throw $error; }
            if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) { throw new DpopException('invalid_dpop_proof'); }
            throw new DpopException('dpop_security_unavailable');
        }
    }

    public function purge(int $limit = 1000): int
    {
        if ($limit < 1 || $limit > 10000) { throw new \InvalidArgumentException('Invalid cleanup batch'); }
        try {
            $pdo = ($this->connect)();
            if ($pdo->inTransaction() || (int) $pdo->getAttribute(PDO::ATTR_AUTOCOMMIT) !== 1) { throw new DpopException('dpop_security_unavailable'); }
            $count = $pdo->exec("DELETE FROM `{$this->table}` WHERE expires_at <= UNIX_TIMESTAMP() ORDER BY expires_at LIMIT {$limit}");
            if ($count === false) { throw new DpopException('dpop_security_unavailable'); }
            return $count;
        } catch (DpopException $e) { throw $e; }
        catch (\Throwable) { throw new DpopException('dpop_security_unavailable'); }
    }

    public function probe(): void
    {
        try { $this->assertSchema(($this->connect)()); }
        catch (\Throwable) { throw new DpopException('dpop_security_unavailable'); }
    }

    private function assertSchema(PDO $pdo): void
    {
            $pdo->query("SELECT security_domain,jkt,jti_hash,accepted_at,expires_at FROM `{$this->table}` LIMIT 0");
            $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $engine->execute([$this->table]);
            if (strtolower((string) $engine->fetchColumn()) !== 'innodb') { throw new \RuntimeException(); }
            $indexes = [];
            $prefixIndexes = [];
            foreach ($pdo->query("SHOW INDEX FROM `{$this->table}`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((int) $row['Non_unique'] === 0) {
                    $indexes[$row['Key_name']][(int) $row['Seq_in_index']] = $row['Column_name'];
                    if ($row['Sub_part'] !== null) { $prefixIndexes[$row['Key_name']] = true; }
                }
            }
            $unique = false;
            foreach ($indexes as $name => $columns) {
                ksort($columns);
                if (isset($prefixIndexes[$name]) || array_values($columns) !== ['security_domain', 'jkt', 'jti_hash']) { throw new \RuntimeException(); }
                $unique = true;
            }
            if (!$unique) { throw new \RuntimeException(); }
            $requiredTypes = ['security_domain' => '/^varchar\(96\)$/iD', 'jkt' => '/^char\(43\)$/iD', 'jti_hash' => '/^binary\(32\)$/iD', 'accepted_at' => '/^bigint(?:\([0-9]+\))? unsigned$/iD', 'expires_at' => '/^bigint(?:\([0-9]+\))? unsigned$/iD'];
            foreach ($pdo->query("SHOW FULL COLUMNS FROM `{$this->table}`")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $name = $column['Field'];
                if (isset($requiredTypes[$name]) && (preg_match($requiredTypes[$name], $column['Type']) !== 1 || $column['Null'] !== 'NO' || $column['Extra'] !== '')) { throw new \RuntimeException(); }
                if (in_array($name, ['security_domain', 'jkt'], true) && $column['Collation'] !== 'ascii_bin') { throw new \RuntimeException(); }
            }
            $this->now($pdo);
    }

    private function now(PDO $pdo): int { return (int) $pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn(); }

    public function __debugInfo(): array { return ['table' => $this->table, 'connection' => '[redacted factory]']; }

    private function checkTime(int $now, int $verifiedAt, VerifiedProof $proof, SecurityPolicy $policy): void
    {
        if ($now < 1 || abs($now - $verifiedAt) > $policy->clockSkew) { throw new DpopException('dpop_security_unavailable'); }
        $policy->assertProofTime($proof, $now);
    }
}
