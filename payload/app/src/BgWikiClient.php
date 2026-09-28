<?php
declare(strict_types=1);

final class BgWikiClient
{
    private const BASE = 'https://www.bg-wiki.com';
    private const ARTICLE_BASE = 'https://www.bg-wiki.com/ffxi/';
    private const SEARCH = 'https://www.bg-wiki.com/ffxi/Special:Search?search=';
    private static float $lastRequestAt = 0.0;

    public function fetchPage(string $title): array
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('BG-Wiki title must not be empty');
        }

        // BG-Wiki blocks public MediaWiki API access by firewall. Read the normal
        // article HTML instead and resolve title aliases through Special:Search.
        $requestedUrl = self::pageUrl($title);
        $response = $this->fetchHtml($requestedUrl);

        if (!$this->looksLikeExistingArticle($response['body'], $response['status'])) {
            $resolved = $this->resolveViaSearch($title);
            if ($resolved === null) {
                return [
                    'requested_title' => $title,
                    'title' => $title,
                    'exists' => false,
                    'canonical_url' => $requestedUrl,
                    'revision_id' => null,
                    'revision_timestamp' => null,
                    'content' => '',
                    'content_format' => 'html',
                ];
            }
            $response = $this->fetchHtml($resolved['url']);
            $requestedUrl = $resolved['url'];
        }

        $html = $response['body'];
        if (!$this->looksLikeExistingArticle($html, $response['status'])) {
            return [
                'requested_title' => $title,
                'title' => $title,
                'exists' => false,
                'canonical_url' => $requestedUrl,
                'revision_id' => null,
                'revision_timestamp' => null,
                'content' => '',
                'content_format' => 'html',
            ];
        }

        $canonical = $this->extractCanonicalUrl($html) ?? $response['effective_url'] ?? $requestedUrl;
        $pageTitle = $this->extractTitle($html) ?? $this->titleFromUrl($canonical) ?? $title;
        $revisionId = $this->extractRevisionId($html);
        $revisionTimestamp = $this->extractRevisionTimestamp($html) ?? $response['last_modified'];

        return [
            'requested_title' => $title,
            'title' => $pageTitle,
            'exists' => true,
            'canonical_url' => $canonical,
            'revision_id' => $revisionId,
            'revision_timestamp' => $revisionTimestamp,
            'content' => $html,
            'content_format' => 'html',
        ];
    }

    private function resolveViaSearch(string $title): ?array
    {
        $response = $this->fetchHtml(self::SEARCH . rawurlencode($title));
        $html = $response['body'];

        // Prefer MediaWiki's explicit search-result headings.
        if (preg_match('~class=["\'][^"\']*mw-search-result-heading[^"\']*["\'][^>]*>.*?<a\b[^>]*href=["\']([^"\']+)["\'][^>]*?(?:title=["\']([^"\']+)["\'])?~is', $html, $m)) {
            $url = $this->absoluteUrl(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url !== null) {
                return ['url' => $url, 'title' => isset($m[2]) ? html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') : $title];
            }
        }

        // Some MediaWiki skins use a different result wrapper; accept the first
        // article-looking /ffxi/ link but exclude Special/File/Category/User pages.
        if (preg_match_all('~<a\b[^>]*href=["\'](/ffxi/[^"\'#?]+)["\'][^>]*>~i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $decoded = rawurldecode($href);
                if (preg_match('~/ffxi/(?:Special:|File:|Category:|User:|Talk:)~i', $decoded)) {
                    continue;
                }
                return ['url' => self::BASE . $href, 'title' => $title];
            }
        }
        return null;
    }

    private function fetchHtml(string $url): array
    {
        $this->throttle();
        $headers = [
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 FFXIGearCenter/0.3',
            'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.8',
            'Connection: close',
        ];
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 30,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 8,
                'header' => implode("\r\n", $headers) . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        $responseHeaders = $http_response_header ?? [];
        if ($raw !== false) {
            return $this->responseFromHeaders((string)$raw, $url, $responseHeaders);
        }

        // Fallback to the system curl binary. This is useful on installations
        // where PHP streams have local TLS/CA quirks, without requiring php-curl.
        $curl = trim((string)@shell_exec('command -v curl 2>/dev/null'));
        if ($curl !== '') {
            $this->throttle();
            $marker = "\n__GCFINALURL__:";
            $cmd = escapeshellarg($curl)
                . ' -L --fail-with-body --silent --show-error --compressed'
                . ' --connect-timeout 10 --max-time 30'
                . ' -A ' . escapeshellarg('Mozilla/5.0 (X11; Linux x86_64) FFXIGearCenter/0.3')
                . ' -w ' . escapeshellarg($marker . '%{url_effective}:%{http_code}')
                . ' ' . escapeshellarg($url) . ' 2>&1';
            $out = @shell_exec($cmd);
            if (is_string($out) && preg_match('~\n__GCFINALURL__:(.*):(\d{3})\s*$~s', $out, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                $body = substr($out, 0, $pos);
                $effective = trim($m[1][0]);
                $status = (int)$m[2][0];
                if ($status >= 200 && $status < 500 && $body !== '') {
                    return ['body' => $body, 'status' => $status, 'effective_url' => $effective, 'last_modified' => null];
                }
            }
        }

        $last = error_get_last();
        $detail = is_array($last) && isset($last['message']) ? $last['message'] : 'unknown network error';
        throw new RuntimeException('BG-Wiki HTML request failed: ' . $detail . ' [' . $url . ']');
    }

    private function responseFromHeaders(string $body, string $requestedUrl, array $headers): array
    {
        $status = 0;
        $effective = $requestedUrl;
        $lastModified = null;
        foreach ($headers as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $line, $m)) {
                $status = (int)$m[1];
            } elseif (stripos($line, 'Location:') === 0) {
                $loc = trim(substr($line, 9));
                $effective = $this->absoluteUrl($loc) ?? $effective;
            } elseif (stripos($line, 'Last-Modified:') === 0) {
                $ts = strtotime(trim(substr($line, 14)));
                if ($ts !== false) $lastModified = gmdate('c', $ts);
            }
        }
        return ['body' => $body, 'status' => $status ?: 200, 'effective_url' => $effective, 'last_modified' => $lastModified];
    }

    private function looksLikeExistingArticle(string $html, int $status): bool
    {
        if ($status === 404 || $html === '') return false;
        if (stripos($html, 'There is currently no text in this page') !== false) return false;
        if (stripos($html, 'noarticletext') !== false) return false;
        return stripos($html, 'mw-content-text') !== false || stripos($html, 'firstHeading') !== false || stripos($html, 'mw-first-heading') !== false;
    }

    private function extractCanonicalUrl(string $html): ?string
    {
        if (preg_match('~<link\b[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<link\b[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\']~i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('~<h1\b[^>]*(?:id=["\']firstHeading["\']|class=["\'][^"\']*mw-first-heading[^"\']*["\'])[^>]*>(.*?)</h1>~is', $html, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return $t !== '' ? $t : null;
        }
        if (preg_match('~<title>(.*?)\s+-\s+FFXI Wiki</title>~is', $html, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return $t !== '' ? $t : null;
        }
        return null;
    }

    private function extractRevisionId(string $html): ?int
    {
        // Modern MediaWiki exposes the current revision in the startup config even
        // when the skin does not render a visible Permanent link with ?oldid=.
        foreach ([
            '~["\']wgRevisionId["\']\s*[:=]\s*(\d+)~i',
            '~["\']wgCurRevisionId["\']\s*[:=]\s*(\d+)~i',
            '~[?&]oldid=(\d+)~i',
        ] as $pattern) {
            if (preg_match_all($pattern, $html, $m) && !empty($m[1])) {
                return (int)end($m[1]);
            }
        }
        return null;
    }

    private function extractRevisionTimestamp(string $html): ?string
    {
        if (preg_match('~<meta\b[^>]*(?:property|name)=["\']article:modified_time["\'][^>]*content=["\']([^"\']+)["\']~i', $html, $m)) {
            $ts = strtotime(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($ts !== false) return gmdate('c', $ts);
        }
        if (preg_match('~This page was last edited on\s+([^<]+)~i', $html, $m)) {
            $ts = strtotime(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($ts !== false) return gmdate('c', $ts);
        }
        return null;
    }

    private function titleFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) return null;
        $prefix = '/ffxi/';
        $pos = strpos($path, $prefix);
        if ($pos === false) return null;
        return str_replace('_', ' ', rawurldecode(substr($path, $pos + strlen($prefix))));
    }

    private function absoluteUrl(string $url): ?string
    {
        if (preg_match('~^https?://~i', $url)) return $url;
        if (str_starts_with($url, '//')) return 'https:' . $url;
        if (str_starts_with($url, '/')) return self::BASE . $url;
        return null;
    }

    private function throttle(): void
    {
        $now = microtime(true);
        $wait = 1.05 - ($now - self::$lastRequestAt);
        if ($wait > 0) usleep((int)($wait * 1_000_000));
        self::$lastRequestAt = microtime(true);
    }

    public static function pageUrl(string $title): string
    {
        return self::ARTICLE_BASE . rawurlencode(str_replace(' ', '_', $title));
    }

    public static function searchUrl(string $name): string
    {
        return self::SEARCH . rawurlencode($name);
    }
}
