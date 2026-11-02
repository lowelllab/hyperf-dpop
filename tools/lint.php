<?php
declare(strict_types=1);
$root = dirname(__DIR__); $count = 0;
foreach (['packages', 'tests', 'examples', 'tools'] as $directory) {
    if (!is_dir($root . '/' . $directory)) { continue; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $code);
        if ($code !== 0) { exit($code); } ++$count;
    }
}
echo "PHP syntax: {$count} files\n";
