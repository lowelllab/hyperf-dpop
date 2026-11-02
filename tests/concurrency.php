<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Acme\Dpop\Exception\DpopException;
use Acme\Dpop\ValueObject\{SecurityPolicy, VerifiedProof};
use DpopTest\Support\Environment;

Environment::schema();
foreach (['mysql', 'redis'] as $driver) {
    $jti = bin2hex(random_bytes(16)); $timestamp = time(); $start = microtime(true) + 0.5; $children = [];
    for ($i = 0; $i < 12; ++$i) {
        $pid = pcntl_fork();
        if ($pid === -1) { throw new RuntimeException('fork failed'); }
        if ($pid === 0) {
            // New connections in each child: never inherit a PDO/Redis session across fork.
            while (microtime(true) < $start) { usleep(1000); }
            try { Environment::store($driver)->reserve('test:concurrency', new VerifiedProof(str_repeat('a', 43), $jti, $timestamp), time(), new SecurityPolicy()); exit(10); }
            catch (DpopException $e) { exit($e->error === 'invalid_dpop_proof' ? 11 : 12); }
            catch (Throwable) { exit(13); }
        }
        $children[] = $pid;
    }
    $accepted = 0; $duplicates = 0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status)) { throw new RuntimeException('child did not exit'); }
        $code = pcntl_wexitstatus($status);
        if ($code === 10) { ++$accepted; } elseif ($code === 11) { ++$duplicates; } else { throw new RuntimeException("Unexpected concurrency result: {$code}"); }
    }
    if ($accepted !== 1 || $duplicates !== 11) { throw new RuntimeException('Replay race detected'); }
    echo "{$driver}: 12 processes, 1 accepted, 11 rejected\n";
}
