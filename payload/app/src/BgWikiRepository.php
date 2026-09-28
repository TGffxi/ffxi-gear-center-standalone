<?php
declare(strict_types=1);

final class BgWikiRepository
{
    public function __construct(private PDO $pdo) {}
    public function save(string $itemName,array $page): void
    {
        $now=gmdate('c');$format=(string)($page['content_format']??'wikitext');$sections=BgWikiExtractor::sections((string)$page['content'],$format);$records=BgWikiExtractor::progressionRecords((string)$page['content'],$format);if($format==='html')$records=array_merge($records,BgWikiExtractor::versionChainRecords((string)$page['content'],(string)$page['title'],$format));$excerpt=BgWikiExtractor::plainExcerpt((string)$page['content'],900,$format);
        $s=$this->pdo->prepare("INSERT INTO bgwiki_pages(page_title,canonical_url,revision_id,revision_timestamp,fetched_at,exists_flag,sections_json,raw_excerpt,error_message) VALUES(:title,:url,:rid,:rts,:now,:exists,:sections,:excerpt,NULL) ON CONFLICT(page_title) DO UPDATE SET canonical_url=excluded.canonical_url,revision_id=excluded.revision_id,revision_timestamp=excluded.revision_timestamp,fetched_at=excluded.fetched_at,exists_flag=excluded.exists_flag,sections_json=excluded.sections_json,raw_excerpt=excluded.raw_excerpt,error_message=NULL");
        $s->execute([':title'=>$page['title'],':url'=>$page['canonical_url'],':rid'=>$page['revision_id'],':rts'=>$page['revision_timestamp'],':now'=>$now,':exists'=>$page['exists']?1:0,':sections'=>json_encode($sections,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':excerpt'=>$excerpt]);
        $this->pdo->prepare('DELETE FROM bgwiki_progression_records WHERE lower(page_title)=lower(:title)')->execute([':title'=>$page['title']]);
        $ri=$this->pdo->prepare('INSERT INTO bgwiki_progression_records(page_title,section_name,record_index,source_type,fields_json,created_at) VALUES(:title,:section,:idx,:type,:fields,:now)');
        $perSection=[];
        foreach($records as $record){
            $section=(string)($record['section']??'');
            if($section==='')continue;
            $idx=$perSection[$section]??0;$perSection[$section]=$idx+1;
            $ri->execute([':title'=>$page['title'],':section'=>$section,':idx'=>$idx,':type'=>(string)($record['source_type']??'table'),':fields'=>json_encode($record['fields']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':now'=>$now]);
        }
        $itemNorm=LacProfileParser::normalizeName($itemName);$pageNorm=LacProfileParser::normalizeName((string)$page['title']);
        $confidence=$itemNorm===$pageNorm?'exact_title':(!empty($page['resolved_from_ambiguous'])?(string)($page['resolution']??'resource_stat_fingerprint'):'canonical_redirect_unverified');
        $m=$this->pdo->prepare("INSERT INTO bgwiki_item_map(item_name_norm,item_name,page_title,confidence,updated_at) VALUES(:norm,:name,:title,:confidence,:now) ON CONFLICT(item_name_norm) DO UPDATE SET item_name=excluded.item_name,page_title=excluded.page_title,confidence=excluded.confidence,updated_at=excluded.updated_at");
        $m->execute([':norm'=>$itemNorm,':name'=>$itemName,':title'=>$page['title'],':confidence'=>$confidence,':now'=>$now]);
    }
    public function byItem(string $itemName): ?array
    {
        $norm=LacProfileParser::normalizeName($itemName);
        $s=$this->pdo->prepare('SELECT p.* FROM bgwiki_item_map m JOIN bgwiki_pages p ON lower(p.page_title)=lower(m.page_title) WHERE m.item_name_norm=:norm LIMIT 1');
        $s->execute([':norm'=>$norm]);
        $direct=$s->fetch()?:null;
        if($direct) return $direct;

        // Alias fallback: imported query names can differ from BG-Wiki's canonical item title
        // (e.g. Mochizuki Chainmail +3 -> Mochi. Chainmail +3).
        $all=$this->pdo->query('SELECT p.* FROM bgwiki_item_map m JOIN bgwiki_pages p ON lower(p.page_title)=lower(m.page_title)');
        foreach($all->fetchAll() as $page){
            if(LacProfileParser::normalizeName((string)$page['page_title'])===$norm) return $page;
        }
        return null;
    }

    public function byPageTitle(string $pageTitle): ?array
    {
        $s=$this->pdo->prepare('SELECT * FROM bgwiki_pages WHERE lower(page_title)=lower(:title) AND exists_flag=1 LIMIT 1');
        $s->execute([':title'=>trim($pageTitle)]);$r=$s->fetch();return $r?:null;
    }

    /** @return array<string,mixed>|null */
    public function mappingForAlias(string $itemName): ?array
    {
        $s=$this->pdo->prepare('SELECT * FROM bgwiki_item_map WHERE item_name_norm=:norm LIMIT 1');
        $s->execute([':norm'=>LacProfileParser::normalizeName($itemName)]);$r=$s->fetch();return $r?:null;
    }

    public function progressionRecordsByItem(string $itemName): array
    {
        $page=$this->byItem($itemName);
        if(!$page)return [];
        $s=$this->pdo->prepare('SELECT section_name,record_index,source_type,fields_json FROM bgwiki_progression_records WHERE lower(page_title)=lower(:title) ORDER BY section_name,record_index');
        $s->execute([':title'=>$page['page_title']]);
        $out=[];
        foreach($s->fetchAll() as $r){
            $fields=json_decode((string)$r['fields_json'],true);
            if(!is_array($fields)||$fields===[])continue;
            // Keep the logical/canonical page owner on direct item records too.
            // ProgressionChainService::edges() requires an owner to build self-
            // augment and upgrade edges; without these fields CoverageAnalyzer
            // silently lost system identity for current-state items such as
            // Odyssey Gaol and Dynamis-D crafted weapons.
            $out[]=[
                'item_name'=>(string)$page['page_title'],
                'page_title'=>(string)$page['page_title'],
                'canonical_url'=>(string)($page['canonical_url']??''),
                'section'=>(string)$r['section_name'],
                'record_index'=>(int)$r['record_index'],
                'source_type'=>(string)$r['source_type'],
                'fields'=>$fields,
            ];
        }
        return $out;
    }

    /**
     * All cached structured progression records with the logical item that owns
     * the BG-Wiki page. Used to build cross-page upgrade/augment chains.
     * @return array<int,array<string,mixed>>
     */
    public function graphRecords(): array
    {
        $sql = "SELECT r.section_name,r.record_index,r.source_type,r.fields_json,
                       p.page_title,p.canonical_url,p.page_title AS item_name
                FROM bgwiki_progression_records r
                JOIN bgwiki_pages p ON lower(p.page_title)=lower(r.page_title)
                WHERE p.exists_flag=1
                ORDER BY p.page_title,r.section_name,r.record_index";
        $out=[];
        foreach($this->pdo->query($sql)->fetchAll() as $r){
            $fields=json_decode((string)$r['fields_json'],true);
            if(!is_array($fields)||$fields===[])continue;
            $out[]=[
                'item_name'=>(string)$r['item_name'],
                'page_title'=>(string)$r['page_title'],
                'canonical_url'=>(string)$r['canonical_url'],
                'section'=>(string)$r['section_name'],
                'record_index'=>(int)$r['record_index'],
                'source_type'=>(string)$r['source_type'],
                'fields'=>$fields,
            ];
        }
        return $out;
    }

}
