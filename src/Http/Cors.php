<?php

namespace Banimark\Http;

/**
 * Which OTHER websites may talk to this install's chat endpoints from the
 * browser - the case where Banimark runs on its own box (support.acme.com)
 * and the widget sits on the customer's site (www.acme.com).
 *
 * Same-origin embedding never needed this and still does not: the browser
 * sends no Origin on same-origin GETs, and a same-origin POST's Origin is our
 * own host, which is always allowed. So an install that never lists a site
 * behaves exactly as before, and the widget script keeps its relative
 * endpoint - only an install WITH a list serves an absolute one, so a script
 * loaded on another domain posts back here and not to that domain.
 *
 * No cookies ride these requests (the widget carries its session id and its
 * token in the body), so credentials are never allowed and the origin is
 * echoed only when it is on the owner's list. A list entry is an origin -
 * scheme, host and optional port - never a path, and "*" means any site.
 *
 * Readable on purpose: an integrator has to be able to see why the browser
 * blocked them, and this decides nothing about licences.
 */
final class Cors
{
    /** setting: one origin per line, saved through sanitize() */
    public const KEY = 'widget_origins';
    public const MAX = 50;

    /** @return string[] normalised origins from the setting ('*' kept as is) */
    public static function origins(array $settings): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,\s]+/', (string) ($settings[self::KEY] ?? '')) ?: [] as $line) {
            $o = self::normalize($line);
            if ($o !== '' && !in_array($o, $out, true)) {
                $out[] = $o;
            }
        }
        return array_slice($out, 0, self::MAX);
    }

    /** What the owner typed, kept only where it is a real origin - the value saveWidget stores. */
    public static function sanitize(string $raw): string
    {
        return implode("\n", self::origins([self::KEY => $raw]));
    }

    /** "https://www.acme.com:8443" -> the same, lower-cased, path/trailing slash dropped; '' when it is not an origin. */
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '*') {
            return '*';
        }
        if ($raw === '' || strlen($raw) > 253 || !preg_match('#^https?://#i', $raw)) {
            return '';
        }
        $p = parse_url($raw);
        if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return '';
        }
        if (isset($p['path']) && rtrim($p['path'], '/') !== '') {
            return '';
        }
        $host = strtolower($p['host']);
        if (!preg_match('/^[a-z0-9.-]+$/', $host) && !preg_match('/^\[[0-9a-f:.]+\]$/', $host)) {
            return '';
        }
        return strtolower($p['scheme']).'://'.$host.(isset($p['port']) ? ':'.(int) $p['port'] : '');
    }

    /**
     * Is this Origin header allowed to read our answers? The install's own
     * origin always is (a same-origin POST carries one); a listed site is; an
     * absent Origin needs nothing (same-origin GET, curl, server-to-server).
     */
    public static function allowed(array $settings, string $origin, string $self = ''): bool
    {
        $origin = self::normalize($origin);
        if ($origin === '' || $origin === '*') {
            return false;
        }
        if ($self !== '' && $origin === self::normalize($self)) {
            return true;
        }
        $list = self::origins($settings);
        return in_array('*', $list, true) || in_array($origin, $list, true);
    }

    /**
     * The headers to add to a visitor-endpoint response, [] when there is
     * nothing to add (no Origin, or one that is not allowed - the browser then
     * blocks the read, which is the point). Preflight = the OPTIONS probe the
     * browser sends before a JSON POST; it gets the method/header grants too.
     *
     * @return array<string, string>
     */
    public static function headers(array $settings, string $origin, bool $preflight = false, string $self = ''): array
    {
        if ($origin === '' || !self::allowed($settings, $origin, $self)) {
            return [];
        }
        $h = [
            'Access-Control-Allow-Origin' => self::normalize($origin),
            'Vary' => 'Origin',
        ];
        if ($preflight) {
            $h['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
            $h['Access-Control-Allow-Headers'] = 'Content-Type';
            $h['Access-Control-Max-Age'] = '86400';
        }
        return $h;
    }

    /**
     * The chat page (/chat-page) inside an <iframe> on a listed site. With no
     * list it stays same-origin only, as it always was; "*" opens it to any
     * site. Returns [header, value].
     *
     * @return array{0: string, 1: string}
     */
    public static function frameHeader(array $settings): array
    {
        $list = self::origins($settings);
        if ($list === []) {
            return ['X-Frame-Options', 'SAMEORIGIN'];
        }
        if (in_array('*', $list, true)) {
            return ['Content-Security-Policy', 'frame-ancestors *'];
        }
        return ['Content-Security-Policy', "frame-ancestors 'self' ".implode(' ', $list)];
    }

    /** Whether the widget script should carry an absolute endpoint (it is loaded on another site). */
    public static function crossSite(array $settings): bool
    {
        return self::origins($settings) !== [];
    }

    /** The install's own origin from the request, '' when unknown. */
    public static function selfOrigin(array $server): string
    {
        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
        if ($host === '') {
            return '';
        }
        $https = ($server['HTTPS'] ?? 'off') !== 'off' && ($server['HTTPS'] ?? '') !== '';
        return ($https ? 'https' : 'http').'://'.strtolower($host);
    }
}
