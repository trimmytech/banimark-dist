<?php

namespace Banimark\Storage;

/**
 * Desk analytics for the panel dashboard. Portable across MySQL/MariaDB and
 * SQLite: day buckets come from FLOOR(created_at/86400) on the integer unix
 * timestamps rather than any vendor date function, and anything needing JSON
 * (tool names live inside message payloads) is aggregated in PHP over a
 * bounded window instead of in SQL.
 */
class Analytics
{
    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
    }

    /** Everything the dashboard needs, in one call. */
    public function overview(?int $now = null): array
    {
        $now = $now ?? time();
        $dayStart = $now - ($now % 86400);
        $week = $now - 7 * 86400;
        $prevWeek = $now - 14 * 86400;

        $conversations = $this->count("SELECT COUNT(*) FROM {$this->prefix}conversations");
        $messages = $this->count("SELECT COUNT(*) FROM {$this->prefix}messages");
        $visitorMsgs = $this->count("SELECT COUNT(*) FROM {$this->prefix}messages WHERE role = 'user'");

        $modes = ['ai' => 0, 'agent' => 0, 'closed' => 0];
        foreach ($this->rows("SELECT mode, COUNT(*) AS n FROM {$this->prefix}conversations GROUP BY mode") as $r) {
            if (isset($modes[$r['mode']])) {
                $modes[$r['mode']] = (int) $r['n'];
            }
        }

        $thisWeek = $this->count("SELECT COUNT(*) FROM {$this->prefix}conversations WHERE created_at >= ?", [$week]);
        $lastWeek = $this->count("SELECT COUNT(*) FROM {$this->prefix}conversations WHERE created_at >= ? AND created_at < ?", [$prevWeek, $week]);

        return [
            'conversations' => $conversations,
            'conversations_today' => $this->count("SELECT COUNT(*) FROM {$this->prefix}conversations WHERE created_at >= ?", [$dayStart]),
            'conversations_week' => $thisWeek,
            'week_delta' => self::delta($thisWeek, $lastWeek),
            'messages' => $messages,
            'visitor_messages' => $visitorMsgs,
            'avg_messages' => $conversations > 0 ? round($messages / $conversations, 1) : 0.0,
            'modes' => $modes,
            'escalation_rate' => $conversations > 0 ? round(($modes['agent'] / $conversations) * 100) : 0,
            'series' => $this->daily(14, $now),
            'tools' => $this->topTools(),
            'tool_calls' => $this->count("SELECT COUNT(*) FROM {$this->prefix}messages WHERE role = 'tool'"),
        ];
    }

    /**
     * Conversations + messages per day for the last N days, oldest first.
     * Always returns exactly N buckets so the chart never shows a ragged axis.
     *
     * @return array<int, array{day: int, label: string, conversations: int, messages: int}>
     */
    public function daily(int $days = 14, ?int $now = null): array
    {
        $now = $now ?? time();
        $firstDay = intdiv($now, 86400) - ($days - 1);
        $since = $firstDay * 86400;

        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $firstDay + $i;
            $buckets[$d] = ['day' => $d, 'label' => date('j M', $d * 86400), 'conversations' => 0, 'messages' => 0];
        }
        foreach ([['conversations', 'conversations'], ['messages', 'messages']] as [$table, $key]) {
            $sql = "SELECT FLOOR(created_at / 86400) AS d, COUNT(*) AS n FROM {$this->prefix}{$table}
                    WHERE created_at >= ? GROUP BY FLOOR(created_at / 86400)";
            foreach ($this->rows($sql, [$since]) as $r) {
                $d = (int) $r['d'];
                if (isset($buckets[$d])) {
                    $buckets[$d][$key] = (int) $r['n'];
                }
            }
        }
        return array_values($buckets);
    }

    /**
     * Which tools the AI actually reaches for. Tool names live inside the
     * message payload JSON, so this counts in PHP over a bounded window.
     *
     * @return array<int, array{name: string, value: int}> busiest first (the chart contract)
     */
    public function topTools(int $limit = 6, int $scan = 3000): array
    {
        $st = $this->pdo->prepare("SELECT payload FROM {$this->prefix}messages WHERE role = 'tool' ORDER BY id DESC LIMIT {$scan}");
        $st->execute();
        $counts = [];
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $payload) {
            $name = json_decode((string) $payload, true)['for_call']['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $name => $n) {
            $out[] = ['name' => $name, 'value' => $n];
        }
        return $out;
    }

    /** Percentage change, or null when there is no prior period to compare. */
    public static function delta(int $current, int $previous): ?int
    {
        if ($previous <= 0) {
            return $current > 0 ? null : 0;
        }
        return (int) round((($current - $previous) / $previous) * 100);
    }

    private function count(string $sql, array $args = []): int
    {
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return 0; // a panel must render even on a half-migrated database
        }
    }

    private function rows(string $sql, array $args = []): array
    {
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
