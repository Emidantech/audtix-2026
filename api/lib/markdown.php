<?php
declare(strict_types=1);
/* Small, safe Markdown renderer for posts. Everything is HTML-escaped first; only the formatting below is produced.
   Supports: ## and ### headings, paragraphs, **bold**, *italic*, `code`, [links](https://...), ![images](/uploads/...),
   - and 1. lists, > quotes, ``` code blocks, --- lines. Links may only be http(s), mailto, tel or site paths. */

function md_url(string $u): ?string {
    $u = trim(html_entity_decode($u, ENT_QUOTES));
    if (preg_match('#^(https?://|mailto:|tel:|/(?!/)|[a-z0-9._-]+\.html)#i', $u)) return htmlspecialchars($u, ENT_QUOTES);
    return null;
}
function md_inline(string $t): string {
    $codes = [];
    $t = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) { $codes[] = '<code>' . $m[1] . '</code>'; return "\x01" . (count($codes) - 1) . "\x01"; }, $t);
    $t = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', fn($m) => ($u = md_url($m[2])) ? '<img src="' . $u . '" alt="' . $m[1] . '" loading="lazy">' : $m[0], $t);
    $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $u = md_url($m[2]); if (!$u) return $m[1];
        $ext = preg_match('#^https?://#i', $u) ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<a href="' . $u . '"' . $ext . '>' . $m[1] . '</a>';
    }, $t);
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $t);
    return preg_replace_callback("/\x01(\d+)\x01/", fn($m) => $codes[(int) $m[1]], $t);
}
function md_render(string $src): string {
    $src = htmlspecialchars(str_replace(["\r\n", "\r"], "\n", $src), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $lines = explode("\n", $src); $out = []; $para = []; $list = null; $items = []; $quote = []; $inCode = false; $code = [];
    $flushP = function () use (&$para, &$out) { if ($para) { $out[] = '<p>' . md_inline(implode("<br>\n", $para)) . '</p>'; $para = []; } };
    $flushL = function () use (&$list, &$items, &$out) { if ($list) { $out[] = "<$list>" . implode('', array_map(fn($i) => '<li>' . md_inline($i) . '</li>', $items)) . "</$list>"; $list = null; $items = []; } };
    $flushQ = function () use (&$quote, &$out) { if ($quote) { $out[] = '<blockquote><p>' . md_inline(implode("<br>\n", $quote)) . '</p></blockquote>'; $quote = []; } };
    foreach ($lines as $l) {
        if (preg_match('/^```/', $l)) { if ($inCode) { $out[] = '<pre><code>' . implode("\n", $code) . '</code></pre>'; $code = []; $inCode = false; } else { $flushP(); $flushL(); $flushQ(); $inCode = true; } continue; }
        if ($inCode) { $code[] = $l; continue; }
        if (trim($l) === '') { $flushP(); $flushL(); $flushQ(); continue; }
        if (preg_match('/^(#{2,4})\s+(.+)$/', $l, $m)) { $flushP(); $flushL(); $flushQ(); $n = strlen($m[1]); $out[] = "<h$n>" . md_inline(trim($m[2])) . "</h$n>"; continue; }
        if (preg_match('/^#\s+(.+)$/', $l, $m)) { $flushP(); $flushL(); $flushQ(); $out[] = '<h2>' . md_inline(trim($m[1])) . '</h2>'; continue; }
        if (preg_match('/^(-{3,}|\*{3,})\s*$/', $l)) { $flushP(); $flushL(); $flushQ(); $out[] = '<hr>'; continue; }
        if (preg_match('/^&gt;\s?(.*)$/', $l, $m)) { $flushP(); $flushL(); $quote[] = $m[1]; continue; }
        if (preg_match('/^\s*[-*+]\s+(.+)$/', $l, $m)) { $flushP(); $flushQ(); if ($list !== 'ul') { $flushL(); $list = 'ul'; } $items[] = $m[1]; continue; }
        if (preg_match('/^\s*\d+[.)]\s+(.+)$/', $l, $m)) { $flushP(); $flushQ(); if ($list !== 'ol') { $flushL(); $list = 'ol'; } $items[] = $m[1]; continue; }
        if ($list && preg_match('/^\s{2,}(.+)$/', $l, $m)) { $items[count($items) - 1] .= ' ' . $m[1]; continue; }
        $flushL(); $flushQ(); $para[] = $l;
    }
    if ($inCode) $out[] = '<pre><code>' . implode("\n", $code) . '</code></pre>';
    $flushP(); $flushL(); $flushQ();
    return implode("\n", $out);
}
function md_plain(string $src, int $len = 220): string {
    $t = preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/\[([^\]]+)\]\([^)]*\)/', '/[#*`>_-]+/', '/\s+/'], ['', '$1', ' ', ' '], $src);
    return mb_strimwidth(trim((string) $t), 0, $len, '…');
}
function slugify(string $t): string {
    $t = strtolower(trim((string) (function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) : $t)));
    return trim(substr((string) preg_replace('/[^a-z0-9]+/', '-', $t), 0, 120), '-');
}
