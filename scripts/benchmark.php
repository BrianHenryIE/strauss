#!/usr/bin/env php
<?php
/**
 * Times Strauss on tests/Benchmark (sylius/sylius: 175 packages, ~15,000 PHP files), in one process and in
 * parallel, without and with the analysis cache, and prints how long each pipeline step took.
 *
 * The first run installs the benchmark project's dependencies with Composer.
 *
 *   php scripts/benchmark.php            # --parallel=1, then --parallel (one worker per spare CPU)
 *   php scripts/benchmark.php 4          # --parallel=1, then --parallel=4
 *   php scripts/benchmark.php 1          # --parallel=1 only
 *
 * The first two runs use `--cache=false`. They are followed by two runs with the analysis cache, in an empty
 * temporary directory so the first is cold and the second warm, and a warm run with `--parallel=1`.
 */

declare(strict_types=1);

$benchmarkDir = dirname(__DIR__) . '/tests/Benchmark';
$strauss = dirname(__DIR__) . '/bin/strauss';
$workers = $argv[1] ?? 'true';

chdir($benchmarkDir);

if (!is_dir($benchmarkDir . '/vendor')) {
    echo "Installing the benchmark project's dependencies...\n";
    passthru('composer install --no-dev --no-interaction --no-progress', $exitCode);
    if (0 !== $exitCode) {
        fwrite(STDERR, "composer install failed.\n");
        exit($exitCode);
    }
}

/**
 * @return array{seconds: float, steps: array<string, float>, workers: ?string}
 */
$runStrauss = function (string $strauss, string $parallel, ?string $cacheDir = null): array {
    shell_exec('rm -rf vendor-prefixed vendor/composer/autoload_aliases.php');

    $command = sprintf(
        '%s%s -d memory_limit=-1 %s --debug --parallel=%s --cache=%s 2>&1',
        is_null($cacheDir) ? '' : 'COMPOSER_CACHE_DIR=' . escapeshellarg($cacheDir) . ' ',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($strauss),
        escapeshellarg($parallel),
        is_null($cacheDir) ? 'false' : 'true'
    );

    $startedAt = microtime(true);
    $process = popen($command, 'r');
    if (false === $process) {
        throw new RuntimeException('Could not start Strauss.');
    }

    $steps = [];
    $workers = null;
    while (false !== ($line = fgets($process))) {
        if (preg_match('/\] (\w+) took ([\d.,]+)s/', $line, $matches)) {
            $steps[$matches[1]] = (float) str_replace(',', '', $matches[2]);
        } elseif (preg_match('/Analysing \d+ files with \d+ worker processes/', $line, $matches)) {
            $workers = $matches[0];
        } elseif (preg_match('/Fatal error|Please submit a bug report/', $line)) {
            fwrite(STDERR, $line);
        }
    }
    $exitCode = pclose($process);
    if (0 !== $exitCode) {
        throw new RuntimeException("Strauss exited with code $exitCode.");
    }

    return ['seconds' => microtime(true) - $startedAt, 'steps' => $steps, 'workers' => $workers];
};

$runs = ['--parallel=1' => $runStrauss($strauss, '1')];
if ('1' !== $workers) {
    $runs["--parallel=$workers"] = $runStrauss($strauss, $workers);
}

$cacheDir = sys_get_temp_dir() . '/strauss-benchmark-cache-' . bin2hex(random_bytes(4));
$runs['cache, cold'] = $runStrauss($strauss, $workers, $cacheDir);
$runs['cache, warm'] = $runStrauss($strauss, $workers, $cacheDir);
if ('1' !== $workers) {
    $runs['warm, 1 proc.'] = $runStrauss($strauss, '1', $cacheDir);
}
$cacheSize = trim((string) shell_exec('du -sh ' . escapeshellarg($cacheDir) . ' | cut -f1'));
shell_exec('rm -rf ' . escapeshellarg($cacheDir));

$stepNames = array_keys(reset($runs)['steps']);
$width = max(array_map('strlen', array_merge($stepNames, ['total'])));

printf("%-{$width}s", '');
foreach (array_keys($runs) as $label) {
    printf(" %15s", $label);
}
echo "\n";

foreach ($stepNames as $step) {
    printf("%-{$width}s", $step);
    foreach ($runs as $run) {
        printf(" %14.1fs", $run['steps'][$step] ?? 0.0);
    }
    echo "\n";
}

printf("%-{$width}s", 'total');
foreach ($runs as $run) {
    printf(" %14.1fs", $run['seconds']);
}
echo "\n";

foreach ($runs as $label => $run) {
    if ($run['workers']) {
        echo "\n$label: {$run['workers']}\n";
    }
}

echo "\nThe cold and warm cache runs use --parallel=$workers. Analysis cache size: $cacheSize\n";
