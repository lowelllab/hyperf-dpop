<?php
declare(strict_types=1);
namespace DpopExample;

use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\TokenBinding;

/** Example-only session model, intentionally outside both packages. */
final class State
{
    public function pdo(): \PDO
    {
        return new \PDO((string) getenv('DPOP_TEST_MYSQL_DSN'), (string) getenv('DPOP_TEST_MYSQL_USER'), (string) getenv('DPOP_TEST_MYSQL_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
    }

    public function issue(string $subject, string $jkt): array
    {
        $access = bin2hex(random_bytes(32)); $refresh = bin2hex(random_bytes(32));
        $this->pdo()->prepare('INSERT INTO example_sessions(access_hash,refresh_hash,jkt,subject,expires_at,revoked) VALUES (?,?,?,?,?,0)')->execute([hash('sha256', $access), hash('sha256', $refresh), $jkt, $subject, time()+600]);
        return ['access_token'=>$access, 'refresh_token'=>$refresh, 'token_type'=>'DPoP', 'expires_in'=>600];
    }

    public function find(#[\SensitiveParameter] string $token, bool $refresh = false): TokenBinding
    {
        $column = $refresh ? 'refresh_hash' : 'access_hash';
        $statement = $this->pdo()->prepare("SELECT * FROM example_sessions WHERE {$column}=? AND revoked=0 AND expires_at>UNIX_TIMESTAMP()");
        $statement->execute([hash('sha256', $token)]); $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { throw new DpopException($refresh ? 'invalid_grant' : 'invalid_token'); }
        return new TokenBinding($row['jkt'], $row['subject']);
    }

    public function rotate(#[\SensitiveParameter] string $refresh, string $jkt): array
    {
        $pdo = $this->pdo(); $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT * FROM example_sessions WHERE refresh_hash=? FOR UPDATE');
            $statement->execute([hash('sha256', $refresh)]); $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if (!$row || $row['revoked'] || $row['expires_at'] <= time() || !hash_equals($row['jkt'], $jkt)) { throw new DpopException('invalid_grant'); }
            $access = bin2hex(random_bytes(32)); $nextRefresh = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE example_sessions SET access_hash=?,refresh_hash=? WHERE refresh_hash=?')->execute([hash('sha256', $access), hash('sha256', $nextRefresh), hash('sha256', $refresh)]);
            $pdo->commit();
            return ['access_token'=>$access,'refresh_token'=>$nextRefresh,'token_type'=>'DPoP'];
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    }

    public function revoke(string $subject): void { $this->pdo()->prepare('UPDATE example_sessions SET revoked=1 WHERE subject=?')->execute([$subject]); }
}
