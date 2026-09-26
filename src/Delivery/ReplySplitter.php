<?php

namespace Banimark\Delivery;

/**
 * A long answer, delivered the way a person would type it: two, three or four
 * short messages, each after a pause that matches its length, instead of one
 * wall of text. The desk decides the split ONCE, server-side, and every
 * channel plays it back - the website widget, the chat link and the app
 * today, a messaging channel tomorrow - so "how a reply arrives" never lives
 * in a client. Readable on purpose: a channel adapter builds on it.
 *
 * The cut is content-aware: paragraphs first, then sentences; a code block, a
 * list (with the line that introduces it) and a link are never split; a short
 * answer is never split at all; and past MAX_PARTS the rest travels together.
 */
final class ReplySplitter
{
    public const DEFAULT_CHARS = 320;
    public const MIN_CHARS = 120;
    public const MAX_CHARS = 1200;
    public const MAX_PARTS = 4;

    /** @return list<array{text: string, delay_ms: int}> one entry = one message; the first has no delay */
    public static function split(string $text, int $maxChars = self::DEFAULT_CHARS, int $maxParts = self::MAX_PARTS): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $maxChars = max(self::MIN_CHARS, min(self::MAX_CHARS, $maxChars));
        $maxParts = max(1, $maxParts);
        if ($text === '' || mb_strlen($text) <= (int) ($maxChars * 1.15) || $maxParts === 1) {
            return $text === '' ? [] : [['text' => $text, 'delay_ms' => 0]];
        }

        $parts = [];
        $current = '';
        $flush = function () use (&$parts, &$current): void {
            if (trim($current) !== '') {
                $parts[] = trim($current);
            }
            $current = '';
        };
        foreach (self::blocks($text) as $block) {
            $fits = $current === '' ? mb_strlen($block['text']) <= $maxChars : mb_strlen($current) + 2 + mb_strlen($block['text']) <= $maxChars;
            if ($fits) {
                $current = $current === '' ? $block['text'] : $current."\n\n".$block['text'];
                continue;
            }
            $flush();
            if ($block['atomic'] || mb_strlen($block['text']) <= $maxChars) {
                $current = $block['text'];
                continue;
            }
            // a long paragraph: sentences, packed up to the limit
            foreach (self::sentences($block['text'], $maxChars) as $chunk) {
                if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($chunk) <= $maxChars) {
                    $current .= ' '.$chunk;
                } else {
                    $flush();
                    $current = $chunk;
                }
            }
        }
        $flush();

        if (count($parts) > $maxParts) {
            $tail = array_splice($parts, $maxParts - 1);
            $parts[] = implode("\n\n", $tail);
        }
        if (count($parts) === 1) {
            return [['text' => $parts[0], 'delay_ms' => 0]];
        }
        $out = [];
        foreach ($parts as $i => $p) {
            $out[] = ['text' => $p, 'delay_ms' => $i === 0 ? 0 : self::delayFor($p)];
        }
        return $out;
    }

    /** The pause before a message: a person typing it, capped so nobody waits long. */
    public static function delayFor(string $text): int
    {
        return (int) min(3200, 600 + mb_strlen($text) * 22);
    }

    /**
     * Paragraph blocks. A fenced code block, and a list together with the
     * line that introduces it (one ending in ":"), are one ATOMIC block.
     *
     * @return list<array{text: string, atomic: bool}>
     */
    private static function blocks(string $text): array
    {
        $out = [];
        $buf = [];
        $inCode = false;
        $inList = false;
        $emit = function (bool $atomic) use (&$out, &$buf): void {
            $t = trim(implode("\n", $buf));
            if ($t !== '') {
                $out[] = ['text' => $t, 'atomic' => $atomic];
            }
            $buf = [];
        };
        foreach (explode("\n", $text) as $line) {
            $isFence = preg_match('/^\s*```/', $line) === 1;
            if ($inCode) {
                $buf[] = $line;
                if ($isFence) { $inCode = false; $emit(true); }
                continue;
            }
            if ($isFence) {
                $emit($inList);
                $inList = false;
                $buf[] = $line;
                $inCode = true;
                continue;
            }
            $isItem = preg_match('/^\s*(?:[-*•]|\d+[.)])\s+/', $line) === 1;
            if (trim($line) === '') {
                if ($inList) { continue; } // a blank line inside a list does not end it
                $emit(false);
                continue;
            }
            if ($isItem) {
                if (!$inList) {
                    // the line that introduces the list stays with it
                    $intro = $buf !== [] && str_ends_with(rtrim((string) end($buf)), ':');
                    if (!$intro) { $emit(false); }
                    $inList = true;
                }
                $buf[] = $line;
                continue;
            }
            if ($inList) {
                $emit(true);
                $inList = false;
            }
            $buf[] = $line;
        }
        $emit($inCode || $inList);
        return $out;
    }

    /** @return list<string> sentence groups of at most $max chars (a single longer sentence stays whole) */
    private static function sentences(string $paragraph, int $max): array
    {
        $pieces = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}0-9"\'(\[])/u', trim($paragraph)) ?: [$paragraph];
        $out = [];
        $cur = '';
        foreach ($pieces as $s) {
            $s = trim($s);
            if ($s === '') { continue; }
            if ($cur !== '' && mb_strlen($cur) + 1 + mb_strlen($s) > $max) {
                $out[] = $cur;
                $cur = $s;
            } else {
                $cur = $cur === '' ? $s : $cur.' '.$s;
            }
        }
        if ($cur !== '') { $out[] = $cur; }
        return $out;
    }
}
