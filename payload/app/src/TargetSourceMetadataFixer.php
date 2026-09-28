<?php
declare(strict_types=1);

/**
 * Normalizes source provenance for the structured IzaKastra/bg_job_guides
 * target import without touching personal goals/tasks or target membership.
 *
 * It is deliberately schema-tolerant: v0.4 snapshots evolved during Alpha,
 * so only tables whose name contains "target" and which expose a job column
 * plus recognized provenance columns are changed.
 */
final class TargetSourceMetadataFixer
{
    /** @param array<int,string> $jobs */
    public static function fix(PDO $pdo, array $jobs=[]): array
    {
        $allJobs=array_keys(AllJobsTargetParser::jobs());
        if(!$jobs) $jobs=$allJobs;
        $jobs=array_values(array_intersect($allJobs,array_map(static fn($j)=>strtoupper(trim((string)$j)),$jobs)));

        $tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $result=['tables'=>[],'rows'=>0,'jobs'=>$jobs];

        foreach($tables as $table){
            $table=(string)$table;
            if(stripos($table,'target')===false) continue;
            $cols=self::columns($pdo,$table);
            if(!$cols) continue;

            $jobCol=self::first($cols,['job','job_code','job_abbr','job_key']);
            if($jobCol===null) continue;

            $titleCols=self::present($cols,['page_title','source_page_title','source_title','guide_page_title']);
            $kindCols=self::present($cols,['source_kind','page_source_kind']);
            $urlCols=self::present($cols,['source_url','page_url','source_page_url','guide_url']);
            $labelCols=self::present($cols,['source_label','page_source_label']);
            if(!$titleCols && !$kindCols && !$urlCols && !$labelCols) continue;

            $updates=0;
            foreach($jobs as $job){
                $set=[];$params=[':job'=>$job];
                $code=strtolower($job);
                $title='bg_job_guides/'.$code.'.md';
                $url='https://github.com/IzaKastra/bg_job_guides/blob/main/'.$code.'.md';
                $kind='izakastra_bg_job_guides_markdown';
                $label='IzaKastra bg_job_guides';

                foreach($titleCols as $i=>$c){$k=':t'.$i;$set[]=self::qi($c).'='.$k;$params[$k]=$title;}
                foreach($kindCols as $i=>$c){$k=':k'.$i;$set[]=self::qi($c).'='.$k;$params[$k]=$kind;}
                foreach($urlCols as $i=>$c){$k=':u'.$i;$set[]=self::qi($c).'='.$k;$params[$k]=$url;}
                foreach($labelCols as $i=>$c){$k=':l'.$i;$set[]=self::qi($c).'='.$k;$params[$k]=$label;}
                if(!$set) continue;

                $sql='UPDATE '.self::qi($table).' SET '.implode(', ',$set).' WHERE UPPER('.self::qi($jobCol).')=:job';
                $st=$pdo->prepare($sql);$st->execute($params);$updates+=$st->rowCount();
            }
            if($updates>0){$result['tables'][$table]=$updates;$result['rows']+=$updates;}
        }
        return $result;
    }

    /** @return array<int,string> */
    private static function columns(PDO $pdo,string $table): array
    {
        $rows=$pdo->query('PRAGMA table_info('.self::qi($table).')')?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_values(array_map(static fn($r)=>(string)$r['name'],$rows));
    }
    /** @param array<int,string> $cols @param array<int,string> $wanted */
    private static function first(array $cols,array $wanted): ?string
    {
        $map=array_change_key_case(array_fill_keys($cols,true),CASE_LOWER);
        foreach($wanted as $w) if(isset($map[strtolower($w)])) foreach($cols as $c) if(strcasecmp($c,$w)===0) return $c;
        return null;
    }
    /** @param array<int,string> $cols @param array<int,string> $wanted @return array<int,string> */
    private static function present(array $cols,array $wanted): array
    {
        $out=[];foreach($wanted as $w)foreach($cols as $c)if(strcasecmp($c,$w)===0){$out[]=$c;break;}return array_values(array_unique($out));
    }
    private static function qi(string $id): string { return '"'.str_replace('"','""',$id).'"'; }
}
