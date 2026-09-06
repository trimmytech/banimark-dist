<?php

namespace Banimark\Http;

/**
 * Fixed-window counters in the database - portable (MySQL/MariaDB/SQLite),
 * needs no Redis, and survives across PHP-FPM workers. Good enough to stop a
 * script flooding the chat and to cap a day's AI spend; not a substitute for
 * a CDN in front of a real DDoS, and it does not pretend to be.
 */
final class RateLimiter
{
    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
    }

    /**
     * Count one hit against $key. Returns how many hits the window has seen
     * INCLUDING this one; the caller compares to its limit. $window in seconds.
     */
    public function hit(string $key, int $window, ?int $now = null): int
    {
        $now = $now ?? time();
        $bucket = intdiv($now, max(1, $window));
        $t = "{$this->prefix}throttle";
        try {
            $st = $this->pdo->prepare("UPDATE {$t} SET hits = hits + 1 WHERE k = ? AND bucket = ?");
            $st->execute([$key, $bucket]);
            if ($st->rowCount() === 0) {
                $this->pdo->prepare("INSERT INTO {$t} (k, bucket, hits, expires_at) VALUES (?, ?, 1, ?)")->execute([$key, $bucket, $now + $window * 2]);
            }
            $st = $this->pdo->prepare("SELECT hits FROM {$t} WHERE k = ? AND bucket = ?");
            $st->execute([$key, $bucket]);
            $hits = (int) $st->fetchColumn();
            if (mt_rand(1, 50) === 1) {
                // tidy old windows now and then; a DELETE that races is harmless
                $this->pdo->prepare("DELETE FROM {$t} WHERE expires_at < ?")->execute([$now]);
            }
            return $hits;
        } catch (\Throwable $e) {
            // a broken throttle table must not take the chat down: fail OPEN
            return 1;
        }
    }

    /** Peek without counting. */
    public function count(string $key, int $window, ?int $now = null): int
    {
        $now = $now ?? time();
        try {
            $st = $this->pdo->prepare("SELECT hits FROM {$this->prefix}throttle WHERE k = ? AND bucket = ?");
            $st->execute([$key, intdiv($now, max(1, $window))]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
