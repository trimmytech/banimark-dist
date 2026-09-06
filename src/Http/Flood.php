<?php

namespace Banimark\Http;

/**
 * The flood rules for the public chat endpoints, with the owner's numbers from
 * the "Data & protection" page. Applied by both runtimes BEFORE a message is
 * stored or a model is called, so a script hammering /chat costs nothing.
 *
 * Keys are per session AND per IP: a bot that opens a new session for every
 * message still runs into the IP counters, and a shared office IP with many
 * real people still has the (looser) IP limits to breathe in.
 */
final class Flood
{
    public const DEFAULTS = [
        'flood_msgs_per_min' => 12,      // per session
        'flood_ip_msgs_per_min' => 40,   // per IP, all sessions
        'flood_sessions_per_hour' => 30, // new sessions per IP
        'flood_uploads_per_10min' => 10, // per session
    ];

    public static function limit(array $settings, string $key): int
    {
        $n = (int) ($settings[$key] ?? self::DEFAULTS[$key]);
        return $n <= 0 ? self::DEFAULTS[$key] : max(1, min(10000, $n));
    }

    /**
     * @param string $kind 'chat' | 'upload' | 'session'
     * @return array{error: string, retry_after: int}|null null = allowed
     */
    public static function check(RateLimiter $limiter, array $settings, string $ip, string $sessionId, string $kind): ?array
    {
        if (($settings['flood_enabled'] ?? '1') === '0') {
            return null;
        }
        $ip = $ip !== '' ? $ip : 'unknown';
        $sid = $sessionId !== '' ? $sessionId : 'none';
        switch ($kind) {
            case 'chat':
                // both counters take the hit BEFORE either verdict: a message refused
                // per session still burns the IP budget, so cycling sessions is no way round
                $perSession = $sessionId !== '' ? $limiter->hit("chat:s:{$sid}", 60) : 0;
                $perIp = $limiter->hit("chat:ip:{$ip}", 60);
                if ($perSession > self::limit($settings, 'flood_msgs_per_min')) {
                    return ['error' => "You're sending messages very quickly - give it a moment and try again.", 'retry_after' => 30];
                }
                if ($perIp > self::limit($settings, 'flood_ip_msgs_per_min')) {
                    return ['error' => 'Too many messages from your connection right now - please try again in a minute.', 'retry_after' => 60];
                }
                return null;
            case 'session':
                if ($limiter->hit("new:ip:{$ip}", 3600) > self::limit($settings, 'flood_sessions_per_hour')) {
                    return ['error' => 'Too many new conversations from your connection - please try again later.', 'retry_after' => 600];
                }
                return null;
            case 'upload':
                if ($limiter->hit("up:s:{$sid}", 600) > self::limit($settings, 'flood_uploads_per_10min')) {
                    return ['error' => 'That is a lot of files at once - give it a few minutes.', 'retry_after' => 300];
                }
                return null;
        }
        return null;
    }
}
