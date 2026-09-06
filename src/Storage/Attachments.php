<?php

namespace Banimark\Storage;

/**
 * The record of files shared in conversations. The bytes live on a FileStore;
 * this is the index that ties them to a message and hands out the
 * capability token the URL carries.
 */
final class Attachments
{
    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
    }

    /**
     * @return array the stored row (with its token), ready to hand back to the uploader
     */
    public function create(string $sessionId, string $disk, string $path, string $name, string $mime, int $size, string $source): array
    {
        $conv = $this->conversationId($sessionId);
        $token = bin2hex(random_bytes(16));
        $st = $this->pdo->prepare("INSERT INTO {$this->prefix}attachments (conversation_id, sent, token, disk, path, name, mime, size, source, created_at) VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([$conv, $token, $disk, $path, $name, $mime, $size, $source === 'agent' ? 'agent' : 'visitor', time()]);
        return $this->find((int) $this->pdo->lastInsertId()) ?? [];
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM {$this->prefix}attachments WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = $this->pdo->prepare("SELECT * FROM {$this->prefix}attachments WHERE token = ?");
        $st->execute([$token]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * The files a message's markers point at, in the order they were written.
     * @param string[] $tokens
     * @return array<int, array>
     */
    public function byTokens(array $tokens): array
    {
        $tokens = array_values(array_filter($tokens, fn ($t) => preg_match('/^[a-f0-9]{32}$/', (string) $t)));
        if ($tokens === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($tokens), '?'));
        $st = $this->pdo->prepare("SELECT * FROM {$this->prefix}attachments WHERE token IN ({$in})");
        $st->execute($tokens);
        $byToken = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $byToken[(string) $row['token']] = $row;
        }
        $out = [];
        foreach ($tokens as $t) {   // preserve the order of the markers in the text
            if (isset($byToken[$t])) {
                $out[] = $byToken[$t];
            }
        }
        return $out;
    }

    /** Mark files as actually sent, so the cleanup leaves them alone. */
    public function markSent(array $tokens, string $sessionId = ''): void
    {
        $tokens = array_values(array_filter($tokens, fn ($t) => preg_match('/^[a-f0-9]{32}$/', (string) $t)));
        if ($tokens === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($tokens), '?'));
        $sql = "UPDATE {$this->prefix}attachments SET sent = 1 WHERE token IN ({$in})";
        $args = $tokens;
        if ($sessionId !== '') { // a token from another conversation is not yours to send
            $sql .= ' AND conversation_id = ?';
            $args[] = $this->conversationId($sessionId);
        }
        $this->pdo->prepare($sql)->execute($args);
    }

    /** @param int[] $ids @return array<int, array> rows this conversation may still send */
    public function pending(array $ids, string $sessionId): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT * FROM {$this->prefix}attachments WHERE id IN ({$in}) AND conversation_id = ? AND sent = 0 ORDER BY id");
        $st->execute(array_merge($ids, [$this->conversationId($sessionId)]));
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Uploaded but never sent (the visitor changed their mind) - swept up later. */
    public function pruneOrphans(int $olderThanSeconds = 86400): array
    {
        $cutoff = time() - $olderThanSeconds;
        $st = $this->pdo->prepare("SELECT * FROM {$this->prefix}attachments WHERE sent = 0 AND created_at < ?");
        $st->execute([$cutoff]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($rows !== []) {
            $this->pdo->prepare("DELETE FROM {$this->prefix}attachments WHERE sent = 0 AND created_at < ?")->execute([$cutoff]);
        }
        return $rows;
    }

    public function countForConversation(string $sessionId): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->prefix}attachments WHERE conversation_id = ?");
        $st->execute([$this->conversationId($sessionId)]);
        return (int) $st->fetchColumn();
    }

    private function conversationId(string $sessionId): int
    {
        $st = $this->pdo->prepare("SELECT id FROM {$this->prefix}conversations WHERE session_id = ?");
        $st->execute([$sessionId]);
        return (int) ($st->fetchColumn() ?: 0);
    }
}
