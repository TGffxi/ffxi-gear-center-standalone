#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$job = 'ALL';
foreach (array_slice($argv, 1) as $arg) if (str_starts_with($arg, '--job=')) $job = strtoupper(trim(substr($arg, 6)));
if (!in_array($job, ['ALL','NIN','THF','COMMON'], true)) {
    fwrite(STDERR, "Unsupported coverage job: {$job}\n");
    exit(2);
}

$started = microtime(true);
$cache = null;
$ok = false;
try {
    $savedArgv = $argv;
    $argv = [__DIR__ . '/coverage-report.php', '--job=' . $job, '--coverage=all'];
    $_SERVER['argv'] = $argv;
    ob_start();
    include __DIR__ . '/coverage-report.php';
    $cliOutput = (string)ob_get_clean();
    $argv = $savedArgv;
    $_SERVER['argv'] = $argv;
    if (!isset($r, $config, $snap) || !is_array($r) || !is_array($config) || !is_array($snap)) {
        throw new RuntimeException('coverage-report.php did not expose its report context.');
    }
    $cache = new CoverageCache(
        $pdo, $root, (string)$config['db_path'], (int)$snap['character_id'], (int)$snap['id'],
        isset($bridge['id']) ? (int)$bridge['id'] : null,
    );
    $cache->publish($job, $r, microtime(true) - $started);
    $ok = true;
    file_put_contents($root . '/data/cache/coverage/' . $job . '.last-refresh.log',
        'OK ' . gmdate('c') . ' job=' . $job . ' duration=' . round(microtime(true)-$started, 3) . "s\n" . $cliOutput
    );
} catch (Throwable $e) {
    file_put_contents($root . '/data/cache/coverage/' . $job . '.last-refresh.log',
        'ERROR ' . gmdate('c') . ' job=' . $job . ' ' . $e->getMessage() . "\n"
    );
    fwrite(STDERR, 'Coverage cache refresh failed: ' . $e->getMessage() . "\n");
} finally {
    if ($cache instanceof CoverageCache) $cache->clearMarker($job);
    else {
        $marker = $root . '/data/cache/coverage/' . $job . '.refreshing';
        if (is_file($marker)) @unlink($marker);
    }
}
if (!$ok) exit(1);
echo "Coverage cache ready: {$job}\n";
