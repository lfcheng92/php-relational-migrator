<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$count = 0;
foreach (['src', 'tests', 'examples', 'tools'] as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) { continue; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') { continue; }
        $command = [PHP_BINARY, '-l', $file->getPathname()];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start PHP lint.'); }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { fwrite(STDERR, $output); exit(1); }
        ++$count;
    }
}
echo "PHP syntax OK: $count files", PHP_EOL;
