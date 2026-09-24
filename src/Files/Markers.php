<?php

namespace Banimark\Files;

/**
 * How a file rides along with a message.
 *
 * The engine rewrites a conversation's rows on every turn (a replace-all
 * write), so a database link from an attachment to a message id would not
 * survive the next reply. The reference therefore lives IN the message text as
 * a marker, which round-trips through state, history and the model untouched:
 *
 *     here is my receipt
 *     [attached: receipt.pdf](banimark:9f3a…)
 *
 * Everything that shows a message to a human strips the markers and renders
 * real attachments; the model simply reads a line naming what was attached,
 * which is useful context it would otherwise not have.
 */
final class Markers
{
    private const RE = '/\[attached: ([^\]]*)\]\(banimark:([a-f0-9]{32})\)/';

    /** @param array<int, array> $attachments rows with name + token */
    public static function append(string $text, array $attachments): string
    {
        $lines = [];
        foreach ($attachments as $a) {
            $name = str_replace([']', '['], '', (string) ($a['name'] ?? 'file'));
            $lines[] = '[attached: '.$name.'](banimark:'.$a['token'].')';
        }
        if ($lines === []) {
            return $text;
        }
        return trim($text) === '' ? implode("\n", $lines) : trim($text)."\n".implode("\n", $lines);
    }

    /** @return array{text: string, tokens: string[]} the human text, and what was attached */
    public static function parse(string $text): array
    {
        $tokens = [];
        if (preg_match_all(self::RE, $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $tokens[] = $hit[2];
            }
        }
        return ['text' => trim((string) preg_replace(self::RE, '', $text)), 'tokens' => $tokens];
    }

    public static function has(string $text): bool
    {
        return (bool) preg_match(self::RE, $text);
    }
}
