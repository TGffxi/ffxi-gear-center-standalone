<?php
declare(strict_types=1);

final class TaskRepository
{
    public function __construct(private PDO $pdo, private int $characterId) {}

    public function all(): array
    {
        $s=$this->pdo->prepare("SELECT * FROM tasks WHERE character_id=:cid ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'doing' THEN 1 ELSE 2 END, CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END, id DESC");
        $s->execute([':cid'=>$this->characterId]);
        return $this->enrichMany($s->fetchAll());
    }

    public function create(string $title,string $type='obtain',string $priority='normal',?string $content=null,string $note='',?string $entityType=null,?string $entityKey=null): int
    {
        $now=gmdate('c');$s=$this->pdo->prepare('INSERT INTO tasks(character_id,title,entity_type,entity_key,task_type,status,priority,content_name,note,created_at,updated_at) VALUES(:cid,:title,:et,:ek,:type,\'open\',:priority,:content,:note,:now,:now)');
        $s->execute([':cid'=>$this->characterId,':title'=>$title,':et'=>$entityType,':ek'=>$entityKey,':type'=>$type,':priority'=>$priority,':content'=>$content,':note'=>$note,':now'=>$now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createRoute(array $route,string $priority,string $itemName,string $itemNameNorm): int
    {
        $existing=$this->pdo->prepare("SELECT t.id FROM tasks t JOIN task_route_details d ON d.task_id=t.id WHERE t.character_id=:cid AND d.route_key=:rk AND t.status NOT IN ('done','ignored') LIMIT 1");
        $existing->execute([':cid'=>$this->characterId,':rk'=>(string)$route['key']]);
        $id=(int)($existing->fetchColumn()?:0);
        if($id>0)return $id;

        $this->pdo->beginTransaction();
        try{
            $id=$this->create((string)$route['title'],(string)$route['task_type'],$priority,$route['content_name']?:null,(string)($route['summary']??''),'item',$itemNameNorm);
            $now=gmdate('c');
            $d=$this->pdo->prepare('INSERT INTO task_route_details(task_id,route_key,route_kind,source_title,source_url,source_section,summary,meta_json,created_at) VALUES(:tid,:rk,:kind,:st,:su,:ss,:summary,:meta,:now)');
            $d->execute([':tid'=>$id,':rk'=>$route['key'],':kind'=>$route['kind'],':st'=>$route['source_title']?:null,':su'=>$route['source_url']?:null,':ss'=>$route['section']?:null,':summary'=>$route['summary']?:null,':meta'=>json_encode(['item'=>$itemName,'fields'=>$route['fields']??[]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':now'=>$now]);
            $si=$this->pdo->prepare('INSERT INTO task_steps(task_id,step_index,label,status,created_at,updated_at) VALUES(:tid,:idx,:label,\'open\',:now,:now)');
            foreach(array_values($route['steps']??[]) as $idx=>$label)$si->execute([':tid'=>$id,':idx'=>$idx,':label'=>(string)$label,':now'=>$now]);
            $this->pdo->commit();
            return $id;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function update(int $id,string $status,string $priority,string $note): void
    {
        $s=$this->pdo->prepare('UPDATE tasks SET status=:status,priority=:priority,note=:note,updated_at=:now WHERE id=:id AND character_id=:cid');
        $s->execute([':status'=>$status,':priority'=>$priority,':note'=>$note,':now'=>gmdate('c'),':id'=>$id,':cid'=>$this->characterId]);
    }

    public function updateStep(int $stepId,string $status): void
    {
        if(!in_array($status,['open','done'],true))throw new InvalidArgumentException('Invalid task step status.');
        $s=$this->pdo->prepare('UPDATE task_steps SET status=:status,updated_at=:now WHERE id=:sid AND task_id IN (SELECT id FROM tasks WHERE character_id=:cid)');
        $s->execute([':status'=>$status,':now'=>gmdate('c'),':sid'=>$stepId,':cid'=>$this->characterId]);
    }

    public function enrichMany(array $rows): array
    {
        foreach($rows as &$row)$row=$this->enrich($row);
        unset($row);
        return $rows;
    }

    public function enrich(array $row): array
    {
        $id=(int)$row['id'];
        $d=$this->pdo->prepare('SELECT * FROM task_route_details WHERE task_id=:id LIMIT 1');$d->execute([':id'=>$id]);
        $row['route']=$d->fetch()?:null;
        $s=$this->pdo->prepare('SELECT * FROM task_steps WHERE task_id=:id ORDER BY step_index');$s->execute([':id'=>$id]);
        $row['steps']=$s->fetchAll();
        $row['steps_done']=count(array_filter($row['steps'],static fn($x)=>$x['status']==='done'));
        return $row;
    }
}
