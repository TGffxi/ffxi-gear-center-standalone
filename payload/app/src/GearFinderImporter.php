<?php
declare(strict_types=1);

final class GearFinderImporter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function import(array $source, bool $force = false): array
    {
        $gearPath = $source['gear'];
        $resPath = $source['res'];
        $gearHash = GearFinderNormalizer::hashFile($gearPath);
        $resHash = GearFinderNormalizer::hashFile($resPath);
        $gear = GearFinderNormalizer::readJsonArray($gearPath);
        $res = GearFinderNormalizer::readJsonArray($resPath);
        $resourceMap = GearFinderNormalizer::resourceMap($res);
        $warnings = GearFinderNormalizer::validateGear($gear, $resourceMap);
        $summary = GearFinderNormalizer::summary($gear, $resourceMap);

        $characterId = $this->upsertCharacter((string)$source['character'], $source['server_id']);
        $previous = $this->latestSnapshot($characterId);
        $previousRun = $this->latestSuccessfulRun($characterId);
        if (!$force && $previous !== null && $previousRun !== null
            && hash_equals((string)($previousRun['gear_sha256'] ?? ''), $gearHash)
            && hash_equals((string)($previousRun['res_sha256'] ?? ''), $resHash)) {
            return [
                'snapshot_id' => (int)$previous['id'],
                'character_id' => $characterId,
                'captured_at' => (string)$previous['captured_at'],
                'summary' => $summary,
                'warnings' => $warnings,
                'gear_sha256' => $gearHash,
                'res_sha256' => $resHash,
                'skipped' => true,
            ];
        }
        if (!$force && $previous !== null && (int)$previous['item_count'] >= 50) {
            $previousCount = (int)$previous['item_count'];
            $currentCount = count($gear);
            if ($currentCount < (int)floor($previousCount * 0.50)) {
                throw new RuntimeException(sprintf(
                    'Snapshot rejected as implausibly small: previous=%d current=%d. Use --force only if intentional.',
                    $previousCount,
                    $currentCount
                ));
            }
        }

        $now = gmdate('c');
        $capturedTs = max(filemtime($gearPath) ?: time(), filemtime($resPath) ?: time());
        $capturedAt = gmdate('c', $capturedTs);

        $this->pdo->beginTransaction();
        try {
            $runStmt = $this->pdo->prepare(
                'INSERT INTO import_runs(character_id, source_type, started_at, status, gear_path, res_path, gear_sha256, res_sha256, warning_count) ' .
                'VALUES(:character_id, :source_type, :started_at, :status, :gear_path, :res_path, :gear_sha256, :res_sha256, :warning_count)'
            );
            $runStmt->execute([
                ':character_id' => $characterId,
                ':source_type' => 'gearfinder',
                ':started_at' => $now,
                ':status' => 'running',
                ':gear_path' => $gearPath,
                ':res_path' => $resPath,
                ':gear_sha256' => $gearHash,
                ':res_sha256' => $resHash,
                ':warning_count' => count($warnings),
            ]);
            $runId = (int)$this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO item_definitions(item_id, name, jobs_mask, equip_slots_mask, level, item_level, superior_level, stats_json, raw_resource_json, first_seen_at, last_seen_at) ' .
                'VALUES(:item_id, :name, :jobs_mask, :equip_slots_mask, :level, :item_level, :superior_level, :stats_json, :raw_resource_json, :now, :now) ' .
                'ON CONFLICT(item_id) DO UPDATE SET ' .
                'name=excluded.name, jobs_mask=excluded.jobs_mask, equip_slots_mask=excluded.equip_slots_mask, level=excluded.level, ' .
                'item_level=excluded.item_level, superior_level=excluded.superior_level, stats_json=excluded.stats_json, ' .
                'raw_resource_json=excluded.raw_resource_json, last_seen_at=excluded.last_seen_at'
            );
            foreach ($resourceMap as $row) {
                $itemStmt->execute([
                    ':item_id' => (int)$row['id'],
                    ':name' => (string)$row['name'],
                    ':jobs_mask' => (int)$row['jobs'],
                    ':equip_slots_mask' => (int)$row['equipSlots'],
                    ':level' => (int)$row['lvl'],
                    ':item_level' => (int)$row['ilvl'],
                    ':superior_level' => (int)$row['su'],
                    ':stats_json' => GearFinderNormalizer::normalizedResourceStatsJson($row),
                    ':raw_resource_json' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':now' => $now,
                ]);
            }

            $snapStmt = $this->pdo->prepare(
                'INSERT INTO snapshots(character_id, import_run_id, source_type, captured_at, imported_at, item_count, unique_item_count, augmented_item_count, porter_slip_count) ' .
                'VALUES(:character_id, :run_id, :source_type, :captured_at, :imported_at, :item_count, :unique_item_count, :augmented_item_count, :porter_slip_count)'
            );
            $snapStmt->execute([
                ':character_id' => $characterId,
                ':run_id' => $runId,
                ':source_type' => 'gearfinder',
                ':captured_at' => $capturedAt,
                ':imported_at' => $now,
                ':item_count' => $summary['gear_entries'],
                ':unique_item_count' => $summary['unique_item_ids'],
                ':augmented_item_count' => $summary['augmented_entries'],
                ':porter_slip_count' => $summary['porter_slip_entries'],
            ]);
            $snapshotId = (int)$this->pdo->lastInsertId();

            $instStmt = $this->pdo->prepare(
                'INSERT INTO equipment_snapshot_items(snapshot_id, item_id, bag_id, bag_name, bag_slot, slip_id, working, augment_signature, augment_stats_json, raw_source_json) ' .
                'VALUES(:snapshot_id, :item_id, :bag_id, :bag_name, :bag_slot, :slip_id, :working, :augment_signature, :augment_stats_json, :raw_source_json)'
            );
            foreach ($gear as $row) {
                $instStmt->execute([
                    ':snapshot_id' => $snapshotId,
                    ':item_id' => (int)$row['id'],
                    ':bag_id' => (int)$row['bag'],
                    ':bag_name' => BagMap::name((int)$row['bag']),
                    ':bag_slot' => (int)$row['slot'],
                    ':slip_id' => (int)$row['slip'],
                    ':working' => !empty($row['working']) ? 1 : 0,
                    ':augment_signature' => GearFinderNormalizer::augmentSignature($row),
                    ':augment_stats_json' => GearFinderNormalizer::normalizedAugmentJson($row),
                    ':raw_source_json' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $finish = $this->pdo->prepare(
                'UPDATE import_runs SET completed_at=:completed_at, status=:status, item_count=:item_count, warning_json=:warning_json WHERE id=:id'
            );
            $finish->execute([
                ':completed_at' => gmdate('c'),
                ':status' => 'success',
                ':item_count' => count($gear),
                ':warning_json' => json_encode($warnings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ':id' => $runId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'snapshot_id' => $snapshotId,
            'character_id' => $characterId,
            'captured_at' => $capturedAt,
            'summary' => $summary,
            'warnings' => $warnings,
            'gear_sha256' => $gearHash,
            'res_sha256' => $resHash,
        ];
    }

    private function upsertCharacter(string $name, ?int $serverId): int
    {
        $now = gmdate('c');
        if ($serverId === null) {
            $find = $this->pdo->prepare('SELECT id FROM characters WHERE lower(name)=lower(:name) AND server_id IS NULL LIMIT 1');
            $find->execute([':name' => $name]);
            $id = $find->fetchColumn();
            if ($id !== false) {
                $this->pdo->prepare('UPDATE characters SET updated_at=:now WHERE id=:id')->execute([':now' => $now, ':id' => $id]);
                return (int)$id;
            }
        } else {
            $find = $this->pdo->prepare('SELECT id FROM characters WHERE lower(name)=lower(:name) AND server_id=:server_id LIMIT 1');
            $find->execute([':name' => $name, ':server_id' => $serverId]);
            $id = $find->fetchColumn();
            if ($id !== false) {
                $this->pdo->prepare('UPDATE characters SET updated_at=:now WHERE id=:id')->execute([':now' => $now, ':id' => $id]);
                return (int)$id;
            }
        }

        $stmt = $this->pdo->prepare('INSERT INTO characters(name, server_id, created_at, updated_at) VALUES(:name, :server_id, :now, :now)');
        $stmt->execute([':name' => $name, ':server_id' => $serverId, ':now' => $now]);
        return (int)$this->pdo->lastInsertId();
    }

    private function latestSuccessfulRun(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM import_runs WHERE character_id=:character_id AND source_type='gearfinder' AND status='success' ORDER BY id DESC LIMIT 1");
        $stmt->execute([':character_id' => $characterId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function latestSnapshot(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM snapshots WHERE character_id=:character_id ORDER BY captured_at DESC, id DESC LIMIT 1');
        $stmt->execute([':character_id' => $characterId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
