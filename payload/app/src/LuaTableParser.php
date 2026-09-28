<?php
declare(strict_types=1);

/**
 * Small parser for the declarative Lua table subset used by these LuAshitacast profiles.
 * It intentionally does not execute Lua.
 */
final class LuaTableParser
{
    private array $tokens = [];
    private int $i = 0;

    public function parse(string $tableSource, int $baseLine = 1): array
    {
        $this->tokens = $this->tokenize($tableSource, $baseLine);
        $this->i = 0;
        if ($this->peekType('IDENT') && ($this->peek()['value'] ?? '') === 'T') {
            $this->i++;
        }
        return $this->parseValue();
    }

    public static function extractAssignments(string $source): array
    {
        $out = [];
        $patterns = [
            '/\blocal\s+([A-Za-z_]\w*)\s*=\s*(?:T\s*)?\{/' => static fn(array $m): string => $m[1],
            '/\bprofile\.Packer\s*=\s*(?:T\s*)?\{/' => static fn(array $m): string => 'profile.Packer',
            '/\bgcinclude\.sets\s*=\s*(?:T\s*)?\{/' => static fn(array $m): string => 'gcinclude.sets',
        ];
        foreach ($patterns as $pattern => $nameFn) {
            if (!preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) continue;
            foreach ($matches as $m) {
                $name = $nameFn(array_map(static fn($x) => is_array($x) ? $x[0] : $x, $m));
                $matchText = $m[0][0];
                $matchPos = $m[0][1];
                $bracePos = strpos($source, '{', $matchPos + strlen($matchText) - 1);
                if ($bracePos === false) continue;
                [$text, $endPos] = self::extractBalancedBraces($source, $bracePos);
                $line = substr_count(substr($source, 0, $bracePos), "\n") + 1;
                $out[$name] = ['text' => $text, 'line' => $line, 'start' => $bracePos, 'end' => $endPos];
            }
        }
        return $out;
    }

    public static function extractBalancedBraces(string $source, int $openPos): array
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $lineComment = false;
        $blockComment = false;
        $len = strlen($source);
        for ($p = $openPos; $p < $len; $p++) {
            $c = $source[$p];
            $n = $p + 1 < $len ? $source[$p + 1] : '';
            if ($lineComment) {
                if ($c === "\n") $lineComment = false;
                continue;
            }
            if ($blockComment) {
                if ($c === ']' && $n === ']') { $blockComment = false; $p++; }
                continue;
            }
            if ($quote !== null) {
                if ($escaped) { $escaped = false; continue; }
                if ($c === '\\') { $escaped = true; continue; }
                if ($c === $quote) $quote = null;
                continue;
            }
            if ($c === '-' && $n === '-') {
                if (substr($source, $p + 2, 2) === '[[') { $blockComment = true; $p += 3; }
                else { $lineComment = true; $p++; }
                continue;
            }
            if ($c === "'" || $c === '"') { $quote = $c; continue; }
            if ($c === '{') $depth++;
            elseif ($c === '}') {
                $depth--;
                if ($depth === 0) return [substr($source, $openPos, $p - $openPos + 1), $p];
            }
        }
        throw new RuntimeException('Unbalanced Lua table braces.');
    }

    private function tokenize(string $s, int $baseLine): array
    {
        $t = [];
        $line = $baseLine;
        $len = strlen($s);
        for ($p = 0; $p < $len;) {
            $c = $s[$p];
            if ($c === "\n") { $line++; $p++; continue; }
            if (ctype_space($c)) { $p++; continue; }
            if ($c === '-' && $p + 1 < $len && $s[$p + 1] === '-') {
                if ($p + 3 < $len && substr($s, $p + 2, 2) === '[[') {
                    $end = strpos($s, ']]', $p + 4);
                    if ($end === false) $end = $len - 2;
                    $line += substr_count(substr($s, $p, $end + 2 - $p), "\n");
                    $p = $end + 2;
                } else {
                    $end = strpos($s, "\n", $p + 2);
                    if ($end === false) break;
                    $p = $end;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c; $startLine = $line; $p++; $v = '';
                while ($p < $len) {
                    $ch = $s[$p++];
                    if ($ch === "\n") $line++;
                    if ($ch === '\\' && $p < $len) {
                        $esc = $s[$p++];
                        $v .= match ($esc) { 'n' => "\n", 'r' => "\r", 't' => "\t", default => $esc };
                        continue;
                    }
                    if ($ch === $quote) break;
                    $v .= $ch;
                }
                $t[] = ['type' => 'STRING', 'value' => $v, 'line' => $startLine];
                continue;
            }
            if (ctype_digit($c) || ($c === '-' && $p + 1 < $len && ctype_digit($s[$p + 1]))) {
                $start = $p; $p++;
                while ($p < $len && (ctype_digit($s[$p]) || $s[$p] === '.')) $p++;
                $raw = substr($s, $start, $p - $start);
                $t[] = ['type' => 'NUMBER', 'value' => str_contains($raw, '.') ? (float)$raw : (int)$raw, 'line' => $line];
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $start = $p; $p++;
                while ($p < $len && (ctype_alnum($s[$p]) || $s[$p] === '_')) $p++;
                $t[] = ['type' => 'IDENT', 'value' => substr($s, $start, $p - $start), 'line' => $line];
                continue;
            }
            if (str_contains('{}[]=,;.', $c)) {
                $t[] = ['type' => $c, 'value' => $c, 'line' => $line]; $p++; continue;
            }
            $p++;
        }
        return $t;
    }

    private function parseValue(): array
    {
        $tok = $this->peek();
        if ($tok === null) return ['type' => 'nil', 'value' => null, 'line' => 0];
        if ($tok['type'] === 'STRING') { $this->i++; return ['type' => 'string', 'value' => $tok['value'], 'line' => $tok['line']]; }
        if ($tok['type'] === 'NUMBER') { $this->i++; return ['type' => 'number', 'value' => $tok['value'], 'line' => $tok['line']]; }
        if ($tok['type'] === 'IDENT') {
            $this->i++;
            if ($tok['value'] === 'T' && $this->peekType('{')) return $this->parseTable();
            if ($tok['value'] === 'true' || $tok['value'] === 'false') return ['type' => 'bool', 'value' => $tok['value'] === 'true', 'line' => $tok['line']];
            if ($tok['value'] === 'nil') return ['type' => 'nil', 'value' => null, 'line' => $tok['line']];
            return ['type' => 'ref', 'value' => $tok['value'], 'line' => $tok['line']];
        }
        if ($tok['type'] === '{') return $this->parseTable();
        $this->i++;
        return ['type' => 'unknown', 'value' => $tok['value'], 'line' => $tok['line']];
    }

    private function parseTable(): array
    {
        $open = $this->consume('{');
        $entries = [];
        $auto = 1;
        while (($tok = $this->peek()) !== null && $tok['type'] !== '}') {
            if ($tok['type'] === ',' || $tok['type'] === ';') { $this->i++; continue; }
            $key = null; $entryLine = $tok['line'];
            if ($tok['type'] === '[') {
                $this->i++;
                $keyNode = $this->parseValue();
                $key = $keyNode['value'];
                $this->consume(']'); $this->consume('=');
                $value = $this->parseValue();
            } elseif ($tok['type'] === 'IDENT' && (($this->tokens[$this->i + 1]['type'] ?? null) === '=')) {
                $key = $tok['value']; $this->i += 2;
                $value = $this->parseValue();
            } else {
                $key = $auto++;
                $value = $this->parseValue();
            }
            $entries[] = ['key' => $key, 'value' => $value, 'line' => $entryLine];
            if ($this->peekType(',') || $this->peekType(';')) $this->i++;
        }
        $this->consume('}');
        return ['type' => 'table', 'entries' => $entries, 'line' => $open['line']];
    }

    public static function tableMap(array $node): array
    {
        $map = [];
        foreach ($node['entries'] ?? [] as $entry) $map[$entry['key']] = $entry['value'];
        return $map;
    }

    public static function scalar(array $node): mixed
    {
        return in_array($node['type'] ?? '', ['string','number','bool','ref'], true) ? ($node['value'] ?? null) : null;
    }

    private function peek(): ?array { return $this->tokens[$this->i] ?? null; }
    private function peekType(string $type): bool { return (($this->tokens[$this->i]['type'] ?? null) === $type); }
    private function consume(string $type): array
    {
        $tok = $this->tokens[$this->i] ?? null;
        if ($tok === null || $tok['type'] !== $type) throw new RuntimeException('Lua table parse error: expected ' . $type);
        $this->i++; return $tok;
    }
}
