<?php
/**
 * Banimark connector - plain PHP
 * ==============================
 * Lets the Banimark assistant for "__BANIMARK_DESK__" look things up in this
 * website's database, read-only, without Banimark ever holding a database
 * password. Upload this file next to your site's index.php and paste the
 * address it shows (open it in a browser) into Banimark -> Tools.
 *
 * What it does, and does not do:
 *  - answers only requests signed by your desk (the secret below); anything
 *    else gets a 401 and nothing more;
 *  - runs only a single SELECT, with values bound, capped at 100 rows and
 *    5 seconds; never writes;
 *  - never lists or returns columns that look like secrets (password, token,
 *    card number ...), whatever the query asks for;
 *  - on WordPress it finds the database from wp-config.php by itself.
 *
 * Contract: https://github.com/trimmytech/banimark-dist/blob/main/docs/connector.md
 * Works on PHP 7.4 and later with the PDO extension (MySQL/MariaDB, PostgreSQL or SQLite).
 */

// ---- settings -------------------------------------------------------------
// The secret is already filled in: it matches the desk this file was downloaded from.
const BANIMARK_SECRET = '__BANIMARK_SECRET__';

// Database. Leave these blank on WordPress (wp-config.php is found automatically).
// Otherwise fill them in once, or set the same names as environment variables.
//   MySQL/MariaDB: 'mysql:host=127.0.0.1;port=3306;dbname=shop;charset=utf8mb4'
//   PostgreSQL:    'pgsql:host=127.0.0.1;port=5432;dbname=shop'
//   SQLite:        'sqlite:/var/www/data/shop.sqlite'
const BANIMARK_DB_DSN = '';
const BANIMARK_DB_USER = '';
const BANIMARK_DB_PASS = '';
// A read-only database account is the safest choice - this file never writes anyway.
// ---------------------------------------------------------------------------

const BANIMARK_CONNECTOR_VERSION = 1;
const BANIMARK_MAX_ROWS = 100;
const BANIMARK_TIMEOUT_MS = 5000;
const BANIMARK_TTL = 60;

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
if ($method === 'GET' || $method === 'HEAD') {
    banimark_status_page();
    exit;
}
if ($method !== 'POST') {
    banimark_json(405, array('ok' => false, 'error' => 'POST only'));
}
$body = (string) file_get_contents('php://input');
$auth = banimark_header('Authorization');
if (stripos($auth, 'Bearer ') !== 0) {
    banimark_json(401, array('ok' => false, 'error' => 'no token'));
}
$check = banimark_verify(BANIMARK_SECRET, trim(substr($auth, 7)), $body, time());
if (!$check['ok']) {
    banimark_json(401, array('ok' => false, 'error' => $check['error']));
}
$data = json_decode($body, true);
if (!is_array($data) || !isset($data['action']) || $data['action'] !== $check['action']) {
    banimark_json(400, array('ok' => false, 'error' => 'the body is not JSON, or its action does not match the token'));
}
try {
    $pdo = banimark_pdo();
} catch (Throwable $e) {
    banimark_json(500, array('ok' => false, 'error' => 'database connection failed: '.banimark_scrub($e->getMessage())));
}
switch ($data['action']) {
    case 'health':
        banimark_json(200, array('ok' => true, 'connector' => 'banimark', 'version' => BANIMARK_CONNECTOR_VERSION, 'platform' => 'php',
            'dialect' => banimark_dialect($pdo), 'tables' => count(banimark_tables($pdo))));
        // no break - banimark_json exits
    case 'schema':
        $out = array();
        foreach (banimark_tables($pdo) as $t) {
            $out[$t] = banimark_columns($pdo, $t);
        }
        banimark_json(200, array('ok' => true, 'tables' => $out));
        // no break
    case 'query':
        $sql = isset($data['sql']) ? (string) $data['sql'] : '';
        $why = banimark_guard($sql);
        if ($why !== null) {
            banimark_json(422, array('ok' => false, 'error' => 'refused: '.$why));
        }
        $max = isset($data['max_rows']) ? (int) $data['max_rows'] : 10;
        $max = max(1, min(BANIMARK_MAX_ROWS, $max));
        $bindings = isset($data['bindings']) && is_array($data['bindings']) ? $data['bindings'] : array();
        try {
            $rows = banimark_query($pdo, $sql, $bindings, $max);
        } catch (Throwable $e) {
            banimark_json(500, array('ok' => false, 'error' => 'the database rejected the query: '.banimark_scrub($e->getMessage())));
        }
        banimark_json(200, array('ok' => true, 'rows' => $rows, 'count' => count($rows)));
        // no break
    default:
        banimark_json(400, array('ok' => false, 'error' => 'unknown action'));
}

// ---- the contract, in one place ------------------------------------------

/** @return array{ok: bool, error?: string, action?: string} */
function banimark_verify(string $secret, string $jwt, string $body, int $now): array
{
    $parts = explode('.', $jwt);
    // (the download replaces the placeholder everywhere, so the secret is checked by length, never by comparing to it)
    if (count($parts) !== 3 || strlen($secret) < 32) {
        return array('ok' => false, 'error' => 'malformed token');
    }
    list($h, $p, $s) = $parts;
    $expected = banimark_b64(hash_hmac('sha256', $h.'.'.$p, $secret, true));
    if (!hash_equals($expected, $s)) {
        return array('ok' => false, 'error' => 'bad signature');
    }
    $claims = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true);
    if (!is_array($claims) || ($claims['iss'] ?? '') !== 'banimark' || ($claims['aud'] ?? '') !== 'banimark-connector') {
        return array('ok' => false, 'error' => 'wrong issuer or audience');
    }
    if ((int) ($claims['exp'] ?? 0) < $now || (int) ($claims['iat'] ?? 0) > $now + 30) {
        return array('ok' => false, 'error' => 'token expired');
    }
    if (!hash_equals((string) ($claims['bh'] ?? ''), hash('sha256', $body))) {
        return array('ok' => false, 'error' => 'body does not match the token');
    }
    $action = (string) ($claims['act'] ?? '');
    if (!in_array($action, array('health', 'schema', 'query'), true)) {
        return array('ok' => false, 'error' => 'unknown action');
    }
    return array('ok' => true, 'action' => $action);
}

/** null = the statement may run; otherwise why not. */
function banimark_guard(string $sql): ?string
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
    $forbidden = array('insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'replace', 'create', 'grant', 'revoke', 'rename',
        'call', 'handler', 'do', 'into', 'outfile', 'dumpfile', 'load_file', 'load', 'benchmark', 'sleep', 'get_lock', 'release_lock',
        'union', 'information_schema', 'performance_schema', 'mysql', 'sys', 'set', 'prepare', 'execute', 'deallocate', 'lock',
        'unlock', 'for update', 'procedure', 'pg_sleep', 'pg_catalog');
    foreach ($forbidden as $word) {
        if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $stripped)) {
            return 'forbidden keyword: '.$word;
        }
    }
    return null;
}

function banimark_sensitive(string $column): bool
{
    return (bool) preg_match('/pass(word|wd)?|secret|token|api_?key|private_?key|cvv|cvc|card_?(no|num|number)|ssn|otp|salt|_hash$|^hash$|^pin$/i', $column);
}

// ---- the database ---------------------------------------------------------

function banimark_pdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = BANIMARK_DB_DSN !== '' ? BANIMARK_DB_DSN : (string) getenv('BANIMARK_DB_DSN');
    $user = BANIMARK_DB_USER !== '' ? BANIMARK_DB_USER : (string) getenv('BANIMARK_DB_USER');
    $pass = BANIMARK_DB_PASS !== '' ? BANIMARK_DB_PASS : (string) getenv('BANIMARK_DB_PASS');
    if ($dsn === '') {
        $wp = banimark_wordpress();
        if ($wp === null) {
            throw new RuntimeException('no database settings - fill in BANIMARK_DB_DSN at the top of banimark-connector.php (or upload it into a WordPress site)');
        }
        list($dsn, $user, $pass) = $wp;
    }
    $pdo = new PDO($dsn, $user !== '' ? $user : null, $pass !== '' ? $pass : null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ));
    $d = banimark_dialect($pdo);
    try {
        if ($d === 'mysql') {
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME='.BANIMARK_TIMEOUT_MS);          // MySQL 5.7+
            $pdo->exec('SET SESSION max_statement_time='.(BANIMARK_TIMEOUT_MS / 1000)); // MariaDB
        } elseif ($d === 'pgsql') {
            $pdo->exec('SET statement_timeout = '.BANIMARK_TIMEOUT_MS);
            $pdo->exec('SET default_transaction_read_only = on');
        }
    } catch (Throwable $e) {
        // best effort: an older engine without the setting still answers
    }
    return $pdo;
}

/** wp-config.php, found by walking up from this file; parsed, never included (including it would boot WordPress). */
function banimark_wordpress(): ?array
{
    $dir = __DIR__;
    for ($i = 0; $i < 5; $i++) {
        foreach (array($dir.'/wp-config.php', dirname($dir).'/wp-config.php') as $file) {
            if (is_file($file)) {
                $text = (string) file_get_contents($file);
                $get = function (string $name) use ($text): string {
                    return preg_match("/define\(\s*['\"]".$name."['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $text, $m) ? $m[1] : '';
                };
                $host = $get('DB_HOST') !== '' ? $get('DB_HOST') : 'localhost';
                $port = 3306;
                if (preg_match('/^(.*):(\d+)$/', $host, $m)) {
                    $host = $m[1];
                    $port = (int) $m[2];
                }
                $dsn = (strpos($host, '/') === 0 ? 'mysql:unix_socket='.$host : 'mysql:host='.$host.';port='.$port).';dbname='.$get('DB_NAME').';charset=utf8mb4';
                return array($dsn, $get('DB_USER'), $get('DB_PASSWORD'));
            }
        }
        $dir = dirname($dir);
    }
    return null;
}

function banimark_dialect(PDO $pdo): string
{
    $name = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($name === 'mariadb') {
        return 'mysql';
    }
    return in_array($name, array('mysql', 'pgsql', 'sqlite'), true) ? $name : $name;
}

/** The owner's tables: plumbing (migrations, sessions, caches, queues, Banimark's own) left out. */
function banimark_tables(PDO $pdo): array
{
    $d = banimark_dialect($pdo);
    if ($d === 'sqlite') {
        $sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
    } elseif ($d === 'pgsql') {
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY table_name";
    } else {
        $sql = 'SHOW TABLES';
    }
    $out = array();
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $t = (string) $t;
        $l = strtolower($t);
        if (strpos($l, 'banimark_') === 0 || strpos($l, 'hq_') === 0) {
            continue;
        }
        if (in_array($l, array('migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_resets',
            'password_reset_tokens', 'personal_access_tokens', 'doctrine_migration_versions', 'migration_versions', 'phinxlog', 'ci_sessions'), true)) {
            continue;
        }
        if (preg_match('/^(telescope_|pulse_|horizon_|oauth_)/', $l) || preg_match('/(^|_)(usermeta|options|sessions|transients)$/', $l)) {
            continue;
        }
        $out[] = $t;
    }
    return $out;
}

function banimark_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return array();
    }
    $d = banimark_dialect($pdo);
    $cols = array();
    if ($d === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll() as $r) {
            $cols[] = array('name' => (string) $r['name'], 'type' => strtolower((string) $r['type']));
        }
    } elseif ($d === 'pgsql') {
        $st = $pdo->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? AND table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY ordinal_position");
        $st->execute(array($table));
        foreach ($st->fetchAll() as $r) {
            $cols[] = array('name' => (string) $r['column_name'], 'type' => strtolower((string) $r['data_type']));
        }
    } else {
        foreach ($pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll() as $r) {
            $cols[] = array('name' => (string) $r['Field'], 'type' => strtolower((string) $r['Type']));
        }
    }
    return array_values(array_filter($cols, function ($c) { return !banimark_sensitive($c['name']); }));
}

function banimark_query(PDO $pdo, string $sql, array $bindings, int $max): array
{
    $st = $pdo->prepare($sql);
    preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $m);
    foreach (array_unique($m[1]) as $name) {
        $value = array_key_exists($name, $bindings) ? $bindings[$name] : null;
        if (is_bool($value)) {
            $st->bindValue(':'.$name, $value, PDO::PARAM_BOOL);
        } elseif (is_int($value)) {
            $st->bindValue(':'.$name, $value, PDO::PARAM_INT);
        } elseif ($value === null) {
            $st->bindValue(':'.$name, null, PDO::PARAM_NULL);
        } else {
            $st->bindValue(':'.$name, is_scalar($value) ? (string) $value : json_encode($value));
        }
    }
    $st->execute();
    $rows = array();
    while (($row = $st->fetch()) !== false) {
        $clean = array();
        foreach ($row as $k => $v) {
            if (!banimark_sensitive((string) $k)) {
                $clean[$k] = $v;
            }
        }
        $rows[] = $clean;
        if (count($rows) >= $max) {
            break;
        }
    }
    $st->closeCursor();
    return $rows;
}

// ---- plumbing ---------------------------------------------------------------

function banimark_json(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function banimark_header(string $name): string
{
    $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }
    if ($name === 'Authorization') {
        foreach (array('REDIRECT_HTTP_AUTHORIZATION') as $alt) {   // Apache + FastCGI drop the header unless rewritten
            if (isset($_SERVER[$alt])) {
                return (string) $_SERVER[$alt];
            }
        }
        if (function_exists('apache_request_headers')) {
            foreach ((array) apache_request_headers() as $k => $v) {
                if (strtolower((string) $k) === 'authorization') {
                    return (string) $v;
                }
            }
        }
    }
    return '';
}

function banimark_b64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** A database error without anything that looks like a credential in it. */
function banimark_scrub(string $text): string
{
    return preg_replace('/(password|pwd|pass)=\S+/i', '$1=***', $text);
}

function banimark_status_page(): void
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $path = isset($_SERVER['REQUEST_URI']) ? strtok((string) $_SERVER['REQUEST_URI'], '?') : '/banimark-connector.php';
    $address = $scheme.'://'.$host.$path;
    $db = '';
    $ok = false;
    try {
        $pdo = banimark_pdo();
        $n = count(banimark_tables($pdo));
        $db = 'Connected to the database ('.banimark_dialect($pdo).', '.$n.' table'.($n === 1 ? '' : 's').').';
        $ok = true;
    } catch (Throwable $e) {
        $db = 'The database is not reachable yet: '.banimark_scrub($e->getMessage());
    }
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Banimark connector</title>'
        .'<style>body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;max-width:640px;margin:48px auto;padding:0 20px;color:#1b1f24}code{background:#f1f3f5;padding:3px 7px;border-radius:6px;font-size:14px;word-break:break-all}.ok{color:#0a7d3e}.bad{color:#b42318}</style></head><body>'
        .'<h1>Banimark connector is installed</h1>'
        .'<p>For the desk <b>'.$e('__BANIMARK_DESK__').'</b>. Copy this address into Banimark &rarr; Tools &rarr; "Where your data lives":</p>'
        .'<p><code>'.$e($address).'</code></p>'
        .'<p class="'.($ok ? 'ok' : 'bad').'">'.$e($db).'</p>'
        .'<p style="color:#666;font-size:13px">This page shows no data. The assistant\'s lookups are signed by your desk, read-only, and limited to the columns you tick in Banimark.</p>'
        .'</body></html>';
}
