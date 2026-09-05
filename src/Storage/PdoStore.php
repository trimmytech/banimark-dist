<?php

namespace Banimark\Storage;

use Banimark\Contracts\StateStore;
use Banimark\Engine\ConversationState;

/**
 * Database persistence over plain PDO - works with MySQL/MariaDB/SQLite, no
 * framework required (the Laravel bridge hands it DB::connection()->getPdo()).
 * One class is deliberately both the engine's StateStore AND the admin
 * panel's conversation directory (inbox listing, human takeover, polling),
 * because they are views over the same two tables.
 *
 * Tables (see createSchema): {prefix}conversations, {prefix}messages.
 * Modes: 'ai' (engine answers) -> 'agent' (human took over / escalated)
 * -> 'closed'.
 */
class PdoStore implements StateStore
{
    public function __construct(
        private \PDO $pdo,
        private string $prefix = 'banimark_',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** @deprecated delegates to Storage\Schema - the single schema source. */
    public static function createSchema(\PDO $pdo, string $prefix = 'banimark_'): void
    {
        Schema::create($pdo, $prefix);
    }

    /* ---------------- StateStore (the engine's view) ---------------- */

    public function load(string $sessionId): ?array
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            return null;
        }
        // only what the MODEL saw: agent replies and staff-only system notes stay out of the prompt
        $rows = $this->query(
            "SELECT role, content, payload FROM {$this->prefix}messages WHERE conversation_id = ? AND role IN ('user', 'assistant', 'tool') ORDER BY id",
            [$conv['id']],
        );
        $stateRows = [];
        foreach ($rows as $r) {
            $payload = $r['payload'] ? (json_decode($r['payload'], true) ?: []) : [];
            $stateRows[] = array_merge([
                'role' => $r['role'] === 'agent' ? 'assistant' : $r['role'],
                'content' => $r['content'],
            ], $payload);
        }

        return [
            'state' => ConversationState::fromArray($stateRows),
            'identity_hash' => (string) $conv['identity_hash'],
        ];
    }

    public function save(string $sessionId, ConversationState $state, string $identityHash): void
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            $this->exec(
                "INSERT INTO {$this->prefix}conversations (session_id, identity_hash, mode, last_message_at, created_at) VALUES (?, ?, 'ai', ?, ?)",
                [$sessionId, $identityHash, time(), time()],
            );
            $conv = $this->conversation($sessionId);
        }
        // replace-all write: simple, correct, and cheap at chat sizes.
        // Agent messages are panel-authored rows, not engine state - keep them.
        $this->exec("DELETE FROM {$this->prefix}messages WHERE conversation_id = ? AND role != 'agent'", [$conv['id']]);
        foreach ($state->toArray() as $row) {
            $payload = $row;
            unset($payload['role'], $payload['content']);
            $payload = array_filter($payload, fn ($v) => $v !== null && $v !== [] && $v !== '');
            $this->exec(
                "INSERT INTO {$this->prefix}messages (conversation_id, role, content, payload, created_at) VALUES (?, ?, ?, ?, ?)",
                [$conv['id'], $row['role'], (string) $row['content'], $payload === [] ? null : json_encode($payload), time()],
            );
        }
        $this->exec("UPDATE {$this->prefix}conversations SET last_message_at = ? WHERE id = ?", [time(), $conv['id']]);
    }

    /* ---------------- directory (the panel's + endpoint's view) ---------------- */

    public function mode(string $sessionId): string
    {
        $conv = $this->conversation($sessionId);
        return $conv ? (string) $conv['mode'] : 'ai';
    }

    public function setMode(string $sessionId, string $mode, string $visitorLabel = null): void
    {
        $set = 'mode = ?';
        $args = [$mode];
        if ($mode === 'agent' && $this->mode($sessionId) !== 'agent') {
            $set .= ', escalated_at = ?'; // a fresh handover, not a re-save
            $args[] = time();
        }
        if ($visitorLabel !== null) {
            $set .= ', visitor_label = ?';
            $args[] = $visitorLabel;
        }
        $args[] = $sessionId;
        $this->exec("UPDATE {$this->prefix}conversations SET {$set} WHERE session_id = ?", $args);
    }

    /** A visitor message stored while a human owns the conversation. */
    public function appendVisitorMessage(string $sessionId, string $text, string $identityHash): void
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            $this->exec(
                "INSERT INTO {$this->prefix}conversations (session_id, identity_hash, mode, last_message_at, created_at) VALUES (?, ?, 'agent', ?, ?)",
                [$sessionId, $identityHash, time(), time()],
            );
            $conv = $this->conversation($sessionId);
        }
        $this->exec(
            "INSERT INTO {$this->prefix}messages (conversation_id, role, content, payload, created_at) VALUES (?, 'user', ?, NULL, ?)",
            [$conv['id'], $text, time()],
        );
        $this->exec("UPDATE {$this->prefix}conversations SET last_message_at = ? WHERE id = ?", [time(), $conv['id']]);
    }

    /** A human agent's reply from the panel. */
    public function appendAgentMessage(string $sessionId, string $text): void
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            return;
        }
        $this->exec(
            "INSERT INTO {$this->prefix}messages (conversation_id, role, content, payload, created_at) VALUES (?, 'agent', ?, NULL, ?)",
            [$conv['id'], $text, time()],
        );
        $this->exec("UPDATE {$this->prefix}conversations SET mode = 'agent', last_message_at = ? WHERE id = ?", [time(), $conv['id']]);
    }

    /** Widget polling: agent messages with id > $afterId. */
    public function agentMessagesSince(string $sessionId, int $afterId): array
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            return [];
        }
        return $this->query(
            "SELECT id, content, created_at FROM {$this->prefix}messages WHERE conversation_id = ? AND role = 'agent' AND id > ? ORDER BY id",
            [$conv['id'], $afterId],
        );
    }

    /**
     * The conversation a known identity is in the middle of, so a signed-in
     * visitor who cleared storage, changed device, or hit an error mid-chat
     * lands back in the same thread instead of opening a new one. Anonymous
     * visitors have no identity to match - they rely on the widget's storage.
     */
    public function latestSessionFor(string $identityHash): ?string
    {
        if ($identityHash === '' || $identityHash === 'anon') {
            return null;
        }
        $rows = $this->query(
            "SELECT session_id FROM {$this->prefix}conversations WHERE identity_hash = ? AND mode <> 'closed' ORDER BY last_message_at DESC, id DESC LIMIT 1",
            [$identityHash],
        );
        return $rows[0]['session_id'] ?? null;
    }

    /** A staff-only note in the thread (e.g. the real provider error behind an escalation). */
    public function appendSystemNote(string $sessionId, string $text): void
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            return;
        }
        $this->exec(
            "INSERT INTO {$this->prefix}messages (conversation_id, role, content, payload, created_at) VALUES (?, 'system', ?, NULL, ?)",
            [$conv['id'], mb_substr($text, 0, 2000), time()],
        );
    }

    /** Typing indicators: a fresh timestamp means "typing right now" for a few seconds. */
    public const TYPING_WINDOW = 6;

    public function markTyping(string $sessionId, string $who, ?int $now = null): void
    {
        $col = $who === 'agent' ? 'agent_typing_at' : 'visitor_typing_at';
        $this->exec("UPDATE {$this->prefix}conversations SET {$col} = ? WHERE session_id = ?", [$now ?? time(), $sessionId]);
    }

    /** Staff live view: every row (all roles) with id > $afterId. */
    public function messagesSince(string $sessionId, int $afterId): array
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            return [];
        }
        return $this->query(
            "SELECT id, role, content, payload, created_at FROM {$this->prefix}messages WHERE conversation_id = ? AND id > ? ORDER BY id",
            [$conv['id'], $afterId],
        );
    }

    /**
     * What happened since a staff browser last asked: new visitor messages and
     * fresh handovers. Feeds the panel's sound + badge. Bounded lists, newest
     * first; the caller stores 'now' and passes it back next time.
     */
    public function staffEvents(int $since, ?int $now = null): array
    {
        $now = $now ?? time();
        $since = max(0, min($since, $now));
        $msgs = $this->query(
            "SELECT c.session_id, c.visitor_label, c.mode, m.content, m.created_at
             FROM {$this->prefix}messages m JOIN {$this->prefix}conversations c ON c.id = m.conversation_id
             WHERE m.role = 'user' AND m.created_at > ? AND m.created_at <= ? ORDER BY m.id DESC LIMIT 10",
            [$since, $now],
        );
        $esc = $this->query(
            "SELECT session_id, visitor_label, escalated_at FROM {$this->prefix}conversations
             WHERE escalated_at > ? AND escalated_at <= ? ORDER BY escalated_at DESC LIMIT 10",
            [$since, $now],
        );
        $items = [];
        foreach ($esc as $r) {
            $items[] = ['kind' => 'escalation', 'session_id' => (string) $r['session_id'], 'label' => (string) ($r['visitor_label'] ?: 'Visitor'),
                'text' => 'needs a human', 'at' => (int) $r['escalated_at']];
        }
        foreach ($msgs as $r) {
            $items[] = ['kind' => 'message', 'session_id' => (string) $r['session_id'], 'label' => (string) ($r['visitor_label'] ?: 'Visitor'),
                'text' => mb_substr((string) $r['content'], 0, 120), 'at' => (int) $r['created_at'], 'mode' => (string) $r['mode']];
        }
        usort($items, fn ($a, $b) => $b['at'] <=> $a['at']);
        $waiting = (int) ($this->query("SELECT COUNT(*) AS n FROM {$this->prefix}conversations WHERE mode = 'agent'", [])[0]['n'] ?? 0);
        return ['now' => $now, 'messages' => count($msgs), 'escalations' => count($esc), 'waiting' => $waiting, 'items' => $items];
    }

    /* ---------------- visitor identity & presence ---------------- */

    /** Guest details supplied at widget init or by the pre-chat form. */
    public function setVisitor(string $sessionId, string $name, string $email): void
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            return;
        }
        $set = [];
        $args = [];
        if (trim($name) !== '') {
            $set[] = 'visitor_label = ?';
            $args[] = mb_substr(trim($name), 0, 190);
        }
        if (trim($email) !== '') {
            $set[] = 'visitor_email = ?';
            $args[] = mb_substr(trim($email), 0, 190);
        }
        if ($set === []) {
            return;
        }
        $args[] = $conv['id'];
        $this->exec("UPDATE {$this->prefix}conversations SET ".implode(', ', $set).' WHERE id = ?', $args);
    }

    /** The visitor is on the page right now - refreshed by the widget's poll. */
    public function touch(string $sessionId, ?int $now = null): void
    {
        $this->exec("UPDATE {$this->prefix}conversations SET last_seen_at = ? WHERE session_id = ?", [$now ?? time(), $sessionId]);
    }

    /** @return array|null presence + contact details, for the follow-up decision */
    public function presence(string $sessionId): ?array
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            return null;
        }
        return [
            'session_id' => $sessionId,
            'mode' => (string) $conv['mode'],
            'visitor_label' => (string) ($conv['visitor_label'] ?? ''),
            'visitor_email' => (string) ($conv['visitor_email'] ?? ''),
            'last_seen_at' => (int) ($conv['last_seen_at'] ?? 0),
            'followup_at' => (int) ($conv['followup_at'] ?? 0),
            'last_message_at' => (int) ($conv['last_message_at'] ?? 0),
            'visitor_typing' => (int) ($conv['visitor_typing_at'] ?? 0) > time() - self::TYPING_WINDOW,
            'agent_typing' => (int) ($conv['agent_typing_at'] ?? 0) > time() - self::TYPING_WINDOW,
        ];
    }

    public function markFollowedUp(string $sessionId, ?int $now = null): void
    {
        $this->exec("UPDATE {$this->prefix}conversations SET followup_at = ? WHERE session_id = ?", [$now ?? time(), $sessionId]);
    }

    /**
     * What the WIDGET may replay when a visitor comes back: their own messages
     * and the replies they already saw. Tool rows and tool-call payloads stay
     * server-side - the visitor never sees the desk's internals.
     */
    public function visitorTranscript(string $sessionId, int $limit = 50): array
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            return [];
        }
        $rows = $this->query(
            "SELECT id, role, content FROM {$this->prefix}messages
             WHERE conversation_id = ? AND role IN ('user', 'assistant', 'agent') AND content <> ''
             ORDER BY id DESC LIMIT ".max(1, $limit),
            [$conv['id']],
        );
        return array_reverse($rows);
    }

    /** Inbox listing, newest activity first. */
    public function listConversations(int $limit = 50, ?string $mode = null): array
    {
        $sql = "SELECT c.*, (SELECT COUNT(*) FROM {$this->prefix}messages m WHERE m.conversation_id = c.id) AS message_count,
                (SELECT content FROM {$this->prefix}messages m2 WHERE m2.conversation_id = c.id ORDER BY m2.id DESC LIMIT 1) AS last_message
                FROM {$this->prefix}conversations c";
        $args = [];
        if ($mode !== null) {
            $sql .= ' WHERE c.mode = ?';
            $args[] = $mode;
        }
        $sql .= ' ORDER BY c.last_message_at DESC LIMIT '.max(1, $limit);
        return $this->query($sql, $args);
    }

    /** Full transcript for the panel (agent rows included, tool payloads too). */
    public function transcript(string $sessionId): array
    {
        $conv = $this->conversation($sessionId);
        if (!$conv) {
            return [];
        }
        return $this->query(
            "SELECT id, role, content, payload, created_at FROM {$this->prefix}messages WHERE conversation_id = ? ORDER BY id",
            [$conv['id']],
        );
    }

    /* ---------------- plumbing ---------------- */

    private function conversation(string $sessionId): ?array
    {
        $rows = $this->query("SELECT * FROM {$this->prefix}conversations WHERE session_id = ?", [$sessionId]);
        return $rows[0] ?? null;
    }

    private function query(string $sql, array $args): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function exec(string $sql, array $args): void
    {
        $this->pdo->prepare($sql)->execute($args);
    }
}
