<?php

namespace Banimark\Storage;

/**
 * One rendering of a transcript row for STAFF eyes (page and live JSON alike),
 * so the server-rendered page and the polled updates never drift apart. Tool
 * traffic collapses to a one-line note; text rows pass through untouched.
 */
final class TranscriptView
{
    /** @return array{id:int, role:string, text:string, at:int, files:array} */
    public static function row(array $m, ?Attachments $attachments = null): array
    {
        $payload = !empty($m['payload']) ? (json_decode((string) $m['payload'], true) ?: []) : [];
        $role = (string) $m['role'];
        $text = (string) $m['content'];
        if ($role === 'system') {
            return ['id' => (int) $m['id'], 'role' => 'system', 'text' => $text, 'at' => (int) ($m['created_at'] ?? 0)];
        }
        if ($role === 'tool') {
            $text = ($payload['for_call']['name'] ?? 'tool').' → '.mb_substr(json_encode($payload['tool_result'] ?? []), 0, 200);
        } elseif ($role === 'assistant' && !empty($payload['tool_calls'])) {
            $role = 'tool';
            $text = 'AI called: '.implode(', ', array_column($payload['tool_calls'], 'name'));
        }
        // files travel as markers in the text (ids churn on every turn) - show them as files
        $parsed = \Banimark\Files\Markers::parse($text);
        $files = [];
        if ($parsed['tokens'] !== [] && $attachments !== null) {
            $files = array_map(fn ($a) => [
                'token' => (string) $a['token'], 'name' => (string) $a['name'], 'mime' => (string) $a['mime'],
                'size' => (int) $a['size'], 'is_image' => \Banimark\Files\UploadPolicy::isImage((string) $a['mime']),
            ], $attachments->byTokens($parsed['tokens']));
        }
        return ['id' => (int) $m['id'], 'role' => $role, 'text' => $parsed['text'], 'at' => (int) ($m['created_at'] ?? 0), 'files' => $files,
            // who replied, for agent rows (team page + "Ada · 14:02" on the bubble)
            'by' => $role === 'agent' ? (string) ($m['agent_name'] ?? '') : ''];
    }

    /** @return array<int, array{id:int, role:string, text:string, at:int, files:array}> */
    public static function rows(array $rows, ?Attachments $attachments = null): array
    {
        $out = array_map(fn ($r) => self::row($r, $attachments), $rows);
        // a message that is only a file has no text, but must still be shown
        return array_values(array_filter($out, fn ($r) => $r['text'] !== '' || $r['files'] !== []));
    }
}
