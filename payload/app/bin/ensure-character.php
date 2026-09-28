#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$root = dirname(__DIR__);
$name = trim((string)($argv[1] ?? ''));
if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
    fwrite(STDERR, "Usage: php bin/ensure-character.php PlayerName\n");
    exit(2);
}
$config = Config::load($root);
$pdo = Database::connect((string)$config['db_path']);
Database::migrate($pdo, $root . '/sql/schema.sql');
$now = gmdate('c');
$q = $pdo->prepare('SELECT id FROM characters WHERE lower(name)=lower(:name) ORDER BY id LIMIT 1');
$q->execute([':name'=>$name]);
$id = (int)($q->fetchColumn() ?: 0);
if ($id > 0) {
    $u = $pdo->prepare('UPDATE characters SET updated_at=:now WHERE id=:id');
    $u->execute([':now'=>$now, ':id'=>$id]);
    echo "Character ready: {$name} (#{$id})\n";
    exit(0);
}
$i = $pdo->prepare('INSERT INTO characters(name,server_id,created_at,updated_at) VALUES(:name,NULL,:now,:now)');
$i->execute([':name'=>$name, ':now'=>$now]);
echo "Character created: {$name} (#" . (int)$pdo->lastInsertId() . ")\n";
