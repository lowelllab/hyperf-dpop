<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mode = $argv[1] ?? 'candidate';
$resolution = $argv[2] ?? 'default';
if (!in_array($mode, ['candidate', 'published'], true) || !in_array($resolution, ['default', 'lowest'], true)) {
    throw new InvalidArgumentException('Expected candidate|published and default|lowest');
}

$readJson = static fn(string $file): array => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$directory = realpath($readJson($root . '/build/packagist-latest.json')['directory']);
$prefix = realpath($root . '/build/packagist') . DIRECTORY_SEPARATOR;
if ($directory === false || !str_starts_with($directory, $prefix)) {
    throw new RuntimeException('Unexpected package output path');
}

$manifest = $readJson($directory . '/manifest.json')['packages'];
$names = ['lowelllab/dpop-core', 'lowelllab/hyperf-dpop'];
$versions = array_combine($names, [$argv[3] ?? '0.2.0', $argv[4] ?? '0.3.0']);
$references = array_combine($names, [$argv[5] ?? '', $argv[6] ?? '']);
foreach ($names as $name) {
    if (preg_match('/^\d+\.\d+\.\d+$/D', $versions[$name]) !== 1 ||
        ($mode === 'published' && preg_match('/^[a-f0-9]{40}$/D', $references[$name]) !== 1)) {
        throw new InvalidArgumentException('Supply exact package versions and published commit hashes');
    }
}

$parent = $directory . '/consumer-' . $mode . '-' . $resolution;
if (!mkdir($parent, 0700)) {
    throw new RuntimeException('Prepare a fresh export for each consumer verification');
}
$log = $parent . '/verification.log';
$run = static function(array $command, string $cwd) use ($log): void {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start consumer verification command');
    }
    fclose($pipes[0]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Consumer verification command failed. See ' . $log);
    }
};

$checkFiles = static function(array $files, string $path): void {
    foreach ($files as $file => $expected) {
        if (!is_file($path . '/' . $file) || hash_file('sha256', $path . '/' . $file) !== $expected) {
            throw new RuntimeException('Package checksum mismatch: ' . $file);
        }
    }
};

$repositories = [];
foreach ($names as $name) {
    $package = $manifest[$name];
    $path = $package['directory'];
    $checkFiles($package['files'], $path);
    $run([PHP_BINARY, '/usr/local/bin/composer', 'validate', '--strict'], $path);
    if ($mode === 'candidate') {
        if (file_exists($path . '/.git')) {
            throw new RuntimeException('Prepare a fresh export before creating candidate tags');
        }
        $run(['git', 'init', '--quiet', '-b', 'main'], $path);
        $run(['git', 'add', '--all'], $path);
        $run(['git', '-c', 'user.name=Package fixture', '-c', 'user.email=package-fixture@example.invalid',
            'commit', '--quiet', '-m', 'Compatibility release candidate'], $path);
        $run(['git', 'tag', 'v' . $versions[$name]], $path);
        $references[$name] = trim(file_get_contents($path . '/.git/refs/tags/v' . $versions[$name]));
        $repositories[] = ['type' => 'vcs', 'url' => $path];
    }
}

$copy = static function(string $source, string $target): void {
    $paths = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        static fn(SplFileInfo $item): bool => $item->getFilename() !== 'runtime'
    );
    foreach (new RecursiveIteratorIterator($paths) as $item) {
        $destination = $target . substr($item->getPathname(), strlen($source));
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        if (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException('Cannot copy consumer test fixture');
        }
    }
};

foreach (['core', 'adapter'] as $type) {
    $consumer = $parent . '/' . $type;
    mkdir($consumer, 0700);
    $copy($root . '/tests', $consumer . '/tests');
    $requires = [$names[0] => $versions[$names[0]]];
    $autoload = ['DpopTest\\' => 'tests/'];
    $php = [PHP_BINARY, '-n'];
    if ($type === 'adapter') {
        $requires += [$names[1] => $versions[$names[1]], 'hyperf/di' => '~3.2.0', 'hyperf/redis' => '~3.2.0'];
        $autoload['DpopExample\\'] = 'examples/hyperf/app/';
        $php = [PHP_BINARY];
        $copy($root . '/examples/hyperf', $consumer . '/examples/hyperf');
        mkdir($consumer . '/tools', 0700);
        copy($root . '/tools/setup-example.php', $consumer . '/tools/setup-example.php');
    }

    $metadata = [
        'name' => 'fixture/dpop-' . $type,
        'require' => $requires,
        'autoload' => ['psr-4' => $autoload],
        'config' => ['allow-plugins' => new stdClass()],
    ];
    if ($repositories !== []) {
        $metadata['repositories'] = $repositories;
    }
    file_put_contents($consumer . '/composer.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $composer = [...$php, '/usr/local/bin/composer'];
    $flags = $resolution === 'lowest' ? ['--prefer-lowest', '--prefer-stable'] : [];
    $run([...$composer, 'update', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist', ...$flags], $consumer);
    $run([...$composer, 'check-platform-reqs', '--no-dev'], $consumer);
    $run([...$composer, 'audit', '--locked', '--no-dev'], $consumer);

    $installed = (require $consumer . '/vendor/composer/installed.php')['versions'];
    $locked = array_column($readJson($consumer . '/composer.lock')['packages'], null, 'name');
    foreach (array_intersect($names, array_keys($requires)) as $name) {
        $path = realpath($installed[$name]['install_path'] ?? '');
        if ($path === false || !str_starts_with($path, $consumer . '/vendor/') ||
            ($locked[$name]['source']['reference'] ?? null) !== $references[$name]) {
            throw new RuntimeException('Consumer did not install the expected package commit: ' . $name);
        }
        $checkFiles($manifest[$name]['files'], $path);
    }
    if (isset($installed['phpunit/phpunit']) || isset($installed['hyperf/devtool']) ||
        ($type === 'core' && isset($installed['hyperf/framework']))) {
        throw new RuntimeException('Unexpected dependencies in the clean consumer');
    }
    if ($type === 'core') {
        $run([...$php, '/usr/local/bin/phpunit', '--bootstrap', 'vendor/autoload.php',
            '--display-deprecations', '--display-phpunit-deprecations', '--fail-on-deprecation',
            '--fail-on-phpunit-deprecation', '--fail-on-warning', '--fail-on-risky', '--fail-on-skipped',
            'tests/CoreTest.php', 'tests/InteropTest.php'], $consumer);
    }
}

file_put_contents($parent . '/verification.json', json_encode([
    'php' => PHP_VERSION,
    'mode' => $mode,
    'resolution' => $resolution,
    'packages' => $versions,
    'references' => $references,
    'core_tests' => 'pass',
    'platform_and_audit' => 'pass',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo '.' . substr($parent, strlen($root)) . "/adapter\n";
