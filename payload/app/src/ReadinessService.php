<?php
declare(strict_types=1);

final class ReadinessService
{
    public function __construct(private PDO $pdo, private RequirementMatcher $matcher) {}
    public function profile(int $profileId): array
    {
        $s=$this->pdo->prepare('SELECT * FROM lac_sets WHERE profile_snapshot_id=:pid ORDER BY category,raw_name');$s->execute([':pid'=>$profileId]);$sets=$s->fetchAll();$groups=[];$tot=['sets'=>0,'requirements'=>0,'present'=>0,'done'=>0,'unverified'=>0,'mismatch'=>0];
        foreach($sets as &$set){$p=$this->matcher->setProgress((int)$set['id']);$set['progress']=$p;$g=$set['activation_type'];if(!isset($groups[$g]))$groups[$g]=['sets'=>0,'requirements'=>0,'present'=>0,'done'=>0,'unverified'=>0,'mismatch'=>0];foreach(['requirements'=>'total','present'=>'present','done'=>'done','unverified'=>'unverified','mismatch'=>'mismatch'] as $dst=>$src){$groups[$g][$dst]+=$p[$src];$tot[$dst]+=$p[$src];}$groups[$g]['sets']++;$tot['sets']++;}unset($set);
        foreach($groups as &$g){$g['present_percent']=$g['requirements']?round($g['present']*100/$g['requirements']):100;$g['verified_percent']=$g['requirements']?round($g['done']*100/$g['requirements']):100;}unset($g);
        $tot['present_percent']=$tot['requirements']?round($tot['present']*100/$tot['requirements']):100;$tot['verified_percent']=$tot['requirements']?round($tot['done']*100/$tot['requirements']):100;
        return ['sets'=>$sets,'groups'=>$groups,'total'=>$tot];
    }
}
