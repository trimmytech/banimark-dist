<?php

namespace Banimark\Connector;

/**
 * The contract between a desk and the CONNECTOR installed on the owner's own
 * website - the small program (a PHP file, a WordPress plugin, a Laravel
 * route, a Node script) that reads their database so Banimark never has to.
 * docs/connector.md is the human-readable version; this file is the one the
 * package, the conformance checker and the tests agree on.
 *
 * One endpoint, three actions, every call signed:
 *
 *   POST <connector url>
 *   Authorization: Bearer <JWT, HS256 with the shared secret>
 *   Content-Type: application/json
 *   {"action": "health" | "schema" | "query", ...}
 *
 * The token carries the action, a 60-second expiry, a nonce and the SHA-256
 * of the body, so a captured request cannot be replayed later, for another
 * action, or with a different query. The connector refuses anything that is
 * not a single SELECT, caps rows and time, and strips columns that look like
 * secrets - the same trust model as SqlTool, enforced a second time where the
 * data is.
 */
final class Protocol
{
    public const VERSION = 1;
    public const ACTIONS = ['health', 'schema', 'query'];
    public const TTL = 60;
    public const MAX_ROWS = 100;
    public const TIMEOUT_MS = 5000;
    public const ISSUER = 'banimark';
    public const AUDIENCE = 'banimark-connector';
    public const HEADER = 'X-Banimark-Connector';

    /** Columns a connector never lists and never returns, whatever the query asked for. */
    public const SENSITIVE = '/pass(word|wd)?|secret|token|api_?key|private_?key|cvv|cvc|card_?(no|num|number)|ssn|otp|salt|_hash$|^hash$|^pin$/i';

    /** Statement words that end the discussion, outside string literals. The same list SqlToolValidator uses. */
    public const FORBIDDEN = [
        'insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'replace',
        'create', 'grant', 'revoke', 'rename', 'call', 'handler', 'do',
        'into', 'outfile', 'dumpfile', 'load_file', 'load', 'benchmark',
        'sleep', 'get_lock', 'release_lock', 'union', 'information_schema',
        'performance_schema', 'mysql', 'sys', 'set', 'prepare', 'execute',
        'deallocate', 'lock', 'unlock', 'for update', 'procedure', 'pg_sleep', 'pg_catalog',
    ];

    /** A fresh shared secret: 48 hex characters, minted by the desk, baked into the download. */
    public static function secret(): string
    {
        return bin2hex(random_bytes(24));
    }

    /** The bearer token for one call. */
    public static function sign(string $secret, string $action, string $body, ?int $now = null): string
    {
        $now = $now ?? time();
        $payload = [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'act' => $action,
            'iat' => $now,
            'exp' => $now + self::TTL,
            'jti' => bin2hex(random_bytes(8)),
            'bh' => hash('sha256', $body),
        ];
        $h = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64(json_encode($payload));
        return $h.'.'.$p.'.'.self::b64(hash_hmac('sha256', $h.'.'.$p, $secret, true));
    }

    /**
     * What a connector does with the token - here so the tests and the
     * conformance checker can prove a connector verifies the same way.
     *
     * @return array{ok: bool, error?: string, action?: string}
     */
    public static function verify(string $secret, string $jwt, string $body, ?int $now = null): array
    {
        $now = $now ?? time();
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || $secret === '') {
            return ['ok' => false, 'error' => 'malformed token'];
        }
        [$h, $p, $s] = $parts;
        $expected = self::b64(hash_hmac('sha256', $h.'.'.$p, $secret, true));
        if (!hash_equals($expected, $s)) {
            return ['ok' => false, 'error' => 'bad signature'];
        }
        $claims = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true);
        if (!is_array($claims) || ($claims['iss'] ?? '') !== self::ISSUER || ($claims['aud'] ?? '') !== self::AUDIENCE) {
            return ['ok' => false, 'error' => 'wrong issuer or audience'];
        }
        if ((int) ($claims['exp'] ?? 0) < $now || (int) ($claims['iat'] ?? 0) > $now + 30) {
            return ['ok' => false, 'error' => 'token expired'];
        }
        if (!hash_equals((string) ($claims['bh'] ?? ''), hash('sha256', $body))) {
            return ['ok' => false, 'error' => 'body does not match the token'];
        }
        $action = (string) ($claims['act'] ?? '');
        if (!in_array($action, self::ACTIONS, true)) {
            return ['ok' => false, 'error' => 'unknown action'];
        }
        return ['ok' => true, 'action' => $action];
    }

    /**
     * Is this statement one a connector may run? null = yes, otherwise why not.
     * Static analysis only; mirrors SqlToolValidator so a connector written in
     * another language has one list to copy.
     */
    public static function guard(string $sql): ?string
    {
        $sql = trim($sql);
        if ($sql === '' || strlen($sql) > 4000) {
            return 'empty or too long';
        }
        if (!preg_match('/^select\s/i', $sql)) {
            return 'only SELECT is allowed';
        }
        if (str_contains($sql, ';')) {
            return 'one statement only';
        }
        if (preg_match('/--|#|\/\*/', $sql)) {
            return 'no comments';
        }
        $stripped = preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $sql);
        foreach (self::FORBIDDEN as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $stripped)) {
                return 'forbidden keyword: '.$word;
            }
        }
        return null;
    }

    public static function sensitive(string $column): bool
    {
        return (bool) preg_match(self::SENSITIVE, $column);
    }

    /** The named placeholders a statement uses, in order of first appearance. */
    public static function placeholders(string $sql): array
    {
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $m);
        return array_values(array_unique($m[1]));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
