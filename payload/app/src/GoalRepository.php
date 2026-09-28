<?php
declare(strict_types=1);

final class GoalRepository
{
    public function __construct(private PDO $pdo, private int $characterId) {}

    public function all(): array
    {
        $s=$this->pdo->prepare("SELECT * FROM goals WHERE character_id=:cid ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'planned' THEN 1 WHEN 'complete' THEN 2 ELSE 3 END, CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END, id");
        $s->execute([':cid'=>$this->characterId]);
        return $s->fetchAll();
    }

    public function upsert(string $entityType,string $entityKey,string $label,string $status='active',string $priority='normal',string $note='',string $origin='USER'): void
    {
        $now=gmdate('c');
        $sql="INSERT INTO goals(character_id,entity_type,entity_key,label,status,priority,origin,note,created_at,updated_at)
              VALUES(:cid,:et,:ek,:label,:status,:priority,:origin,:note,:now,:now)
              ON CONFLICT(character_id,entity_type,entity_key) DO UPDATE SET
                label=excluded.label,status=excluded.status,priority=excluded.priority,note=excluded.note,updated_at=excluded.updated_at";
        $s=$this->pdo->prepare($sql);
        $s->execute([':cid'=>$this->characterId,':et'=>$entityType,':ek'=>$entityKey,':label'=>$label,':status'=>$status,':priority'=>$priority,':origin'=>$origin,':note'=>$note,':now'=>$now]);
    }

    public function update(int $id,string $status,string $priority,string $note): void
    {
        $s=$this->pdo->prepare('UPDATE goals SET status=:status,priority=:priority,note=:note,updated_at=:now WHERE id=:id AND character_id=:cid');
        $s->execute([':status'=>$status,':priority'=>$priority,':note'=>$note,':now'=>gmdate('c'),':id'=>$id,':cid'=>$this->characterId]);
    }

    public function delete(int $id): void
    {
        $s=$this->pdo->prepare('DELETE FROM goals WHERE id=:id AND character_id=:cid');
        $s->execute([':id'=>$id,':cid'=>$this->characterId]);
    }
}
