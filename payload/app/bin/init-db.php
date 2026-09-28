#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$root = dirname(__DIR__);
$config = Config::load($root);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo, $root . '/sql/schema.sql');
echo "Database ready: {$config['db_path']}\n";
