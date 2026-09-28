#!/usr/bin/env php
<?php
declare(strict_types=1);
// Backward-compatible command name; v2 imports both augments and resources.
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/import-live-snapshot.php');
passthru($command,$exitCode);
exit($exitCode);
