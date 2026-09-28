<?php
declare(strict_types=1);

final class BgWikiExtractor
{
    private const WANTED = [
        'obtained from' => 'Obtained From',
        'obtained by' => 'Obtained From',
        'acquisition' => 'Obtained From',
        'purchased from' => 'Purchased From',
        'redeemed from' => 'Redeemed From',
        'dropped from' => 'Dropped From',
        'quest' => 'Quest',
        'quests' => 'Quest',
        'this item is able to be augmented' => 'Augmented Via',
        'augmented via' => 'Augmented Via',
        'augmenting' => 'Augmented Via',
        'augments' => 'Augmented Via',
        'upgrade info' => 'Upgrade Info',
        'upgrade information' => 'Upgrade Info',
        'upgraded from' => 'Upgraded From',
        'used in the following item upgrades' => 'Used In Item Upgrades',
        'used in item upgrades' => 'Used In Item Upgrades',
        'used in recipe' => 'Used In Recipe',
        'used in recipes' => 'Used In Recipe',
        'synthesis information' => 'Synthesis',
        'synthesis' => 'Synthesis',
        'synergy information' => 'Synthesis',
        'notes' => 'Notes',
    ];

    /**
     * DOMDocument otherwise guesses ISO-8859-1/Windows-1252 for an HTML fragment
     * without a charset declaration.  BG-Wiki articleHtml() returns a UTF-8
     * fragment, so reparsing that fragment without an explicit encoding turns
     * curly quotes into mojibake such as â€œStore TPâ€\x9d.  Keep encoding explicit
     * at the parse boundary instead of repairing display text later.
     */
    private static function loadHtmlUtf8(DOMDocument $doc, string $html): bool
    {
        return @$doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
    }

    /**
     * One-time, lossless repair helper for structured rows that were already
     * persisted by the old fragment parser.  This is intentionally conservative:
     * a candidate is accepted only when the mojibake text can be reversed to a
     * valid UTF-8 byte sequence and the suspicious-marker score strictly drops.
     * It is used by the offline database migration, never as a display-time fix.
     */
    public static function repairLegacyMojibakeString(string $value): string
    {
        if ($value === '' || self::mojibakeScore($value) === 0) return $value;

        $candidate = strtr($value, self::windows1252ReverseMap());
        if ($candidate === $value || preg_match('//u', $candidate) !== 1) return $value;
        if (self::mojibakeScore($candidate) >= self::mojibakeScore($value)) return $value;
        return $candidate;
    }

    private static function mojibakeScore(string $value): int
    {
        $score = 0;
        foreach (['Ã','Â','â','ð','ï¿½'] as $marker) $score += substr_count($value, $marker);
        if (preg_match_all('/[\\x{0080}-\\x{009F}]/u', $value, $m)) $score += count($m[0]);
        return $score;
    }

    /** @return array<string,string> UTF-8 character => original Windows-1252 byte */
    private static function windows1252ReverseMap(): array
    {
        static $map = null;
        if ($map !== null) return $map;
        $map = [];

        // ISO-8859-1 / C1 code points map directly back to their byte values.
        for ($b = 0x80; $b <= 0xFF; $b++) {
            $utf8 = $b < 0xC0
                ? chr(0xC0 | ($b >> 6)) . chr(0x80 | ($b & 0x3F))
                : chr(0xC0 | ($b >> 6)) . chr(0x80 | ($b & 0x3F));
            $map[$utf8] = chr($b);
        }

        // Windows-1252 printable characters occupying C1 byte positions.
        $special = [
            0x80=>'€',0x82=>'‚',0x83=>'ƒ',0x84=>'„',0x85=>'…',0x86=>'†',0x87=>'‡',
            0x88=>'ˆ',0x89=>'‰',0x8A=>'Š',0x8B=>'‹',0x8C=>'Œ',0x8E=>'Ž',
            0x91=>'‘',0x92=>'’',0x93=>'“',0x94=>'”',0x95=>'•',0x96=>'–',0x97=>'—',
            0x98=>'˜',0x99=>'™',0x9A=>'š',0x9B=>'›',0x9C=>'œ',0x9E=>'ž',0x9F=>'Ÿ',
        ];
        foreach ($special as $byte=>$char) $map[$char] = chr($byte);
        return $map;
    }

    public static function sections(string $content, string $format = 'wikitext'): array
    {
        return $format === 'html' ? self::sectionsHtml($content) : self::sectionsWikitext($content);
    }

    /**
     * Return only the MediaWiki article body.  The previous importer converted the
     * entire HTML document to text, which included the login links, global content
     * navigation and portal menus.  Keep this function dependency-light: DOM is
     * preferred when available, with a string-boundary fallback for lean PHP builds.
     */
    public static function articleHtml(string $html): string
    {
        if ($html === '') return '';

        if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
            $old = libxml_use_internal_errors(true);
            try {
                $doc = new DOMDocument();
                if (self::loadHtmlUtf8($doc, $html)) {
                    $xp = new DOMXPath($doc);
                    $nodes = $xp->query(
                        "//*[contains(concat(' ', normalize-space(@class), ' '), ' mw-parser-output ')]"
                    );
                    $root = ($nodes && $nodes->length) ? $nodes->item(0) : null;
                    if (!$root) {
                        $nodes = $xp->query("//*[@id='mw-content-text']");
                        $root = ($nodes && $nodes->length) ? $nodes->item(0) : null;
                    }
                    if ($root) {
                        self::pruneDom($xp, $root);
                        $clean = $doc->saveHTML($root);
                        if (is_string($clean) && trim($clean) !== '') return $clean;
                    }
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($old);
            }
        }

        // Fallback: begin at MediaWiki's content container/parser output and stop
        // before the standard page footer/categories. This does not need balanced
        // HTML and is enough to prevent global navigation from entering excerpts.
        $start = 0;
        if (preg_match('~<div\b[^>]*class=["\'][^"\']*mw-parser-output[^"\']*["\'][^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
        } elseif (preg_match('~<div\b[^>]*id=["\']mw-content-text["\'][^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
        }
        $end = strlen($html);
        foreach ([
            '<div class="printfooter"', "<div class='printfooter'",
            '<div id="catlinks"', "<div id='catlinks'",
            '<div class="mw-footer-container"', "<div class='mw-footer-container'",
            '<footer',
        ] as $marker) {
            $p = stripos($html, $marker, $start);
            if ($p !== false && $p < $end) $end = $p;
        }
        $slice = substr($html, $start, max(0, $end - $start));
        $slice = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is', ' ', $slice) ?? $slice;
        $slice = preg_replace('~<span\b[^>]*class=["\'][^"\']*mw-editsection[^"\']*["\'][^>]*>.*?</span>~is', ' ', $slice) ?? $slice;
        return $slice;
    }

    private static function pruneDom(DOMXPath $xp, DOMNode $root): void
    {
        $remove = [];
        $queries = [
            ".//script|.//style|.//noscript|.//form",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' mw-editsection ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' navbox ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' noprint ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' mw-jump-link ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' toc ')]",
            ".//*[@id='toc']",
        ];
        foreach ($queries as $query) {
            $nodes = $xp->query($query, $root);
            if (!$nodes) continue;
            foreach ($nodes as $node) $remove[] = $node;
        }
        // Remove deepest nodes first; DOMNodeList is live on some libxml builds.
        foreach (array_reverse($remove) as $node) {
            if ($node->parentNode) $node->parentNode->removeChild($node);
        }
    }

    private static function sectionsWikitext(string $wikitext): array
    {
        $out=[];
        if($wikitext==='')return $out;
        preg_match_all('/^==+\s*([^=]+?)\s*==+\s*$/m',$wikitext,$m,PREG_OFFSET_CAPTURE);
        $heads=$m[0];$names=$m[1];
        for($i=0;$i<count($heads);$i++){
            $name=trim($names[$i][0]);$canonical=self::canonicalHeading($name);if($canonical===null)continue;
            $start=$heads[$i][1]+strlen($heads[$i][0]);$end=$i+1<count($heads)?$heads[$i+1][1]:strlen($wikitext);$body=trim(substr($wikitext,$start,$end-$start));
            $body=preg_replace('/<!--.*?-->/s','',$body)??$body;$body=trim($body);if(strlen($body)>5000)$body=substr($body,0,5000).'…';
            self::appendSection($out,$canonical,$body);
        }
        return $out;
    }

    private static function sectionsHtml(string $html): array
    {
        if ($html === '') return [];
        $article = self::articleHtml($html);
        $out=[];

        // Primary path: normal MediaWiki headings.
        preg_match_all('~<(h[2-5])\b[^>]*>(.*?)</\1>~is', $article, $m, PREG_OFFSET_CAPTURE);
        $heads=$m[0];$labels=$m[2];
        for($i=0;$i<count($heads);$i++){
            $label=self::htmlToPlain($labels[$i][0],300,false);
            $canonical=self::canonicalHeading($label);
            if($canonical===null)continue;
            $start=$heads[$i][1]+strlen($heads[$i][0]);
            $end=$i+1<count($heads)?$heads[$i+1][1]:strlen($article);
            $block=substr($article,$start,$end-$start);
            $plain=self::htmlToPlain($block,5000,false);
            if($plain!=='')self::appendSection($out,$canonical,$plain);
        }

        // BG-Wiki item templates also use table cells / strong labels instead of H2
        // headings for acquisition metadata. Segment the article's clean text by any
        // standalone semantic label so those pages still produce structured data.
        $plain=self::htmlToPlain($article,30000,false);
        $lines=preg_split('/\R/u',$plain)?:[];
        for($i=0;$i<count($lines);$i++){
            $line=trim($lines[$i]);
            $canonical=self::canonicalHeading($line);
            if($canonical===null || $canonical==='Notes')continue;
            $body=[];
            for($j=$i+1;$j<count($lines);$j++){
                $next=trim($lines[$j]);
                if($next==='')continue;
                if(self::canonicalHeading($next)!==null)break;
                if(self::isArticleTail($next))break;
                $body[]=$next;
                if(count($body)>=60)break;
            }
            if($body)self::appendSection($out,$canonical,self::cut(implode("\n",$body),5000));
        }

        return $out;
    }

    private static function appendSection(array &$out,string $name,string $body): void
    {
        $body=trim($body);
        if($body==='')return;
        if(!isset($out[$name])){$out[$name]=$body;return;}
        if(str_contains($out[$name],$body) || str_contains($body,$out[$name])){
            if(strlen($body)>strlen($out[$name]))$out[$name]=$body;
            return;
        }
        $out[$name]=self::cut($out[$name]."\n".$body,5000);
    }

    private static function canonicalHeading(string $name): ?string
    {
        $norm=self::normalizeHeading($name);
        return self::WANTED[$norm]??null;
    }

    private static function normalizeHeading(string $name): string
    {
        $name=html_entity_decode(strip_tags($name),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $name=preg_replace('/\[\s*edit\s*\]/i','',$name)??$name;
        $name=trim($name);
        // Table label punctuation such as "Obtained From:" should match too.
        $name=preg_replace('/[\s\.\!\:\-]+$/u','',$name)??$name;
        return strtolower(trim($name));
    }

    public static function plainExcerpt(string $content, int $max=900, string $format='wikitext'): string
    {
        if($format==='html')return self::htmlToPlain(self::articleHtml($content),$max,true);
        $x=preg_replace('/\{\{.*?\}\}/s',' ',$content)??$content;$x=preg_replace('/\[\[(?:[^\]|]+\|)?([^\]]+)\]\]/','$1',$x)??$x;$x=preg_replace('/<[^>]+>/',' ',$x)??$x;$x=preg_replace('/[=\'\{\}\[\]]+/',' ',$x)??$x;$x=trim(preg_replace('/\s+/',' ',$x)??$x);return self::cut($x,$max);
    }

    /**
     * Extract row-level progression records from BG-Wiki HTML tables.
     * Each returned record keeps the semantic section (e.g. Dropped From)
     * and a field map based on the table's real column headers. This prevents
     * header labels such as "NPC Name" / "Zone" / "Rate" from being shown as
     * if they were acquisition data.
     */
    public static function progressionRecords(string $content, string $format='html'): array
    {
        if ($format !== 'html' || $content === '') return [];
        $article = self::articleHtml($content);
        if ($article === '') return [];

        if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
            $old = libxml_use_internal_errors(true);
            try {
                $doc = new DOMDocument();
                if (self::loadHtmlUtf8($doc, '<!doctype html><html><body>'.$article.'</body></html>')) {
                    $xp = new DOMXPath($doc);
                    $records = [];
                    $tables = $xp->query('//table');
                    if ($tables) foreach ($tables as $table) {
                        $section = self::tableSectionDom($xp, $table);
                        if ($section === null || $section === 'Notes') continue;
                        foreach (self::tableRowsDom($xp, $table) as $fields) {
                            if ($fields !== []) $records[] = ['section'=>$section,'source_type'=>'table','fields'=>$fields];
                        }
                    }
                    return $records;
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($old);
            }
        }

        return self::progressionRecordsRegex($article);
    }

    private static function tableSectionDom(DOMXPath $xp, DOMNode $table): ?string
    {
        // A normal BG-Wiki acquisition table follows a semantic H2/H3 heading.
        $prev = $xp->query('preceding::*[self::h2 or self::h3 or self::h4 or self::h5][1]', $table);
        if ($prev && $prev->length) {
            $name = self::canonicalHeading(self::nodePlain($prev->item(0)));
            if ($name !== null && $name !== 'Notes') return $name;
            // Reforged set tables are commonly transcluded under Notes rather than
            // an explicit Upgrade heading. Their column schema is nevertheless
            // structured and stable enough to classify as Upgrade Info.
            $inferred = self::inferSystemTableSectionDom($xp, $table);
            if ($inferred !== null) return $inferred;
            if ($name !== null) return $name;
        }

        // Some templates put the semantic label into the first table row/cell.
        $rows = $xp->query('.//tr', $table);
        if ($rows) foreach ($rows as $row) {
            $cells = $xp->query('./th|./td', $row);
            if (!$cells || !$cells->length) continue;
            if ($cells->length <= 2) {
                $label = self::nodePlain($cells->item(0));
                $name = self::canonicalHeading($label);
                if ($name !== null) return $name;
            }
            break;
        }
        return null;
    }

    private static function inferSystemTableSectionDom(DOMXPath $xp, DOMNode $table): ?string
    {
        $rows=$xp->query('.//tr',$table);if(!$rows)return null;
        $pendingComposite=null;
        foreach($rows as $row){
            $cells=$xp->query('./th|./td',$row);if(!$cells||!$cells->length)continue;
            $headers=[];$allTh=true;
            foreach($cells as $cell){
                if(strtolower($cell->nodeName)!=='th'){$allTh=false;continue;}
                $headers[]=trim(self::nodePlain($cell));
            }
            if(!$headers)continue;
            if($pendingComposite!==null && $allTh){
                $merged=self::mergeCompositeHeaders($pendingComposite,$headers);
                if($merged!==null)return self::inferSystemSectionHeaders($merged);
                $pendingComposite=null;
            }
            if(self::isCompositeHeaderTop($headers)){$pendingComposite=$headers;continue;}
            $section=self::inferSystemSectionHeaders($headers);
            if($section!==null)return $section;
            break;
        }
        return null;
    }

    private static function inferSystemSectionHeaders(array $headers): ?string
    {
        $h=array_map(static fn($x)=>strtolower(trim((string)$x)),$headers);
        $hasBase=in_array('base',$h,true)||in_array('base item',$h,true);
        $hasResult=in_array('reforged armor',$h,true)||in_array('result',$h,true)||in_array('upgrade to',$h,true);
        $joined='|'.implode('|',$h).'|';
        $hasCost=str_contains($joined,'required item')||str_contains($joined,'job ingredient')||str_contains($joined,'slot ingredient')||str_contains($joined,'gallimaufry')||str_contains($joined,'required shard')||str_contains($joined,'void ingredient')||preg_match('/required\s+.+\s+units/i',$joined);
        if($hasBase&&$hasResult&&$hasCost)return 'Upgrade Info';
        $hasTrial=in_array('trial number',$h,true) || in_array('trial',$h,true);
        $hasTrialBase=$hasBase;
        $hasTrialReward=in_array('reward',$h,true) || in_array('result',$h,true);
        $hasTrialRequirement=in_array('upgrade item',$h,true) || in_array('objective',$h,true) || in_array('requirement',$h,true) || in_array('requirements',$h,true);
        if($hasTrial&&$hasTrialBase&&$hasTrialReward&&$hasTrialRequirement)return 'Upgrade Info';
        return null;
    }

    private static function isCompositeHeaderTop(array $headers): bool
    {
        $h=array_map(static fn($x)=>strtolower(trim((string)$x)),$headers);
        return in_array('slot',$h,true) && in_array('day 1',$h,true) && in_array('day 2',$h,true) && in_array('reforged armor',$h,true);
    }

    private static function mergeCompositeHeaders(array $top,array $sub): ?array
    {
        if(!self::isCompositeHeaderTop($top))return null;
        $s=array_map(static fn($x)=>strtolower(trim((string)$x)),$sub);
        if(!in_array('base',$s,true) || !in_array('required item',$s,true) || !in_array('job ingredient',$s,true) || !in_array('ingredients',$s,true))return null;
        // BG-Wiki Reforged Artifact +3 tables render Day 1/Day 2 as grouped
        // top headers.  Flatten the two header rows to the six real data columns.
        return ['Slot','Base','Required Item','Job Ingredient','Ingredients','Reforged Armor'];
    }

    private static function tableRowsDom(DOMXPath $xp, DOMNode $table): array
    {
        $rows = $xp->query('.//tr', $table);
        if (!$rows) return [];
        $headers = [];
        $pendingComposite = null;
        $out = [];
        foreach ($rows as $row) {
            $cells = $xp->query('./th|./td', $row);
            if (!$cells || !$cells->length) continue;
            $texts = [];
            $hasTd = false;
            $hasTh = false;
            foreach ($cells as $cell) {
                $texts[] = self::nodePlain($cell);
                if (strtolower($cell->nodeName) === 'td') $hasTd = true; else $hasTh = true;
            }
            $texts = array_values(array_map('trim', $texts));
            if (!array_filter($texts, static fn($v)=>$v!=='')) continue;

            // Section-label rows such as "Obtained From:" are not data headers.
            if (count($texts) <= 2 && self::canonicalHeading($texts[0] ?? '') !== null) continue;

            // Real header rows on BG-Wiki typically use TH cells. Mixed TH/TD
            // is also accepted when we do not have a header yet.
            if ($headers === [] && $hasTh) {
                $candidate = [];
                foreach ($texts as $i=>$text) $candidate[$i] = $text !== '' ? $text : ('Column '.($i+1));
                if ($pendingComposite !== null) {
                    $merged=self::mergeCompositeHeaders($pendingComposite,$candidate);
                    if($merged!==null){$headers=$merged;$pendingComposite=null;continue;}
                    $pendingComposite=null;
                }
                if(self::isCompositeHeaderTop($candidate)){$pendingComposite=$candidate;continue;}
                if (self::looksLikeHeaderRow($candidate)) {
                    $headers = $candidate;
                    continue;
                }
            }
            if ($headers === []) continue;

            // Ignore repeated header rows inside long tables.
            if (self::sameHeaderTexts($headers, $texts)) continue;

            $fields = [];
            foreach ($headers as $i=>$header) {
                $value = trim($texts[$i] ?? '');
                if ($value === '') continue;
                $fields[$header] = $value;
            }
            if ($fields && !self::recordLooksLikeHeaderOnly($fields)) $out[] = $fields;
        }
        return $out;
    }

    private static function nodePlain(?DOMNode $node): string
    {
        if (!$node) return '';
        // clone HTML so BR tags become line breaks before strip_tags.
        $doc = $node->ownerDocument;
        $html = $doc ? $doc->saveHTML($node) : ($node->textContent ?? '');
        return self::htmlToPlain((string)$html, 4000, false);
    }

    private static function looksLikeHeaderRow(array $headers): bool
    {
        $generic = ['npc name','npc','name','monster name','level','zone','rate','notes','special event','event','type of event','item','required items','cost','currency','quantity','qty'];
        $hits = 0;
        foreach ($headers as $h) if (in_array(strtolower(trim($h)), $generic, true)) $hits++;
        return $hits >= 1 || count($headers) >= 2;
    }

    private static function sameHeaderTexts(array $headers, array $texts): bool
    {
        if (count($headers) !== count($texts)) return false;
        foreach ($headers as $i=>$h) if (strtolower(trim($h)) !== strtolower(trim($texts[$i] ?? ''))) return false;
        return true;
    }

    private static function recordLooksLikeHeaderOnly(array $fields): bool
    {
        $generic = ['npc name','npc','name','monster name','level','zone','rate','notes','special event','event','type of event','item','required items','cost','currency','quantity','qty'];
        foreach ($fields as $value) if (!in_array(strtolower(trim((string)$value)), $generic, true)) return false;
        return true;
    }

    private static function progressionRecordsRegex(string $article): array
    {
        $out=[];
        if(!preg_match_all('~<table\\b[^>]*>(.*?)</table>~is',$article,$tables,PREG_OFFSET_CAPTURE))return $out;
        foreach($tables[0] as $entry){
            [$tableHtml,$offset]=$entry;
            $before=substr($article,max(0,$offset-4000),min(4000,$offset));
            $section=null;
            if(preg_match_all('~<h[2-5]\\b[^>]*>(.*?)</h[2-5]>~is',$before,$hm) && !empty($hm[1])){
                $section=self::canonicalHeading(self::htmlToPlain((string)end($hm[1]),300,false));
            }
            if(!preg_match_all('~<tr\\b[^>]*>(.*?)</tr>~is',$tableHtml,$rm))continue;
            $headers=[];$pendingComposite=null;
            foreach($rm[1] as $rowHtml){
                if(!preg_match_all('~<(th|td)\\b[^>]*>(.*?)</\\1>~is',$rowHtml,$cm,PREG_SET_ORDER))continue;
                $texts=[];$hasTh=false;
                foreach($cm as $c){$hasTh=$hasTh||strtolower($c[1])==='th';$texts[]=self::htmlToPlain($c[2],1000,false);}
                if(count($texts)<=2 && self::canonicalHeading($texts[0]??'')!==null)continue;
                if($headers===[] && $hasTh){
                    if($pendingComposite!==null){
                        $merged=self::mergeCompositeHeaders($pendingComposite,$texts);
                        if($merged!==null){$headers=$merged;$pendingComposite=null;if($section===null || $section==='Notes')$section=self::inferSystemSectionHeaders($headers) ?? $section;continue;}
                        $pendingComposite=null;
                    }
                    if(self::isCompositeHeaderTop($texts)){$pendingComposite=$texts;continue;}
                    $headers=$texts;
                    if($section===null || $section==='Notes')$section=self::inferSystemSectionHeaders($headers) ?? $section;
                    continue;
                }
                if($headers===[] || $section===null || $section==='Notes')continue;
                $fields=[];foreach($headers as $i=>$h){$v=trim($texts[$i]??'');if($v!=='')$fields[trim($h)]=$v;}
                if($fields&&!self::recordLooksLikeHeaderOnly($fields))$out[]=['section'=>$section,'source_type'=>'table','fields'=>$fields];
            }
        }
        return $out;
    }

    /**
     * Extract an explicit ordered item-version ladder from an information-table
     * `Versions:` row. Ultimate weapon pages expose each stage as a real wiki link;
     * MediaWiki renders the current stage as a self-link. We use those explicit
     * targets only and never manufacture page titles from the visible Lv./iLevel label.
     *
     * @return array<int,array{section:string,source_type:string,fields:array<string,string>}>
     */
    public static function versionChainRecords(string $content, string $pageTitle, string $format='html'): array
    {
        if($format!=='html' || trim($content)==='')return [];
        $article=self::articleHtml($content);$titles=[];

        if(class_exists('DOMDocument') && class_exists('DOMXPath')){
            $old=libxml_use_internal_errors(true);
            try{
                $doc=new DOMDocument();
                if(self::loadHtmlUtf8($doc,$article)){
                    $xp=new DOMXPath($doc);
                    $rows=$xp->query('//tr');
                    if($rows)foreach($rows as $row){
                        $cells=$xp->query('./th|./td',$row);if(!$cells||$cells->length<2)continue;
                        $label=self::normalizeHeading(self::nodePlain($cells->item(0)));
                        if($label!=='versions')continue;
                        for($i=1;$i<$cells->length;$i++){
                            $cell=$cells->item($i);
                            $nodes=$xp->query('.//a[@href] | .//*[contains(concat(" ", normalize-space(@class), " "), " selflink ")]',$cell);
                            if(!$nodes)continue;
                            foreach($nodes as $node){
                                $title='';
                                if(strtolower($node->nodeName)==='a')$title=self::wikiTitleFromHref((string)$node->attributes?->getNamedItem('href')?->nodeValue);
                                else $title=trim($pageTitle);
                                if($title!==''&&!in_array($title,$titles,true))$titles[]=$title;
                            }
                        }
                        if($titles)break;
                    }
                }
            }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        }

        if(!$titles && preg_match_all('~<tr\b[^>]*>(.*?)</tr>~is',$article,$rm)){
            foreach($rm[1] as $rowHtml){
                if(!preg_match_all('~<(th|td)\b[^>]*>(.*?)</\1>~is',$rowHtml,$cm,PREG_SET_ORDER)||count($cm)<2)continue;
                if(self::normalizeHeading(self::htmlToPlain($cm[0][2],200,false))!=='versions')continue;
                $rest='';for($i=1;$i<count($cm);$i++)$rest.=$cm[$i][2];
                preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>.*?</a>|<[^>]*class=["\'][^"\']*selflink[^"\']*["\'][^>]*>.*?</[^>]+>~is',$rest,$links,PREG_SET_ORDER);
                foreach($links as $m){
                    $title='';
                    if(!empty($m[1]))$title=self::wikiTitleFromHref(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'));
                    else $title=trim($pageTitle);
                    if($title!==''&&!in_array($title,$titles,true))$titles[]=$title;
                }
                break;
            }
        }

        if(count($titles)<2)return [];
        $out=[];
        for($i=0;$i<count($titles)-1;$i++)$out[]=['section'=>'Version Chain','source_type'=>'versions','fields'=>[
            'From Item'=>$titles[$i],
            'To Item'=>$titles[$i+1],
            'Source'=>'Ultimate Weapon Information / Versions',
        ]];
        return $out;
    }

    private static function wikiTitleFromHref(string $href): string
    {
        $href=html_entity_decode(trim($href),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if($href==='')return '';
        $path=parse_url($href,PHP_URL_PATH);if(!is_string($path))return '';
        $prefix='/ffxi/';$pos=strpos($path,$prefix);if($pos===false)return '';
        $title=rawurldecode(substr($path,$pos+strlen($prefix)));
        if($title===''||preg_match('/^(?:File|Category|Special|User|Talk):/i',$title))return '';
        return trim(str_replace('_',' ',$title));
    }

    /**
     * Return explicit version-candidate page titles linked from an article.  This
     * is used only to disambiguate a real game item against BG-Wiki pages; it does
     * not create progression edges by itself.
     *
     * @return array<int,string>
     */
    public static function versionCandidateTitles(string $content, string $baseTitle, string $format='html'): array
    {
        if($format!=='html' || trim($content)==='')return [];
        $article=self::articleHtml($content);$baseNorm=LacProfileParser::normalizeName($baseTitle);$out=[];
        if($baseNorm==='')return [];

        if(class_exists('DOMDocument') && class_exists('DOMXPath')){
            $old=libxml_use_internal_errors(true);
            try{
                $doc=new DOMDocument();
                if(self::loadHtmlUtf8($doc,'<!doctype html><html><body>'.$article.'</body></html>')){
                    $xp=new DOMXPath($doc);$links=$xp->query('//a[@href]');
                    if($links)foreach($links as $a){
                        $href=(string)$a->attributes?->getNamedItem('href')?->nodeValue;
                        $title=self::wikiTitleFromHref($href);if($title==='')continue;
                        $norm=LacProfileParser::normalizeName($title);
                        if($norm===$baseNorm)continue;
                        if(str_starts_with($norm,$baseNorm.' (') && !in_array($title,$out,true))$out[]=$title;
                    }
                }
            }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        }
        if(!$out && preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>~i',$article,$m)){
            foreach($m[1] as $href){$title=self::wikiTitleFromHref((string)$href);if($title==='')continue;$norm=LacProfileParser::normalizeName($title);if($norm!==$baseNorm&&str_starts_with($norm,$baseNorm.' (')&&!in_array($title,$out,true))$out[]=$title;}
        }
        return $out;
    }

    /**
     * Extract a small, conservative fingerprint from the top item-information
     * block.  It is intentionally limited to values that also exist in Ashita's
     * resource data, making ambiguous BG-Wiki titles resolvable without guessing
     * from a suffix such as "119 III".
     *
     * @return array<string,int>
     */
    public static function itemFacts(string $content, string $format='html'): array
    {
        if($format!=='html' || trim($content)==='')return [];
        $plain=self::htmlToPlain(self::articleHtml($content),18000,false);$facts=[];
        $patterns=[
            'level'=>'~(?:^|\\n)Level:\\s*(\\d+)~i',
            'item_level'=>'~(?:^|\\n)Item Level:\\s*(\\d+)~i',
            'dmg'=>'~\\bDMG:\\s*\\+?(\\d+)~i',
            'delay'=>'~\\bDelay:\\s*\\+?(\\d+)~i',
            'atk'=>'~\\bAttack\\+(\\d+)~i',
            'mdmg'=>'~\\bMagic Damage\\+(\\d+)~i',
            'skkatana'=>'~\\bKatana skill \\+(\\d+)~i',
            'skparry'=>'~\\bParrying skill \\+(\\d+)~i',
            'skh2h'=>'~\\bHand-to-Hand skill \\+(\\d+)~i',
            'skdagger'=>'~\\bDagger skill \\+(\\d+)~i',
            'sksword'=>'~\\bSword skill \\+(\\d+)~i',
            'skgsword'=>'~\\bGreat Sword skill \\+(\\d+)~i',
            'skaxe'=>'~\\bAxe skill \\+(\\d+)~i',
            'skgaxe'=>'~\\bGreat Axe skill \\+(\\d+)~i',
            'skscythe'=>'~\\bScythe skill \\+(\\d+)~i',
            'skpolearm'=>'~\\bPolearm skill \\+(\\d+)~i',
            'skgkatana'=>'~\\bGreat Katana skill \\+(\\d+)~i',
            'skclub'=>'~\\bClub skill \\+(\\d+)~i',
            'skstaff'=>'~\\bStaff skill \\+(\\d+)~i',
        ];
        foreach($patterns as $key=>$pattern)if(preg_match($pattern,$plain,$m))$facts[$key]=(int)$m[1];
        return $facts;
    }

    public static function progressionGroups(array $sections): array
    {
        $groups=['Acquisition'=>[],'Augmentation'=>[],'Upgrade / Use'=>[],'Notes'=>[]];
        foreach($sections as $name=>$body){
            $group=match($name){
                'Obtained From','Purchased From','Redeemed From','Dropped From','Quest'=>'Acquisition',
                'Augmented Via'=>'Augmentation',
                'Upgrade Info','Upgraded From','Used In Item Upgrades','Used In Recipe'=>'Upgrade / Use',
                default=>'Notes',
            };
            $groups[$group][$name]=$body;
        }
        return array_filter($groups,static fn($x)=>$x!==[]);
    }

    private static function isArticleTail(string $line): bool
    {
        $n=strtolower(trim($line));
        return str_starts_with($n,'retrieved from ') || str_starts_with($n,'category:') || $n==='categories';
    }

    private static function cut(string $text, int $max): string
    {
        if ($max <= 0) return '';
        return function_exists('mb_substr') ? mb_substr($text,0,$max) : substr($text,0,$max);
    }

    private static function htmlToPlain(string $html,int $max,bool $compact=false): string
    {
        $x=preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is',' ',$html)??$html;
        $x=preg_replace('~<br\s*/?>~i',"\n",$x)??$x;
        $x=preg_replace('~</(?:p|div|li|tr|h[1-6]|table|section|dl|dt|dd)>~i',"\n",$x)??$x;
        $x=preg_replace('~</(?:td|th)>~i',"\t",$x)??$x;
        $x=strip_tags($x);
        $x=html_entity_decode($x,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $lines=[];
        foreach(preg_split('/\R/u',$x)?:[] as $line){
            $line=trim(preg_replace('/[\t ]+/u',' ',$line)??$line);
            if($line==='')continue;
            if($line==='[edit]')continue;
            if(self::isArticleTail($line))continue;
            // Remove exact MediaWiki boilerplate lines that can still live inside
            // content wrappers on older skins, without suppressing item prose.
            if(in_array(strtolower($line),['from ffxi wiki','jump to navigation','jump to search'],true))continue;
            $lines[]=$line;
        }
        $plain=trim(implode($compact?' ':"\n",$lines));
        if($compact)$plain=trim(preg_replace('/\s+/u',' ',$plain)??$plain);
        return self::cut($plain,$max);
    }
}
