<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Banimark connector - Laravel
 * ============================
 * Lets the Banimark assistant for "__BANIMARK_DESK__" look things up in this
 * application's database, read-only, without Banimark ever holding a
 * database password. Copy this file into app/Http/Controllers/, the route
 * file into routes/, add one line to routes/api.php (see README.txt), then
 * open /api/banimark-connector in a browser and paste the address it shows
 * into Banimark -> Tools.
 *
 * What it does, and does not do:
 *  - answers only requests signed by your desk (the secret below); anything
 *    else gets a 401 and nothing more;
 *  - runs only a single SELECT, with values bound, capped at 100 rows and
 *    5 seconds; never writes;
 *  - never lists or returns columns that look like secrets (password, token,
 *    card number ...), whatever the query asks for;
 *  - uses the application's default database connection, as configured in
 *    config/database.php - nothing to fill in.
 *
 * Contract: https://github.com/trimmytech/banimark-dist/blob/main/docs/connector.md
 * Works on Laravel 9, 10, 11 and 12 with PHP 8.1 or later (MySQL/MariaDB, PostgreSQL or SQLite).
 */
class BanimarkConnectorController extends Controller
{
    // The secret is already filled in: it matches the desk this file was downloaded from.
    public const SECRET = '__BANIMARK_SECRET__';

    public const CONNECTOR_VERSION = 1;
    public const MAX_ROWS = 100;
    public const TIMEOUT_MS = 5000;
    public const ACTIONS = ['health', 'schema', 'query'];

    /** GET /api/banimark-connector - the "it is installed" page, showing the address and nothing else. */
    public function status(Request $request): Response
    {
        $address = $request->url();
        $db = '';
        $ok = false;
        try {
            $pdo = $this->pdo();
            $n = count($this->tables($pdo));
            $db = 'Connected to the database ('.$this->dialect($pdo).', '.$n.' table'.($n === 1 ? '' : 's').').';
            $ok = true;
        } catch (Throwable $e) {
            $db = 'The database is not reachable yet: '.$this->scrub($e->getMessage());
        }
        $e = static function ($s): string {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Banimark connector</title>'
            .'<style>body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;max-width:640px;margin:48px auto;padding:0 20px;color:#1b1f24}code{background:#f1f3f5;padding:3px 7px;border-radius:6px;font-size:14px;word-break:break-all}.ok{color:#0a7d3e}.bad{color:#b42318}</style></head><body>'
            .'<h1>Banimark connector is installed</h1>'
            .'<p>For the desk <b>'.$e('__BANIMARK_DESK__').'</b>. Copy this address into Banimark &rarr; Tools &rarr; "Where your data lives":</p>'
            .'<p><code>'.$e($address).'</code></p>'
            .'<p class="'.($ok ? 'ok' : 'bad').'">'.$e($db).'</p>'
            .'<p style="color:#666;font-size:13px">This page shows no data. The assistant\'s lookups are signed by your desk, read-only, and limited to the columns you tick in Banimark.</p>'
            .'</body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** POST /api/banimark-connector - the contract: health, schema, query. */
    public function handle(Request $request)
    {
        $body = (string) $request->getContent();
        $token = (string) $request->bearerToken();
        if ($token === '') {
            return $this->json(401, ['ok' => false, 'error' => 'no token']);
        }
        $check = $this->verify(self::SECRET, $token, $body, time());
        if (!$check['ok']) {
            return $this->json(401, ['ok' => false, 'error' => $check['error']]);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['action']) || $data['action'] !== $check['action']) {
            return $this->json(400, ['ok' => false, 'error' => 'the body is not JSON, or its action does not match the token']);
        }
        try {
            $pdo = $this->pdo();
        } catch (Throwable $e) {
            return $this->json(500, ['ok' => false, 'error' => 'database connection failed: '.$this->scrub($e->getMessage())]);
        }
        try {
            switch ($data['action']) {
                case 'health':
                    return $this->json(200, ['ok' => true, 'connector' => 'banimark', 'version' => self::CONNECTOR_VERSION, 'platform' => 'laravel',
                        'dialect' => $this->dialect($pdo), 'tables' => count($this->tables($pdo))]);
                case 'schema':
                    $out = [];
                    foreach ($this->tables($pdo) as $t) {
                        $out[$t] = $this->columns($pdo, $t);
                    }
                    return $this->json(200, ['ok' => true, 'tables' => $out]);
                case 'query':
                    $sql = isset($data['sql']) ? (string) $data['sql'] : '';
                    $why = $this->guard($sql);
                    if ($why !== null) {
                        return $this->json(422, ['ok' => false, 'error' => 'refused: '.$why]);
                    }
                    $max = isset($data['max_rows']) ? (int) $data['max_rows'] : 10;
                    $max = max(1, min(self::MAX_ROWS, $max));
                    $bindings = isset($data['bindings']) && is_array($data['bindings']) ? $data['bindings'] : [];
                    try {
                        $rows = $this->query($pdo, $sql, $bindings, $max);
                    } catch (Throwable $e) {
                        return $this->json(500, ['ok' => false, 'error' => 'the database rejected the query: '.$this->scrub($e->getMessage())]);
                    }
                    return $this->json(200, ['ok' => true, 'rows' => $rows, 'count' => count($rows)]);
                default:
                    return $this->json(400, ['ok' => false, 'error' => 'unknown action']);
            }
        } catch (Throwable $e) {
            return $this->json(500, ['ok' => false, 'error' => 'the database failed: '.$this->scrub($e->getMessage())]);
        }
    }

    // ---- the contract, in one place ------------------------------------------

    /** @return array{ok: bool, error?: string, action?: string} */
    private function verify(string $secret, string $jwt, string $body, int $now): array
    {
        $parts = explode('.', $jwt);
        // (the download replaces the placeholder everywhere, so the secret is checked by length, never by comparing to it)
        if (count($parts) !== 3 || strlen($secret) < 32) {
            return ['ok' => false, 'error' => 'malformed token'];
        }
        [$h, $p, $s] = $parts;
        $expected = $this->b64(hash_hmac('sha256', $h.'.'.$p, $secret, true));
        if (!hash_equals($expected, $s)) {
            return ['ok' => false, 'error' => 'bad signature'];
        }
        $claims = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true);
        if (!is_array($claims) || ($claims['iss'] ?? '') !== 'banimark' || ($claims['aud'] ?? '') !== 'banimark-connector') {
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

    /** null = the statement may run; otherwise why not. */
    private function guard(string $sql): ?string
    {
        $sql = trim($sql);
        if ($sql === '' || strlen($sql) > 4000) {
            return 'empty or too long';
        }
        if (!preg_match('/^select\s/i', $sql)) {
            return 'only SELECT is allowed';
        }
        if (strpos($sql, ';') !== false) {
            return 'one statement only';
        }
        if (preg_match('/--|#|\/\*/', $sql)) {
            return 'no comments';
        }
        $stripped = preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $sql);
        $forbidden = ['insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'replace', 'create', 'grant', 'revoke', 'rename',
            'call', 'handler', 'do', 'into', 'outfile', 'dumpfile', 'load_file', 'load', 'benchmark', 'sleep', 'get_lock', 'release_lock',
            'union', 'information_schema', 'performance_schema', 'mysql', 'sys', 'set', 'prepare', 'execute', 'deallocate', 'lock',
            'unlock', 'for update', 'procedure', 'pg_sleep', 'pg_catalog'];
        foreach ($forbidden as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $stripped)) {
                return 'forbidden keyword: '.$word;
            }
        }
        return null;
    }

    private function sensitive(string $column): bool
    {
        return (bool) preg_match('/pass(word|wd)?|secret|token|api_?key|private_?key|cvv|cvc|card_?(no|num|number)|ssn|otp|salt|_hash$|^hash$|^pin$/i', $column);
    }

    // ---- the database ---------------------------------------------------------

    /** The application's default connection; the time cap is set once per request, best effort. */
    private function pdo(): PDO
    {
        $pdo = DB::connection()->getPdo();
        $d = $this->dialect($pdo);
        try {
            if ($d === 'mysql') {
                $pdo->exec('SET SESSION MAX_EXECUTION_TIME='.self::TIMEOUT_MS);          // MySQL 5.7+
                $pdo->exec('SET SESSION max_statement_time='.(self::TIMEOUT_MS / 1000)); // MariaDB
            } elseif ($d === 'pgsql') {
                $pdo->exec('SET statement_timeout = '.self::TIMEOUT_MS);
            }
        } catch (Throwable $e) {
            // best effort: an older engine without the setting still answers
        }
        return $pdo;
    }

    private function dialect(PDO $pdo): string
    {
        $name = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return $name === 'mariadb' ? 'mysql' : $name;
    }

    /** The owner's tables: plumbing (migrations, sessions, caches, queues, Banimark's own) left out. */
    private function tables(PDO $pdo): array
    {
        $d = $this->dialect($pdo);
        if ($d === 'sqlite') {
            $sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        } elseif ($d === 'pgsql') {
            $sql = "SELECT table_name FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY table_name";
        } else {
            $sql = 'SHOW TABLES';
        }
        $out = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $t = (string) $t;
            $l = strtolower($t);
            if (strpos($l, 'banimark_') === 0 || strpos($l, 'hq_') === 0) {
                continue;
            }
            if (in_array($l, ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_resets',
                'password_reset_tokens', 'personal_access_tokens', 'doctrine_migration_versions', 'migration_versions', 'phinxlog', 'ci_sessions'], true)) {
                continue;
            }
            if (preg_match('/^(telescope_|pulse_|horizon_|oauth_)/', $l) || preg_match('/(^|_)(usermeta|options|sessions|transients)$/', $l)) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    private function columns(PDO $pdo, string $table): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [];
        }
        $d = $this->dialect($pdo);
        $cols = [];
        if ($d === 'sqlite') {
            foreach ($pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cols[] = ['name' => (string) $r['name'], 'type' => strtolower((string) $r['type'])];
            }
        } elseif ($d === 'pgsql') {
            $st = $pdo->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? AND table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY ordinal_position");
            $st->execute([$table]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cols[] = ['name' => (string) $r['column_name'], 'type' => strtolower((string) $r['data_type'])];
            }
        } else {
            foreach ($pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cols[] = ['name' => (string) $r['Field'], 'type' => strtolower((string) $r['Type'])];
            }
        }
        return array_values(array_filter($cols, fn ($c) => !$this->sensitive($c['name'])));
    }

    /**
     * Runs the SELECT through DB::select with only the bindings the statement
     * uses (named `:name`, never interpolated); rows capped and sensitive
     * columns removed.
     */
    private function query(PDO $pdo, string $sql, array $bindings, int $max): array
    {
        $d = $this->dialect($pdo);
        preg_match_all('/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/', preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $sql), $m);
        $bound = [];
        foreach (array_unique($m[1]) as $name) {
            $value = array_key_exists($name, $bindings) ? $bindings[$name] : null;
            if (is_bool($value)) {
                // Laravel binds everything that is not an int as a string: give each engine the boolean it understands
                $value = $d === 'pgsql' ? ($value ? 'true' : 'false') : ($value ? 1 : 0);
            } elseif ($value !== null && !is_int($value)) {
                $value = is_scalar($value) ? (string) $value : json_encode($value);
            }
            $bound[$name] = $value;
        }
        $rows = [];
        foreach (DB::select($sql, $bound) as $row) {
            $clean = [];
            foreach ((array) $row as $k => $v) {
                if (!$this->sensitive((string) $k)) {
                    $clean[$k] = $v;
                }
            }
            $rows[] = $clean;
            if (count($rows) >= $max) {
                break;
            }
        }
        return $rows;
    }

    // ---- plumbing ---------------------------------------------------------------

    private function json(int $status, array $data)
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store']);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** A database error without anything that looks like a credential in it. */
    private function scrub(string $text): string
    {
        return (string) preg_replace('/(password|pwd|pass)=\S+/i', '$1=***', $text);
    }
}
