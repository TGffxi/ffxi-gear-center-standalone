<?php
declare(strict_types=1);

final class Database
{
    public static function connect(string $dbPath): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('PHP extension pdo_sqlite is not loaded. Install/enable php-sqlite.');
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create data directory: ' . $dir);
        }

        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    public static function migrate(PDO $pdo, string $schemaFile): void
    {
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new RuntimeException('Could not read schema: ' . $schemaFile);
        }
        // Columns added after the initial CREATE TABLE must exist before the
        // schema recreates views that expose target_items via ti.*.  Keep this
        // migration explicit and idempotent so an update preserves Steve's DB.
        self::ensureColumn($pdo, 'target_items', 'chain_status', "TEXT NOT NULL DEFAULT 'PENDING'");
        $pdo->exec($sql);
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $exists=(bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=".$pdo->quote($table)." LIMIT 1")->fetchColumn();
        if(!$exists)return;
        foreach($pdo->query('PRAGMA table_info('.$table.')')->fetchAll() as $row){
            if(strcasecmp((string)($row['name']??''),$column)===0)return;
        }
        $pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
    }
}
