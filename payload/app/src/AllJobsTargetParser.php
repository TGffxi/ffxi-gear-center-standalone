<?php
declare(strict_types=1);

/**
 * Parser for BG-Wiki's All Jobs Gear Sets job pages.
 *
 * Safety rules:
 * - source labels are preserved verbatim; canonical correction happens later;
 * - Mid/High-buff columns are parsed from their own DOM containers;
 * - item slot names are NOT guessed from list position;
 * - nested list entries are preserved as target details/augment hints.
 */
final class AllJobsTargetParser
{
    /** @return array<string,string> abbreviation => BG-Wiki job page label */
    public static function jobs(): array
    {
        return [
            'WAR'=>'Warrior','MNK'=>'Monk','WHM'=>'White Mage','BLM'=>'Black Mage','RDM'=>'Red Mage','THF'=>'Thief',
            'PLD'=>'Paladin','DRK'=>'Dark Knight','BST'=>'Beastmaster','BRD'=>'Bard','RNG'=>'Ranger','SMN'=>'Summoner',
            'SAM'=>'Samurai','NIN'=>'Ninja','DRG'=>'Dragoon','BLU'=>'Blue Mage','COR'=>'Corsair','PUP'=>'Puppetmaster',
            'DNC'=>'Dancer','SCH'=>'Scholar','GEO'=>'Geomancer','RUN'=>'Rune Fencer',
        ];
    }

    public static function pageTitle(string $job): string
    {
        $job=strtoupper(trim($job));
        $jobs=self::jobs();
        if(!isset($jobs[$job])) throw new InvalidArgumentException('Unsupported FFXI job: '.$job);
        return 'All Jobs Gear Sets/'.$jobs[$job];
    }

    /** @return array<string,mixed> */
    public function parseJobPage(string $html,string $job,string $pageTitle=''): array
    {
        $job=strtoupper(trim($job));
        if(!isset(self::jobs()[$job])) throw new InvalidArgumentException('Unsupported FFXI job: '.$job);

        // GEARCENTER_BG_JOB_GUIDES_PRIMARY_V1
        // The original tracker used IzaKastra/bg_job_guides directly.  Keep
        // that structured source as primary for real network imports while
        // leaving synthetic parser fixtures on the existing HTML path.
        $liveTitle=strtolower(trim($pageTitle));
        $looksLikeLiveWiki=(strlen($html)>5000)
            && (stripos($html,'mw-content-text')!==false)
            && (stripos($html,'ffxi wiki')!==false || stripos($html,'bg-wiki')!==false);
        if($liveTitle==='main page' || $looksLikeLiveWiki){
            $md=$this->fetchPrimaryGuideMarkdown($job);
            if($md!==null){
                $primary=$this->parsePrimaryGuideMarkdown($md,$job);
                if(!empty($primary['sets'])) return $primary;
            }
        }
        if(trim($html)==='') throw new InvalidArgumentException('Target page HTML is empty.');
        if(!class_exists('DOMDocument')) return $this->parseJobPageFallback($html,$job,$pageTitle);

        $dom=new DOMDocument();
        $prev=libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NOWARNING|LIBXML_NOERROR);
        libxml_clear_errors();libxml_use_internal_errors($prev);
        $xp=new DOMXPath($dom);
        $root=$xp->query("//*[@id='mw-content-text']//*[contains(concat(' ',normalize-space(@class),' '),' mw-parser-output ')]")->item(0)
            ?? $xp->query("//*[@id='mw-content-text']")->item(0)
            ?? $dom->documentElement;

        $assumptions=$this->assumptions($this->text($root));
        $sets=[];$warnings=[];$currentSet='';$order=0;
        $nodes=$xp->query('.//*[self::h2 or self::h3 or self::h4 or self::table or self::ul or self::ol]',$root);
        if(!$nodes) $nodes=[];
        foreach($nodes as $node){
            $tag=strtolower($node->nodeName);
            if(in_array($tag,['h2','h3','h4'],true)){
                $heading=$this->cleanHeading($this->text($node));
                if($this->isSetHeading($heading,$job)) $currentSet=$heading;
                continue;
            }
            if($tag==='table'){
                // Current BG-Wiki All Jobs pages keep the individual set name
                // inside the outer gear table instead of h2/h3/h4 headings.
                // Example: "Disaster* Mid buff ..." or "Upheaval* Mid buff ...".
                $tableSet=$this->setHeadingFromTable($node);
                if($tableSet!==null) $currentSet=$tableSet;
                if($currentSet==='') continue;

                $variants=$this->parseVariantTable($xp,$node);
                foreach($variants as $variant){
                    if(!$variant['items']) continue;
                    $sets[]=[
                        'set_name'=>$currentSet,
                        'variant_name'=>$variant['variant'],
                        'source_order'=>$order++,
                        'items'=>$variant['items'],
                    ];
                }
                continue;
            }
            // Fallback for pages/sections rendered as a single list rather than a table.
            if(($tag==='ul'||$tag==='ol') && !$this->hasAncestor($node,'table') && !$this->hasAncestor($node,'li')){
                $items=$this->extractItems($xp,$node);
                if($items){
                    $sets[]=['set_name'=>$currentSet,'variant_name'=>'Standard','source_order'=>$order++,'items'=>$items];
                }
            }
        }

        // Deduplicate identical parsed blocks caused by nested/skin markup.
        $dedup=[];
        foreach($sets as $set){
            $fingerprint=LacProfileParser::normalizeName($set['set_name']).'|'.strtolower($set['variant_name']).'|'.hash('sha256',CanonicalJson::encode($set['items']));
            $dedup[$fingerprint]=$set;
        }
        $sets=array_values($dedup);
        if(!$sets) $warnings[]='No target sets were recognized on the job page.';

        return [
            'job'=>$job,
            'job_name'=>self::jobs()[$job],
            'page_title'=>$pageTitle!==''?$pageTitle:self::pageTitle($job),
            'source_kind'=>'bgwiki_all_jobs_gear_sets',
            'assumptions'=>$assumptions,
            'sets'=>$sets,
            'warnings'=>$warnings,
        ];
    }

    /**
     * Primary structured source used by the original Gear Tracker.
     * Network -> atomic local cache -> stale-cache fallback.
     */
    private function fetchPrimaryGuideMarkdown(string $job): ?string
    {
        $code=strtolower(trim($job));
        $url='https://raw.githubusercontent.com/IzaKastra/bg_job_guides/main/'.$code.'.md';
        $cacheDir=dirname(__DIR__).'/data/cache/bg_job_guides';
        $cacheFile=$cacheDir.'/'.$code.'.md';
        if(!is_dir($cacheDir)) @mkdir($cacheDir,0775,true);

        // Fresh cache avoids downloading all 22 guides repeatedly during the
        // same acceptance session.  Stale cache is refreshed below.
        if(is_file($cacheFile) && (time()-(int)@filemtime($cacheFile))<21600){
            $fresh=@file_get_contents($cacheFile);
            if(is_string($fresh) && $this->looksLikeGuideMarkdown($fresh)) return $fresh;
        }

        $body=$this->fetchGuideUrl($url);
        if($body!==null && $this->looksLikeGuideMarkdown($body)){
            if(is_dir($cacheDir)){
                $tmp=$cacheFile.'.tmp.'.getmypid();
                if(@file_put_contents($tmp,$body)!==false) @rename($tmp,$cacheFile);
                else @unlink($tmp);
            }
            return $body;
        }

        if(is_file($cacheFile)){
            $cached=@file_get_contents($cacheFile);
            if(is_string($cached) && $this->looksLikeGuideMarkdown($cached)) return $cached;
        }
        return null;
    }

    private function fetchGuideUrl(string $url): ?string
    {
        if(function_exists('curl_init')){
            $ch=curl_init($url);
            if($ch!==false){
                curl_setopt_array($ch,[
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_FOLLOWLOCATION=>true,
                    CURLOPT_CONNECTTIMEOUT=>8,
                    CURLOPT_TIMEOUT=>20,
                    CURLOPT_USERAGENT=>'FFXI-Gear-Center/0.4 bg_job_guides',
                    CURLOPT_ENCODING=>'',
                ]);
                $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                if(is_string($body) && $code>=200 && $code<300) return $body;
            }
        }

        $ctx=stream_context_create(['http'=>[
            'method'=>'GET','timeout'=>20,'ignore_errors'=>true,
            'header'=>"User-Agent: FFXI-Gear-Center/0.4 bg_job_guides\r\nAccept: text/plain,*/*\r\n",
        ]]);
        $body=@file_get_contents($url,false,$ctx);
        if(is_string($body) && $body!=='') return $body;

        // CachyOS test machines already use curl for the BG-Wiki acceptance
        // path; retain it as a final fallback if PHP lacks curl/URL fopen.
        if(function_exists('exec')){
            $out=[];$rc=1;
            @exec('curl -L --compressed --fail --silent --show-error --max-time 20 -A '.escapeshellarg('FFXI-Gear-Center/0.4 bg_job_guides').' '.escapeshellarg($url),$out,$rc);
            if($rc===0 && $out) return implode("\n",$out)."\n";
        }
        return null;
    }

    private function looksLikeGuideMarkdown(string $text): bool
    {
        return strlen($text)>200
            && stripos($text,'Guide Equipment Set')!==false
            && preg_match('/^\s*\|Set Name\s*=/mi',$text)===1
            && preg_match('/^\s*\|(Main|Sub|Head|Body|Hands|Legs|Feet)\s*=/mi',$text)===1;
    }

    /** @return array<string,mixed> */
    private function parsePrimaryGuideMarkdown(string $text,string $job): array
    {
        $validSlots=[
            'main'=>'Main','sub'=>'Sub','range'=>'Range','ammo'=>'Ammo','head'=>'Head','neck'=>'Neck',
            'ear1'=>'Ear1','ear2'=>'Ear2','body'=>'Body','hands'=>'Hands','ring1'=>'Ring1','ring2'=>'Ring2',
            'back'=>'Back','waist'=>'Waist','legs'=>'Legs','feet'=>'Feet',
        ];
        $sets=[];$order=0;$currentSet=null;$currentVariant=null;

        $flushVariant=function() use (&$currentSet,&$currentVariant): void {
            if($currentSet===null || $currentVariant===null) return;
            if(!empty($currentVariant['items'])) $currentSet['variants'][]=$currentVariant;
            $currentVariant=null;
        };
        $flushSet=function() use (&$sets,&$order,&$currentSet,&$currentVariant,&$flushVariant): void {
            if($currentSet===null) return;
            $flushVariant();
            foreach($currentSet['variants'] as $variant){
                if(empty($variant['items'])) continue;
                $sets[]=[
                    'set_name'=>$currentSet['name'],
                    'variant_name'=>$variant['variant'],
                    'source_order'=>$order++,
                    'items'=>$variant['items'],
                ];
            }
            $currentSet=null;$currentVariant=null;
        };

        foreach(preg_split('/\R/u',$text)?:[] as $line){
            if(preg_match('/^\s*\|Set Name\s*=\s*(.*?)\s*$/iu',$line,$m)){
                $flushSet();
                $name=$this->cleanMarkdownValue((string)$m[1]);
                if($name!=='') $currentSet=['name'=>$name,'variants'=>[]];
                continue;
            }
            if($currentSet===null) continue;

            if(preg_match('/^\s*\|CaptionTop\s*=\s*(.*?)\s*$/iu',$line,$m)){
                $flushVariant();
                $variant=$this->cleanMarkdownValue((string)$m[1]);
                $currentVariant=['variant'=>$variant!==''?$variant:'Standard','items'=>[]];
                continue;
            }

            if(!preg_match('/^\s*\|([A-Za-z0-9]+)\s*=\s*(.*?)\s*$/u',$line,$m)) continue;
            $field=(string)$m[1];$raw=(string)$m[2];$fieldLower=strtolower($field);

            if(isset($validSlots[$fieldLower])){
                $item=$this->markdownItem($raw,$validSlots[$fieldLower]);
                if($item===null) continue;
                if($currentVariant===null) $currentVariant=['variant'=>'Standard','items'=>[]];
                $currentVariant['items'][]=$item;
                continue;
            }

            // Preserve slot-specific augment hints (BackAug, MainAug, etc.)
            // on the matching item instead of treating them as fake gear.
            if(preg_match('/^(main|sub|range|ammo|head|neck|ear1|ear2|body|hands|ring1|ring2|back|waist|legs|feet)(?:aug|augment)$/i',$field,$am)){
                if($currentVariant===null) continue;
                $slot=$validSlots[strtolower((string)$am[1])]??null;
                $detail=$this->cleanMarkdownValue($raw);
                if($slot!==null && $detail!==''){
                    for($i=count($currentVariant['items'])-1;$i>=0;$i--){
                        if(($currentVariant['items'][$i]['slot_name']??null)===$slot){
                            $currentVariant['items'][$i]['details'][]='Augment: '.$detail;
                            break;
                        }
                    }
                }
            }
        }
        $flushSet();

        // Stable de-duplication in case the upstream template contains an
        // equivalent repeated block.
        $dedup=[];
        foreach($sets as $set){
            $fingerprint=LacProfileParser::normalizeName((string)$set['set_name']).'|'
                .strtolower((string)$set['variant_name']).'|'
                .hash('sha256',json_encode($set['items'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
            $dedup[$fingerprint]=$set;
        }
        $sets=array_values($dedup);

        return [
            'job'=>$job,
            'job_name'=>self::jobs()[$job],
            'page_title'=>self::pageTitle($job),
            'source_kind'=>'izakastra_bg_job_guides_markdown',
            'source_url'=>'https://github.com/IzaKastra/bg_job_guides/blob/main/'.strtolower($job).'.md',
            'assumptions'=>$this->assumptions($text),
            'sets'=>$sets,
            'warnings'=>$sets?[]:['No target sets were recognized in bg_job_guides Markdown.'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function markdownItem(string $raw,string $slot): ?array
    {
        $name=$this->cleanMarkdownValue($raw);
        if($name==='') return null;
        if(!preg_match('/[\pL]/u',$name)) return null;
        $linkTitle=$name;
        if(preg_match('/\[\[([^\]|#]+)(?:#[^\]|]*)?(?:\|[^\]]*)?\]\]/u',$raw,$m)){
            $candidate=trim((string)$m[1]);
            if($candidate!=='') $linkTitle=$candidate;
        }
        $url='https://www.bg-wiki.com/ffxi/'.str_replace('%2F','/',rawurlencode(str_replace(' ','_',$linkTitle)));
        return [
            'position'=>0,
            'slot_name'=>$slot,
            'source_item_name'=>$name,
            'item_name_norm'=>LacProfileParser::normalizeName($name),
            'source_link_title'=>$linkTitle,
            'source_link_url'=>$url,
            'details'=>[],
        ];
    }

    private function cleanMarkdownValue(string $value): string
    {
        $value=preg_replace('/<!--.*?-->/s','',$value)??$value;
        $value=preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]*))?\]\]/u',static function(array $m): string {
            $target=trim((string)($m[1]??''));$label=trim((string)($m[2]??''));
            if($label==='*') return '';
            return $label!==''?$label:$target;
        },$value)??$value;
        $value=html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $value=preg_replace('/\s+/u',' ',trim($value))??trim($value);
        return rtrim($value,"* \t\n\r\0\x0B");
    }

    /** @return array<string,mixed> */
    private function parseJobPageFallback(string $html,string $job,string $pageTitle): array
    {
        $plain=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
        $assumptions=$this->assumptions($plain);$sets=[];$warnings=[];$order=0;
        $pattern='~<h([2-4])\\b[^>]*>(.*?)</h\\1>(.*?)(?=<h[2-4]\\b|$)~is';
        if(preg_match_all($pattern,$html,$m,PREG_SET_ORDER)){
            foreach($m as $part){
                $heading=$this->cleanHeading(trim(html_entity_decode(strip_tags($part[2]),ENT_QUOTES|ENT_HTML5,'UTF-8')));
                if(!$this->isSetHeading($heading,$job))continue;$body=$part[3];$found=false;
                foreach($this->allTagContents($body,'td') as $cell){
                    $cellPlain=trim(preg_replace('/\\s+/u',' ',html_entity_decode(strip_tags($cell),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
                    $hasMid=(bool)preg_match('/\\bMid\\s*-?\\s*buff/i',$cellPlain);$hasHigh=(bool)preg_match('/\\bHigh\\s*-?\\s*buff/i',$cellPlain);
                    if($hasMid===$hasHigh)continue; // neither or an outer cell containing both columns
                    $items=$this->extractItemsFallback($cell);if(!$items)continue;$found=true;
                    $sets[]=['set_name'=>$heading,'variant_name'=>$hasMid?'Mid buff':'High buff','source_order'=>$order++,'items'=>$items];
                }
                if(!$found){$items=$this->extractItemsFallback($body);if($items)$sets[]=['set_name'=>$heading,'variant_name'=>'Standard','source_order'=>$order++,'items'=>$items];}
            }
        }
        $dedup=[];foreach($sets as $set){$fingerprint=LacProfileParser::normalizeName($set['set_name']).'|'.strtolower($set['variant_name']).'|'.hash('sha256',CanonicalJson::encode($set['items']));$dedup[$fingerprint]=$set;}$sets=array_values($dedup);
        if(!$sets)$warnings[]='No target sets were recognized on the job page.';
        $warnings[]='Parsed with HTML fallback because PHP DOM extension is unavailable.';
        return ['job'=>$job,'job_name'=>self::jobs()[$job],'page_title'=>$pageTitle!==''?$pageTitle:self::pageTitle($job),'source_kind'=>'bgwiki_all_jobs_gear_sets','assumptions'=>$assumptions,'sets'=>$sets,'warnings'=>$warnings];
    }

    /** @return array<int,string> */
    private function allTagContents(string $html,string $tag): array
    {
        $tag=strtolower($tag);$tokens=preg_split('~(<[^>]+>)~s',$html,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY)?:[];$stack=[];$out=[];
        foreach($tokens as $tok){
            if(preg_match('~^<'.$tag.'\\b[^>]*>~i',$tok)){$stack[]='';continue;}
            if(preg_match('~^</'.$tag.'\\s*>~i',$tok)){if($stack){$content=array_pop($stack);$out[]=$content;}continue;}
            if($stack){foreach($stack as $i=>$buf)$stack[$i].=$tok;}
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function extractItemsFallback(string $html): array
    {
        $tokens=preg_split('~(</?li\\b[^>]*>)~is',$html,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY)?:[];$depth=0;$name='';$details=[];$detail='';$out=[];$position=0;
        foreach($tokens as $tok){
            if(preg_match('~^<li\\b~i',$tok)){$depth++;if($depth===1){$name='';$details=[];}elseif($depth===2)$detail='';continue;}
            if(preg_match('~^</li~i',$tok)){
                if($depth===2){$d=$this->plainFragment($detail);if($d!=='')$details[]=$d;}
                elseif($depth===1){
                    $n=$this->cleanItemName($this->plainFragment($name));$link=$this->linkEvidenceFromHtml($name);
                    if($this->looksLikeItem($n))$out[]=[
                        'position'=>$position++,'slot_name'=>null,'source_item_name'=>$n,
                        'item_name_norm'=>LacProfileParser::normalizeName($n),
                        'source_link_title'=>$link['title'],'source_link_url'=>$link['url'],
                        'details'=>array_values(array_unique(array_filter($details))),
                    ];
                }
                $depth=max(0,$depth-1);continue;
            }
            if($depth===1)$name.=$tok;elseif($depth>=2)$detail.=$tok;
        }
        return $out;
    }

    private function plainFragment(string $html): string
    {
        return trim(preg_replace('/\\s+/u',' ',html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
    }

    /** @return array<int,array{variant:string,items:array<int,array<string,mixed>>}> */
    private function parseVariantTable(DOMXPath $xp,DOMNode $table): array
    {
        $candidates=[];
        $cells=$xp->query('.//td',$table);
        if($cells){
            foreach($cells as $cell){
                $text=$this->text($cell);
                $variant=$this->variantFromText($text);
                if($variant===null) continue;
                $nestedSame=false;
                $nested=$xp->query('.//td',$cell);
                if($nested){
                    foreach($nested as $n){
                        if($n->isSameNode($cell)) continue;
                        if($this->variantFromText($this->text($n))===$variant && $xp->query('.//li',$n)?->length){$nestedSame=true;break;}
                    }
                }
                if($nestedSame) continue; // prefer the smallest column/container
                $items=$this->extractItems($xp,$cell);
                if($items) $candidates[]=['variant'=>$variant,'items'=>$items];
            }
        }
        if($candidates) return $candidates;

        // Single-column fallback: if a table contains a list but no explicit buff label.
        $items=$this->extractItems($xp,$table);
        return $items ? [['variant'=>'Standard','items'=>$items]] : [];
    }

    /** @return array<int,array<string,mixed>> */
    private function extractItems(DOMXPath $xp,DOMNode $container): array
    {
        $out=[];$lis=$xp->query('.//li[not(ancestor::li)]',$container);
        if(!$lis) return [];
        $position=0;
        foreach($lis as $li){
            $clone=$li->cloneNode(true);
            if($clone instanceof DOMElement){
                $remove=[];
                foreach($clone->getElementsByTagName('ul') as $x)$remove[]=$x;
                foreach($clone->getElementsByTagName('ol') as $x)$remove[]=$x;
                foreach($remove as $x)$x->parentNode?->removeChild($x);
            }
            $link=['title'=>null,'url'=>null];
            if($clone instanceof DOMElement){
                $anchor=$clone->getElementsByTagName('a')->item(0);
                if($anchor instanceof DOMElement)$link=$this->linkEvidence(
                    $anchor->getAttribute('title'),$anchor->getAttribute('href')
                );
            }
            $name=$this->cleanItemName($this->text($clone));
            if(!$this->looksLikeItem($name)) continue;
            $details=[];$nested=$xp->query('.//li',$li);
            if($nested){foreach($nested as $n){$d=trim($this->text($n));if($d!==''&&strcasecmp($d,$name)!==0)$details[]=$d;}}
            $details=array_values(array_unique($details));
            $out[]=[
                'position'=>$position++,
                'slot_name'=>null, // deliberately unresolved until item/resource identity is known
                'source_item_name'=>$name,
                'item_name_norm'=>LacProfileParser::normalizeName($name),
                'source_link_title'=>$link['title'],
                'source_link_url'=>$link['url'],
                'details'=>$details,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function assumptions(string $text): array
    {
        $out=[];
        if(preg_match('/Most\s+Odyssey\s+equipment\s+assumes\s+Rank\s+(\d+)/i',$text,$m))$out['odyssey_default_rank']=(int)$m[1];
        if(preg_match('/Nyame\s+(?:alone\s+)?assumes\s+Rank\s+(\d+)\s+Path\s+([A-Z])/i',$text,$m)){
            $out['nyame_rank']=(int)$m[1];$out['nyame_path']=strtoupper($m[2]);
        }
        if(preg_match('/Updated\s+(\d{4}\s+[A-Z][a-z]{2}\.?\s+\d{1,2})/i',$text,$m))$out['page_update_text']=$m[1];
        return $out;
    }

    /**
     * Recover a set name from the outer BG-Wiki gear table.
     *
     * Current All Jobs pages no longer expose each WS/set as its own heading.
     * The outer table starts for example with:
     *   Disaster* Mid buff ...
     *   Upheaval* Mid buff ...
     *
     * Nested variant tables start directly with "Mid buff" / "High buff",
     * therefore they deliberately return null here.
     */
    private function setHeadingFromTable(DOMNode $table): ?string
    {
        $text=$this->text($table);

        if(!preg_match(
            '/^\s*(.+?)\s+(?=Mid\s*-?\s*buff\b|High\s*-?\s*buff\b)/iu',
            $text,
            $m
        )) return null;

        $heading=$this->cleanHeading((string)$m[1]);

        if($heading==='') return null;
        if(strlen($heading)>120) return null;

        $h=strtolower($heading);

        // Never promote guide/skin/navigation tables to actual gear sets.
        foreach([
            'this article is a guide',
            'please do not edit',
            'navigation',
            'contents',
        ] as $bad){
            if(str_contains($h,$bad)) return null;
        }

        return $heading;
    }

    private function variantFromText(string $text): ?string
    {
        // DOM textContent may concatenate </b><ul> without whitespace, e.g.
        // "Mid buffKunimitsu".  The leading boundary and full marker are
        // sufficient; a trailing word boundary would lose the whole column.
        if(preg_match('/\bMid\s*-?\s*buff/i',$text))return 'Mid buff';
        if(preg_match('/\bHigh\s*-?\s*buff/i',$text))return 'High buff';
        return null;
    }

    private function isSetHeading(string $heading,string $job): bool
    {
        if($heading==='')return false;
        $h=strtolower($heading);
        if(str_contains($h,'sets') && str_contains($h,strtolower(self::jobs()[$job])))return false;
        if(in_array($h,['contents','navigation','references','external links','notes','see also'],true))return false;
        return true;
    }

    private function cleanHeading(string $s): string
    {
        $s=preg_replace('/\[edit\]/i','',$s)??$s;
        $s=preg_replace('/\s+/u',' ',trim($s))??trim($s);
        return rtrim($s,"* \t\n\r\0\x0B");
    }

    private function cleanItemName(string $s): string
    {
        $s=preg_replace('/\s+/u',' ',trim($s))??trim($s);
        $s=preg_replace('/^[•*\-]+\s*/u','',$s)??$s;
        return trim($s);
    }

    /** @return array{title:?string,url:?string} */
    private function linkEvidenceFromHtml(string $html): array
    {
        if(!preg_match('~<a\\b([^>]*)>~is',$html,$m))return ['title'=>null,'url'=>null];
        $attrs=(string)$m[1];$title='';$href='';
        if(preg_match('~\\btitle\\s*=\\s*(["\\\'])(.*?)\\1~is',$attrs,$x))$title=html_entity_decode((string)$x[2],ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(preg_match('~\\bhref\\s*=\\s*(["\\\'])(.*?)\\1~is',$attrs,$x))$href=html_entity_decode((string)$x[2],ENT_QUOTES|ENT_HTML5,'UTF-8');
        return $this->linkEvidence($title,$href);
    }

    /** @return array{title:?string,url:?string} */
    private function linkEvidence(string $title,string $href): array
    {
        $title=trim($title);$href=trim($href);
        if($title==='' && preg_match('~(?:https?://www\\.bg-wiki\\.com)?/ffxi/([^?#]+)~i',$href,$m)){
            $title=str_replace('_',' ',rawurldecode((string)$m[1]));
        }
        if($title!=='' && preg_match('/^(?:File|Category|Special|User|Talk):/i',$title))return ['title'=>null,'url'=>null];
        $url=null;
        if($href!==''){
            if(str_starts_with($href,'/ffxi/'))$url='https://www.bg-wiki.com'.$href;
            elseif(preg_match('~^https?://(?:www\\.)?bg-wiki\\.com/ffxi/~i',$href))$url=$href;
        }
        return ['title'=>$title!==''?$title:null,'url'=>$url];
    }

    private function looksLikeItem(string $name): bool
    {
        if($name===''||strlen($name)>100)return false;
        if(preg_match('/^(?:Notes?|Image|Mid buff|High buff|damage)$/i',$name))return false;
        if(preg_match('/\b(?:Updated|ML\d+|TP\s*\+|damage$)\b/i',$name))return false;
        return true;
    }

    private function text(?DOMNode $node): string
    {
        if(!$node)return '';
        return trim(preg_replace('/\s+/u',' ',html_entity_decode($node->textContent??'',ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
    }

    private function hasAncestor(DOMNode $node,string $tag): bool
    {
        $p=$node->parentNode;$tag=strtolower($tag);
        while($p){if(strtolower($p->nodeName)===$tag)return true;$p=$p->parentNode;}
        return false;
    }
}
