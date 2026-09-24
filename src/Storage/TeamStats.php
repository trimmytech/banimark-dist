<?php

namespace Banimark\Storage;

/**
 * What an owner needs to know about the people answering chats: who is around,
 * how much each one handles, and how long visitors wait for them. Computed in
 * PHP from plain rows so it runs the same on MySQL and SQLite.
 */
final class TeamStats
{
    /** A staff member is "online" within this many seconds of their last click. */
    public const ONLINE = 300;
    public const AWAY = 3600;
    /** Waits longer than this are treated as "the visitor had left", not a response time. */
    private const MAX_WAIT = 12 * 3600;

    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
    }

    /**
     * @return array<int, array{id:int, name:string, email:string, role:string, status:string, last_active_at:int,
     *   replies:int, conversations:int, avg_wait:?int, median_wait:?int, first_avg:?int, first_median:?int, handovers:int}>
     */
    public function summary(int $since, ?int $now = null): array
    {
        $now = $now ?? time();
        $agents = [];
        $st = $this->pdo->query("SELECT id, name, email, role, last_active_at, enabled, status FROM {$this->prefix}agents ORDER BY name");
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            if ((string) ($a['status'] ?? 'active') !== 'active') {
                continue; // an invitation not yet accepted has nothing to report
            }
            $agents[(int) $a['id']] = [
                'id' => (int) $a['id'], 'name' => (string) $a['name'], 'email' => (string) $a['email'], 'role' => (string) $a['role'],
                'status' => self::presence((int) $a['last_active_at'], $now, (bool) $a['enabled']),
                'last_active_at' => (int) $a['last_active_at'],
                'replies' => 0, 'conversations' => 0, 'avg_wait' => null, 'median_wait' => null,
                'first_avg' => null, 'first_median' => null, 'handovers' => 0,
                '_convs' => [], '_waits' => [], '_firsts' => [],
            ];
        }
        // every agent reply in the period, with when the visitor last spoke before it
        $st = $this->pdo->prepare(
            "SELECT m.conversation_id, m.agent_id, m.created_at,
                (SELECT MAX(v.created_at) FROM {$this->prefix}messages v WHERE v.conversation_id = m.conversation_id AND v.role = 'user' AND v.id < m.id) AS asked_at,
                (SELECT MIN(p.id) FROM {$this->prefix}messages p WHERE p.conversation_id = m.conversation_id AND p.role = 'agent') AS first_agent_msg_id,
                m.id, c.escalated_at
             FROM {$this->prefix}messages m JOIN {$this->prefix}conversations c ON c.id = m.conversation_id
             WHERE m.role = 'agent' AND m.created_at >= ? ORDER BY m.id"
        );
        $st->execute([$since]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $aid = (int) $r['agent_id'];
            if (!isset($agents[$aid])) {
                continue; // legacy rows (0) or a deleted account
            }
            $a = &$agents[$aid];
            $a['replies']++;
            $a['_convs'][(int) $r['conversation_id']] = true;
            $asked = (int) ($r['asked_at'] ?? 0);
            $wait = $asked > 0 ? (int) $r['created_at'] - $asked : null;
            if ($wait !== null && $wait >= 0 && $wait <= self::MAX_WAIT) {
                $a['_waits'][] = $wait;
            }
            // first human reply after the AI (or a visitor) asked for a person
            if ((int) $r['id'] === (int) $r['first_agent_msg_id'] && (int) $r['escalated_at'] > 0) {
                $first = (int) $r['created_at'] - (int) $r['escalated_at'];
                if ($first >= 0 && $first <= self::MAX_WAIT) {
                    $a['_firsts'][] = $first;
                    $a['handovers']++;
                }
            }
            unset($a);
        }
        foreach ($agents as &$a) {
            $a['conversations'] = count($a['_convs']);
            [$a['avg_wait'], $a['median_wait']] = self::stats($a['_waits']);
            [$a['first_avg'], $a['first_median']] = self::stats($a['_firsts']);
            unset($a['_convs'], $a['_waits'], $a['_firsts']);
        }
        unset($a);
        // busiest first, then by name
        usort($agents, fn ($x, $y) => [$y['replies'], $x['name']] <=> [$x['replies'], $y['name']]);
        return array_values($agents);
    }

    /** The latest replies: who answered whom, and when. */
    public function recent(int $limit = 25): array
    {
        $st = $this->pdo->prepare(
            "SELECT m.id, m.content, m.created_at, a.name AS agent, c.session_id, c.visitor_label, c.mode
             FROM {$this->prefix}messages m
             JOIN {$this->prefix}conversations c ON c.id = m.conversation_id
             LEFT JOIN {$this->prefix}agents a ON a.id = m.agent_id
             WHERE m.role = 'agent' ORDER BY m.id DESC LIMIT ".max(1, $limit)
        );
        $st->execute();
        return array_map(fn ($r) => [
            'id' => (int) $r['id'], 'agent' => (string) ($r['agent'] ?? '') ?: 'a staff member', 'at' => (int) $r['created_at'],
            'session_id' => (string) $r['session_id'], 'visitor' => (string) $r['visitor_label'] ?: 'Anonymous visitor', 'mode' => (string) $r['mode'],
            'snippet' => mb_strimwidth(\Banimark\Files\Markers::parse((string) $r['content'])['text'] ?: '(a file)', 0, 90, '…'),
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Totals across the team for the period: how many handovers are still waiting, etc. */
    public function overview(int $since, ?int $now = null): array
    {
        $now = $now ?? time();
        $q = function (string $sql, array $args): int {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            return (int) $st->fetchColumn();
        };
        return [
            'waiting' => $q("SELECT COUNT(*) FROM {$this->prefix}conversations WHERE mode = 'agent' AND last_message_at > staff_seen_at", []),
            'handovers' => $q("SELECT COUNT(*) FROM {$this->prefix}conversations WHERE escalated_at >= ?", [$since]),
            'replies' => $q("SELECT COUNT(*) FROM {$this->prefix}messages WHERE role = 'agent' AND created_at >= ?", [$since]),
            'online' => $q("SELECT COUNT(*) FROM {$this->prefix}agents WHERE enabled = 1 AND last_active_at >= ?", [$now - self::ONLINE]),
        ];
    }

    public static function presence(int $lastActive, int $now, bool $enabled = true): string
    {
        if (!$enabled) {
            return 'disabled';
        }
        if ($lastActive <= 0) {
            return 'never';
        }
        $ago = $now - $lastActive;
        return $ago <= self::ONLINE ? 'online' : ($ago <= self::AWAY ? 'away' : 'offline');
    }

    /** "2m 10s", "1h 4m", "3d" - waits people can read at a glance. */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
        }
        return floor($seconds / 86400).'d';
    }

    /** @return array{0: ?int, 1: ?int} average and median, null when empty */
    private static function stats(array $values): array
    {
        if ($values === []) {
            return [null, null];
        }
        sort($values);
        $n = count($values);
        $median = $n % 2 ? $values[intdiv($n, 2)] : (int) round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);
        return [(int) round(array_sum($values) / $n), $median];
    }
}
