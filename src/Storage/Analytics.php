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

    /** The periods the dashboard offers. */
    public const PERIODS = [7 => '7 days', 30 => '30 days', 90 => '90 days'];

    /**
     * The dashboard for one period, each figure compared with the period just
     * before it (so "+12%" always means "than the same length of time before").
     *
     * @return array{days: int, conversations: int, conversations_delta: ?int, ai_handled: int, ai_rate: int,
     *   ai_rate_delta: ?int, handed_over: int, handed_over_delta: ?int, lookups: int, lookups_delta: ?int,
     *   waiting: int, modes: array, series: array, tools: array, total: int}
     */
    public function period(int $days, ?int $now = null, int $onlineMinutes = PdoStore::ONLINE_MINUTES): array
    {
        $days = isset(self::PERIODS[$days]) ? $days : 30;
        $now = $now ?? time();
        $since = $now - $days * 86400;
        $before = $since - $days * 86400;
        $c = "{$this->prefix}conversations";
        $m = "{$this->prefix}messages";

        $conv = fn (int $a, int $b) => $this->count("SELECT COUNT(*) FROM {$c} WHERE created_at >= ? AND created_at < ?", [$a, $b]);
        $handed = fn (int $a, int $b) => $this->count("SELECT COUNT(*) FROM {$c} WHERE escalated_at >= ? AND escalated_at < ? AND escalated_at > 0", [$a, $b]);
        $looks = fn (int $a, int $b) => $this->count("SELECT COUNT(*) FROM {$m} WHERE role = 'tool' AND created_at >= ? AND created_at < ?", [$a, $b]);

        $now1 = $now + 1;
        $cNow = $conv($since, $now1);
        $cPrev = $conv($before, $since);
        $hNow = $handed($since, $now1);
        $hPrev = $handed($before, $since);
        // "answered by the AI" = conversations in the period that never needed a person
        $rate = fn (int $all, int $h) => $all > 0 ? (int) round(max(0, $all - $h) / $all * 100) : 0;

        $modes = ['ai' => 0, 'agent' => 0, 'closed' => 0];
        foreach ($this->rows("SELECT mode, COUNT(*) AS n FROM {$c} WHERE created_at >= ? GROUP BY mode", [$since]) as $r) {
            if (isset($modes[$r['mode']])) {
                $modes[$r['mode']] = (int) $r['n'];
            }
        }

        $aiRate = $rate($cNow, $hNow);
        $aiPrev = $rate($cPrev, $hPrev);
        return [
            'days' => $days,
            'total' => $this->count("SELECT COUNT(*) FROM {$c}"),
            'conversations' => $cNow,
            'conversations_delta' => self::delta($cNow, $cPrev),
            'ai_handled' => max(0, $cNow - $hNow),
            'ai_rate' => $aiRate,
            // percentage POINTS for a rate, not a percent of a percent
            'ai_rate_delta' => $cPrev > 0 ? $aiRate - $aiPrev : null,
            'handed_over' => $hNow,
            'handed_over_delta' => self::delta($hNow, $hPrev),
            'lookups' => $looksNow = $looks($since, $now1),
            'lookups_delta' => self::delta($looksNow, $looks($before, $since)),
            // with a person right now, and the visitor spoke last
            'waiting' => $this->count("SELECT COUNT(*) FROM {$c} WHERE mode = 'agent' AND last_message_at > staff_seen_at"),
            // visitors whose chat (open or closed) checked in during the owner's window
            'online' => $this->count("SELECT COUNT(*) FROM {$c} WHERE last_seen_at > ? AND visitor_deleted_at = 0", [$now - max(1, $onlineMinutes) * 60]),
            'online_minutes' => max(1, $onlineMinutes),
            'modes' => $modes,
            'series' => $this->daily($days, $now),
            'tools' => $this->topTools(),
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
        $counts = [];
        // like every other figure here: a half-migrated database gives zero, not a 500
        foreach (array_column($this->rows("SELECT payload FROM {$this->prefix}messages WHERE role = 'tool' ORDER BY id DESC LIMIT {$scan}"), 'payload') as $payload) {
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
