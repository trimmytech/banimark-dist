<?php

namespace Banimark\Ui;

/**
 * The formatting a chat message may carry - **bold**, *italic*, `code`, fenced
 * code, links, bullet and numbered lists, line breaks - and nothing else. Text
 * is HTML-escaped FIRST, so no markup a visitor or a model writes survives;
 * only the constructs below are turned back into tags, and links are limited
 * to http(s) / mailto. The JS twin (resources/design/markdown.js) implements
 * the same rules and both are checked against tests/fixtures/markdown.json.
 */
final class Markdown
{
    public static function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }
        // fenced code blocks are lifted out first so nothing inside them is touched
        $blocks = [];
        $text = preg_replace_callback('/```[a-zA-Z0-9_-]*\n?(.*?)```/s', function ($m) use (&$blocks) {
            $blocks[] = '<pre><code>'.self::esc(rtrim($m[1], "\n")).'</code></pre>';
            return "\x00B".(count($blocks) - 1)."\x00";
        }, $text);

        $out = [];
        $list = null; // 'ul' | 'ol' while inside a list
        foreach (explode("\n", $text) as $line) {
            $isUl = preg_match('/^\s*[-*•]\s+(.*)$/u', $line, $lm);
            $isOl = !$isUl && preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $lm);
            if ($isUl || $isOl) {
                $kind = $isUl ? 'ul' : 'ol';
                if ($list !== $kind) {
                    if ($list !== null) { $out[] = "</{$list}>"; }
                    $out[] = "<{$kind}>";
                    $list = $kind;
                }
                $out[] = '<li>'.self::inline($lm[1]).'</li>';
                continue;
            }
            if ($list !== null) {
                $out[] = "</{$list}>";
                $list = null;
            }
            $out[] = $line === '' ? '' : self::inline($line);
        }
        if ($list !== null) {
            $out[] = "</{$list}>";
        }
        // paragraphs: blank lines separate, single newlines become <br>
        $html = '';
        $para = [];
        $flush = function () use (&$para, &$html) {
            if ($para !== []) { $html .= '<p>'.implode('<br>', $para).'</p>'; $para = []; }
        };
        foreach ($out as $piece) {
            if ($piece === '') { $flush(); continue; }
            if (preg_match('/^<\/?(ul|ol)>$|^<li>|^\x00B\d+\x00$/', $piece)) { $flush(); $html .= $piece; continue; }
            $para[] = $piece;
        }
        $flush();
        return (string) preg_replace_callback('/\x00B(\d+)\x00/', fn ($m) => $blocks[(int) $m[1]], $html);
    }

    /** Inline rules on ONE escaped line. */
    private static function inline(string $line): string
    {
        $s = self::esc($line);
        // inline code first: its contents are literal
        $codes = [];
        $s = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$codes) {
            $codes[] = '<code>'.$m[1].'</code>';
            return "\x00C".(count($codes) - 1)."\x00";
        }, $s);
        // [text](url) - only safe schemes; the text was escaped above
        $s = preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/i', fn ($m) => self::link($m[2], $m[1]), $s);
        // bare URLs (not already inside an href)
        $s = preg_replace_callback('/(?<![="\'>\/])\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)"\']/i', fn ($m) => self::link($m[0], $m[0]), $s);
        $s = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?!\w)/', '<em>$1</em>', $s);
        $s = preg_replace('/(?<![\w_])_(?=\S)([^_\n]+?)(?<=\S)_(?!\w)/', '<em>$1</em>', $s);
        return (string) preg_replace_callback('/\x00C(\d+)\x00/', fn ($m) => $codes[(int) $m[1]], $s);
    }

    private static function link(string $url, string $label): string
    {
        // the URL arrives escaped (&amp;) which is exactly what an href wants
        return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.$label.'</a>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
