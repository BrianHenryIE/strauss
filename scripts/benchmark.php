#!/usr/bin/env php
<?php
/**
 * Times Strauss on tests/Benchmark (sylius/sylius: 175 packages, ~15,000 PHP files), in one process and in
 * parallel, and prints how long each pipeline step took.
 *
 * The first run installs the benchmark project's dependencies with Composer.
 *
 *   php scripts/benchmark.php            # --parallel=1, then --parallel (one worker per spare CPU)
 *   php scripts/benchmark.php 4          # --parallel=1, then --parallel=4
 *   php scripts/benchmark.php 1          # --parallel=1 only
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
$runStrauss = function (string $strauss, string $parallel): array {
    shell_exec('rm -rf vendor-prefixed vendor/composer/autoload_aliases.php');

    $command = sprintf(
        '%s -d memory_limit=-1 %s --debug --parallel=%s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($strauss),
        escapeshellarg($parallel)
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

$runs = ['1' => $runStrauss($strauss, '1')];
if ('1' !== $workers) {
    $runs[$workers] = $runStrauss($strauss, $workers);
}

$stepNames = array_keys(reset($runs)['steps']);
$width = max(array_map('strlen', array_merge($stepNames, ['total'])));

printf("%-{$width}s", '');
foreach (array_keys($runs) as $parallel) {
    printf(" %14s", "--parallel=$parallel");
}
echo "\n";

foreach ($stepNames as $step) {
    printf("%-{$width}s", $step);
    foreach ($runs as $run) {
        printf(" %13.1fs", $run['steps'][$step] ?? 0.0);
    }
    echo "\n";
}

printf("%-{$width}s", 'total');
foreach ($runs as $run) {
    printf(" %13.1fs", $run['seconds']);
}
echo "\n";

foreach ($runs as $parallel => $run) {
    if ($run['workers']) {
        echo "\n--parallel=$parallel: {$run['workers']}\n";
    }
}
