<?php

namespace Banimark\Ui;

/**
 * What a conversation NEEDS, at a glance. One place decides the state, the
 * label, the colour and the urgency, so the inbox, the tab counts and any
 * future alert all agree. The rule that matters: a visitor whose last message
 * nobody has answered is "waiting", and the longer they wait the louder the
 * row gets.
 */
final class Triage
{
    /** A wait this long stops being amber and starts being red. */
    public const URGENT_AFTER = 600; // 10 minutes
    /** A visitor seen this recently is still in the chat. */
    public const ONLINE_WITHIN = 45;

    /**
     * @param array $row a row from PdoStore::listConversations()
     * @return array{key:string, label:string, tone:string, title:string, waiting:int}
     */
    public static function state(array $row, ?int $now = null): array
    {
        $now = $now ?? time();
        $mode = (string) ($row['mode'] ?? 'ai');
        $since = (int) ($row['waiting_since'] ?? 0);
        $waiting = $since > 0 ? max(0, $now - $since) : 0;

        if ($mode === 'closed') {
            return ['key' => 'closed', 'label' => 'Closed', 'tone' => 'calm', 'title' => 'This conversation is finished.', 'waiting' => 0];
        }
        if ($since > 0) {
            // nobody has answered the visitor's last message
            $urgent = $waiting >= self::URGENT_AFTER;
            return [
                'key' => $mode === 'agent' ? 'waiting' : 'unanswered',
                'label' => 'Waiting '.self::brief($waiting),
                'tone' => $urgent ? 'urgent' : 'warn',
                'title' => $mode === 'agent'
                    ? 'A person needs to reply - the visitor has been waiting '.self::brief($waiting).'.'
                    : 'The assistant did not answer this message. It has been '.self::brief($waiting).'.',
                'waiting' => $waiting,
            ];
        }
        if ($mode === 'agent') {
            return ['key' => 'with_person', 'label' => 'With a person', 'tone' => 'info', 'title' => 'A person has taken this over and replied. The AI stays quiet.', 'waiting' => 0];
        }
        return ['key' => 'ai', 'label' => 'AI is answering', 'tone' => 'ai', 'title' => 'The assistant is handling this on its own.', 'waiting' => 0];
    }

    /** The small flags beside the name: who they are, what came with it, what went wrong. */
    public static function flags(array $row, ?int $now = null): array
    {
        $now = $now ?? time();
        $flags = [];
        if ((string) ($row['identity_hash'] ?? 'anon') !== 'anon') {
            $flags[] = ['icon' => 'key', 'label' => 'Signed in', 'title' => 'This visitor is signed in to your app, so lookups can be scoped to their account.'];
        }
        if ((int) ($row['file_count'] ?? 0) > 0) {
            $flags[] = ['icon' => 'files', 'label' => (string) (int) $row['file_count'], 'title' => $row['file_count'].' file(s) shared in this chat'];
        }
        if (!empty($row['has_note'])) {
            $flags[] = ['icon' => 'escalation', 'label' => '', 'title' => 'Something went wrong here (a provider or tool problem). Open it to see the note.', 'tone' => 'urgent'];
        }
        return $flags;
    }

    public static function isOnline(array $row, ?int $now = null): bool
    {
        return (int) ($row['last_seen_at'] ?? 0) > (($now ?? time()) - self::ONLINE_WITHIN);
    }

    public static function isUnread(array $row): bool
    {
        return (int) ($row['last_message_at'] ?? 0) > (int) ($row['staff_seen_at'] ?? 0) && (string) ($row['mode'] ?? '') !== 'closed';
    }

    /** "45s", "12m", "3h", "2d" - short enough to sit inside a pill. */
    public static function brief(int $seconds): string
    {
        if ($seconds < 60) {
            return max(0, $seconds).'s';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600).'h';
        }
        return floor($seconds / 86400).'d';
    }
}
