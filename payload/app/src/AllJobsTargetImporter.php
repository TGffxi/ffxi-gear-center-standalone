<?php
declare(strict_types=1);

final class AllJobsTargetImporter
{
    public function __construct(private PDO $pdo){}

    /** @return array<string,mixed> */
    public function importJobs(array $jobs): array
    {
        $repo=new TargetRepository($this->pdo);$sourceId=$repo->ensureAllJobsSource();$client=new BgWikiClient();$parser=new AllJobsTargetParser();
        $result=['ok'=>[],'errors'=>[],'sets'=>0,'targets'=>0];
        foreach($jobs as $job){
            $job=strtoupper(trim((string)$job));
            try{
                $title=AllJobsTargetParser::pageTitle($job);$page=$client->fetchPage($title);
                if(empty($page['exists'])) throw new RuntimeException('BG-Wiki target page not found: '.$title);
                $parsed=$parser->parseJobPage((string)$page['content'],$job,(string)$page['title']);
                $repo->saveJob($sourceId,$page,$parsed);
                $sets=count($parsed['sets']);$targets=0;foreach($parsed['sets'] as $set)$targets+=count($set['items']);
                $result['ok'][$job]=['page'=>$page['title'],'sets'=>$sets,'targets'=>$targets,'warnings'=>$parsed['warnings']];
                $result['sets']+=$sets;$result['targets']+=$targets;
            }catch(Throwable $e){$result['errors'][$job]=$e->getMessage();}
        }
        return $result;
    }
}
