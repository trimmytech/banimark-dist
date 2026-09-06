<?php

namespace Banimark\Storage;

use Banimark\Files\FileStore;

/**
 * Deleting chat history - by age (the owner's retention policy), one
 * conversation, everything from one visitor, or everything. Attachments go
 * with their conversations: the rows AND the stored files, so "delete" means
 * delete. Runs the same way in both runtimes.
 */
final class Retention
{
    public function __construct(
        private \PDO $pdo,
        private ?FileStore $files = null,
        private string $prefix = 'banimark_',
    ) {
    }

    /** 0 = keep forever. */
    public static function days(array $settings): int
    {
        return max(0, min(3650, (int) ($settings['retention_days'] ?? 0)));
    }

    /**
     * Delete conversations whose last activity is older than $days.
     * Returns how many conversations went. Idle open threads count too: a
     * visitor who left a month ago is not coming back to that thread.
     */
    public function prune(int $days, ?int $now = null): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = ($now ?? time()) - $days * 86400;
        $st = $this->pdo->prepare("SELECT id FROM {$this->prefix}conversations WHERE last_message_at > 0 AND last_message_at < ?");
        $st->execute([$cutoff]);
        $ids = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        return $this->deleteIds($ids);
    }

    public function deleteConversation(string $sessionId): int
    {
        $st = $this->pdo->prepare("SELECT id FROM {$this->prefix}conversations WHERE session_id = ?");
        $st->execute([$sessionId]);
        $id = (int) $st->fetchColumn();
        return $id > 0 ? $this->deleteIds([$id]) : 0;
    }

    /** Everything a visitor ever said, across all their threads. */
    public function deleteVisitor(string $identityHash): int
    {
        if ($identityHash === '' || $identityHash === 'anon') {
            return 0; // "anon" is every anonymous visitor at once - use deleteAll for that
        }
        $st = $this->pdo->prepare("SELECT id FROM {$this->prefix}conversations WHERE identity_hash = ?");
        $st->execute([$identityHash]);
        return $this->deleteIds(array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function deleteAll(): int
    {
        $ids = array_map('intval', $this->pdo->query("SELECT id FROM {$this->prefix}conversations")->fetchAll(\PDO::FETCH_COLUMN));
        return $this->deleteIds($ids);
    }

    /** @param int[] $ids */
    private function deleteIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        foreach (array_chunk($ids, 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            // the files first, so a crash mid-way leaves rows pointing at
            // nothing rather than orphaned bytes nobody can find again
            $st = $this->pdo->prepare("SELECT path FROM {$this->prefix}attachments WHERE conversation_id IN ({$in})");
            $st->execute($chunk);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $path) {
                try { $this->files?->delete((string) $path); } catch (\Throwable $e) { /* best effort */ }
            }
            $this->pdo->prepare("DELETE FROM {$this->prefix}attachments WHERE conversation_id IN ({$in})")->execute($chunk);
            $this->pdo->prepare("DELETE FROM {$this->prefix}messages WHERE conversation_id IN ({$in})")->execute($chunk);
            $this->pdo->prepare("DELETE FROM {$this->prefix}conversations WHERE id IN ({$in})")->execute($chunk);
        }
        return count($ids);
    }

    /** Daily housekeeping hook for both runtimes: throttled, never throws. */
    public static function daily(\PDO $pdo, array $settings, callable $set, ?FileStore $files = null, string $prefix = 'banimark_', ?int $now = null): int
    {
        $now = $now ?? time();
        if ($now - (int) ($settings['retention_last_run'] ?? 0) < 86400) {
            return 0;
        }
        $set('retention_last_run', (string) $now);
        try {
            return (new self($pdo, $files, $prefix))->prune(self::days($settings), $now);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
