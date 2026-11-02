<?php
/**
 * Plugin Name: Banimark Connector
 * Plugin URI:  https://banimark.com
 * Description: Lets the Banimark assistant for "__BANIMARK_DESK__" look things up in this site's database, read-only, without Banimark ever holding a database password. Settings → Banimark connector shows the address to paste into Banimark.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * Author:      Banimark
 * License:     MIT
 *
 * What it does, and does not do:
 *  - answers only requests signed by your desk (the secret below); anything
 *    else gets a 401 and nothing more;
 *  - runs only a single SELECT, with values bound, capped at 100 rows and
 *    5 seconds; never writes;
 *  - never lists or returns columns that look like secrets (password, token,
 *    card number ...), whatever the query asks for;
 *  - leaves WordPress' own plumbing tables (options, meta, transients ...) out.
 *
 * Contract: https://github.com/trimmytech/banimark-dist/blob/main/docs/connector.md
 */

if (!defined('ABSPATH')) {
    exit;
}

// The secret is already filled in: it matches the desk this plugin was downloaded from.
const BANIMARK_CONNECTOR_SECRET = '__BANIMARK_SECRET__';

const BANIMARK_CONNECTOR_VERSION = 1;
const BANIMARK_CONNECTOR_MAX_ROWS = 100;
const BANIMARK_CONNECTOR_TIMEOUT_MS = 5000;

// ---- the endpoint: /wp-json/banimark/v1/connector -------------------------

add_action('rest_api_init', function () {
    register_rest_route('banimark/v1', '/connector', array(
        array(
            'methods' => 'POST',
            'callback' => 'banimark_connector_handle',
            'permission_callback' => '__return_true', // the signed token IS the permission; see banimark_connector_verify()
        ),
        array(
            'methods' => 'GET',
            'callback' => 'banimark_connector_status',
            'permission_callback' => '__return_true',
        ),
    ));
});

/** GET: confirms the connector is installed and shows its address. No data. */
function banimark_connector_status(WP_REST_Request $request)
{
    $db = '';
    $ok = false;
    try {
        $n = count(banimark_connector_tables());
        $db = 'Connected to the database (mysql, '.$n.' table'.($n === 1 ? '' : 's').').';
        $ok = true;
    } catch (Throwable $e) {
        $db = 'The database is not reachable yet: '.banimark_connector_scrub($e->getMessage());
    }
    return new WP_REST_Response(array(
        'ok' => true,
        'connector' => 'banimark',
        'version' => BANIMARK_CONNECTOR_VERSION,
        'platform' => 'wordpress',
        'installed' => true,
        'desk' => '__BANIMARK_DESK__',
        'address' => rest_url('banimark/v1/connector'),
        'secret_set' => strlen(BANIMARK_CONNECTOR_SECRET) >= 32,
        'database' => $db,
        'database_ok' => $ok,
        'note' => 'Copy the address into Banimark -> Tools -> "Where your data lives". This page shows no data.',
    ), 200);
}

/** POST: the contract - health, schema, query - every call signed by the desk. */
function banimark_connector_handle(WP_REST_Request $request)
{
    $body = (string) $request->get_body();
    $auth = (string) $request->get_header('authorization');
    if ($auth === '') {
        // some hosts (Apache + FastCGI) hand the header over under another name
        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                $auth = (string) $_SERVER[$key];
                break;
            }
        }
    }
    if (stripos($auth, 'Bearer ') !== 0) {
        return banimark_connector_json(401, array('ok' => false, 'error' => 'no token'));
    }
    $check = banimark_connector_verify(BANIMARK_CONNECTOR_SECRET, trim(substr($auth, 7)), $body, time());
    if (!$check['ok']) {
        return banimark_connector_json(401, array('ok' => false, 'error' => $check['error']));
    }
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['action']) || $data['action'] !== $check['action']) {
        return banimark_connector_json(400, array('ok' => false, 'error' => 'the body is not JSON, or its action does not match the token'));
    }
    try {
        switch ($data['action']) {
            case 'health':
                return banimark_connector_json(200, array('ok' => true, 'connector' => 'banimark', 'version' => BANIMARK_CONNECTOR_VERSION,
                    'platform' => 'wordpress', 'dialect' => 'mysql', 'tables' => count(banimark_connector_tables())));
            case 'schema':
                $out = array();
                foreach (banimark_connector_tables() as $t) {
                    $out[$t] = banimark_connector_columns($t);
                }
                return banimark_connector_json(200, array('ok' => true, 'tables' => $out));
            case 'query':
                $sql = isset($data['sql']) ? (string) $data['sql'] : '';
                $why = banimark_connector_guard($sql);
                if ($why !== null) {
                    return banimark_connector_json(422, array('ok' => false, 'error' => 'refused: '.$why));
                }
                $max = isset($data['max_rows']) ? (int) $data['max_rows'] : 10;
                $max = max(1, min(BANIMARK_CONNECTOR_MAX_ROWS, $max));
                $bindings = isset($data['bindings']) && is_array($data['bindings']) ? $data['bindings'] : array();
                try {
                    $rows = banimark_connector_query($sql, $bindings, $max);
                } catch (Throwable $e) {
                    return banimark_connector_json(500, array('ok' => false, 'error' => 'the database rejected the query: '.banimark_connector_scrub($e->getMessage())));
                }
                return banimark_connector_json(200, array('ok' => true, 'rows' => $rows, 'count' => count($rows)));
            default:
                return banimark_connector_json(400, array('ok' => false, 'error' => 'unknown action'));
        }
    } catch (Throwable $e) {
        return banimark_connector_json(500, array('ok' => false, 'error' => 'the database failed: '.banimark_connector_scrub($e->getMessage())));
    }
}

// ---- the contract, in one place ------------------------------------------

/** @return array{ok: bool, error?: string, action?: string} */
function banimark_connector_verify($secret, $jwt, $body, $now)
{
    $secret = (string) $secret;
    $parts = explode('.', (string) $jwt);
    // (the download replaces the placeholder everywhere, so the secret is checked by length, never by comparing to it)
    if (count($parts) !== 3 || strlen($secret) < 32) {
        return array('ok' => false, 'error' => 'malformed token');
    }
    list($h, $p, $s) = $parts;
    $expected = banimark_connector_b64(hash_hmac('sha256', $h.'.'.$p, $secret, true));
    if (!hash_equals($expected, $s)) {
        return array('ok' => false, 'error' => 'bad signature');
    }
    $claims = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true);
    if (!is_array($claims) || (isset($claims['iss']) ? $claims['iss'] : '') !== 'banimark' || (isset($claims['aud']) ? $claims['aud'] : '') !== 'banimark-connector') {
        return array('ok' => false, 'error' => 'wrong issuer or audience');
    }
    $exp = isset($claims['exp']) ? (int) $claims['exp'] : 0;
    $iat = isset($claims['iat']) ? (int) $claims['iat'] : 0;
    if ($exp < $now || $iat > $now + 30) {
        return array('ok' => false, 'error' => 'token expired');
    }
    $bh = isset($claims['bh']) ? (string) $claims['bh'] : '';
    if (!hash_equals($bh, hash('sha256', (string) $body))) {
        return array('ok' => false, 'error' => 'body does not match the token');
    }
    $action = isset($claims['act']) ? (string) $claims['act'] : '';
    if (!in_array($action, array('health', 'schema', 'query'), true)) {
        return array('ok' => false, 'error' => 'unknown action');
    }
    return array('ok' => true, 'action' => $action);
}

/** null = the statement may run; otherwise why not. */
function banimark_connector_guard($sql)
{
    $sql = trim((string) $sql);
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

function banimark_connector_sensitive($column)
{
    return (bool) preg_match('/pass(word|wd)?|secret|token|api_?key|private_?key|cvv|cvc|card_?(no|num|number)|ssn|otp|salt|_hash$|^hash$|^pin$/i', (string) $column);
}

// ---- the database (always WordPress' own MySQL/MariaDB, through $wpdb) ------

/** The site's tables: plumbing (migrations, sessions, caches, WordPress' options and meta tables, Banimark's own) left out. */
function banimark_connector_tables()
{
    global $wpdb;
    $out = array();
    foreach ((array) $wpdb->get_col('SHOW TABLES') as $t) {
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
        // WordPress' own plumbing (wp_postmeta, wp_termmeta, wp_term_taxonomy ...); wp_users stays, its user_pass column is stripped
        if (preg_match('/_(termmeta|commentmeta|postmeta|links|term_relationships|term_taxonomy)$/', $l)) {
            continue;
        }
        $out[] = $t;
    }
    return $out;
}

function banimark_connector_columns($table)
{
    global $wpdb;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return array();
    }
    $cols = array();
    foreach ((array) $wpdb->get_results('SHOW COLUMNS FROM `'.$table.'`', ARRAY_A) as $r) {
        $name = (string) $r['Field'];
        if (!banimark_connector_sensitive($name)) {
            $cols[] = array('name' => $name, 'type' => strtolower((string) $r['Type']));
        }
    }
    return array_values($cols);
}

/**
 * Turns the desk's `:name` placeholders into $wpdb->prepare() format
 * specifiers, in order of appearance, and runs the statement with the values
 * bound by $wpdb (never pasted in). Bindings the statement does not use are
 * ignored; a missing one is NULL.
 */
function banimark_connector_query($sql, array $bindings, $max)
{
    global $wpdb;
    list($format, $values) = banimark_connector_placeholders($sql, $bindings);
    $prepared = $values === array() ? $format : $wpdb->prepare($format, $values);
    if (!is_string($prepared) || $prepared === '') {
        throw new RuntimeException('the statement could not be prepared');
    }
    $suppress = $wpdb->suppress_errors(true);
    $wpdb->query('SET SESSION MAX_EXECUTION_TIME='.BANIMARK_CONNECTOR_TIMEOUT_MS);          // MySQL 5.7+, best effort
    $wpdb->query('SET SESSION max_statement_time='.(BANIMARK_CONNECTOR_TIMEOUT_MS / 1000)); // MariaDB, best effort
    $wpdb->last_error = '';
    $result = $wpdb->get_results($prepared, ARRAY_A);
    $error = (string) $wpdb->last_error;
    $wpdb->suppress_errors($suppress);
    if ($error !== '') {
        throw new RuntimeException($error);
    }
    $rows = array();
    foreach ((array) $result as $row) {
        $clean = array();
        foreach ((array) $row as $k => $v) {
            if (!banimark_connector_sensitive((string) $k)) {
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

/**
 * The $wpdb->prepare() form of a statement: literal `%` escaped as `%%`
 * first, then every `:name` outside a string literal replaced by `%d`
 * (ints), `%f` (floats), `%s` (everything else) or the word NULL, with the
 * matching values in the same order. Returns [format, values].
 */
function banimark_connector_placeholders($sql, array $bindings)
{
    $sql = str_replace('%', '%%', (string) $sql);
    $out = '';
    $values = array();
    $n = strlen($sql);
    $i = 0;
    while ($i < $n) {
        $c = $sql[$i];
        if ($c === "'" || $c === '"') {
            $j = $i + 1;
            while ($j < $n) {
                if ($sql[$j] === '\\') {
                    $j += 2;
                    continue;
                }
                if ($sql[$j] === $c) {
                    $j++;
                    break;
                }
                $j++;
            }
            $out .= substr($sql, $i, $j - $i);
            $i = $j;
            continue;
        }
        if ($c === ':' && preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)/', substr($sql, $i), $m) && ($i === 0 || $sql[$i - 1] !== ':')) {
            $name = $m[1];
            $value = array_key_exists($name, $bindings) ? $bindings[$name] : null;
            if ($value === null) {
                $out .= 'NULL';
            } elseif (is_bool($value)) {
                $out .= '%d';
                $values[] = $value ? 1 : 0;
            } elseif (is_int($value)) {
                $out .= '%d';
                $values[] = $value;
            } elseif (is_float($value)) {
                $out .= '%f';
                $values[] = $value;
            } else {
                $out .= '%s';
                $values[] = is_scalar($value) ? (string) $value : json_encode($value);
            }
            $i += strlen($m[0]);
            continue;
        }
        $out .= $c;
        $i++;
    }
    return array($out, $values);
}

// ---- Settings -> Banimark connector -----------------------------------------

add_action('admin_menu', function () {
    add_options_page('Banimark connector', 'Banimark connector', 'manage_options', 'banimark-connector', 'banimark_connector_settings_page');
});

function banimark_connector_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $address = rest_url('banimark/v1/connector');
    $secretSet = strlen(BANIMARK_CONNECTOR_SECRET) >= 32;
    $db = '';
    $ok = false;
    try {
        $n = count(banimark_connector_tables());
        $db = 'Connected to the database (mysql, '.$n.' table'.($n === 1 ? '' : 's').').';
        $ok = true;
    } catch (Throwable $e) {
        $db = 'The database is not reachable yet: '.banimark_connector_scrub($e->getMessage());
    }
    echo '<div class="wrap">';
    echo '<h1>Banimark connector</h1>';
    echo '<p>For the desk <b>'.esc_html('__BANIMARK_DESK__').'</b>. Copy this address into Banimark &rarr; Tools &rarr; "Where your data lives":</p>';
    echo '<p><code style="font-size:14px;word-break:break-all">'.esc_html($address).'</code></p>';
    echo '<p>'.($secretSet ? 'The desk secret is set.' : '<span style="color:#b42318">The desk secret is missing - download the plugin again from Banimark.</span>').'</p>';
    echo '<p style="color:'.($ok ? '#0a7d3e' : '#b42318').'">'.esc_html($db).'</p>';
    echo '<p style="color:#666">This page shows no data. The assistant\'s lookups are signed by your desk, read-only, and limited to the columns you tick in Banimark. '
        .'If a test from Banimark says "no token", your host is dropping the Authorization header: ask them to pass it through to PHP (most do by default).</p>';
    echo '</div>';
}

add_filter('plugin_action_links_'.plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="'.esc_url(admin_url('options-general.php?page=banimark-connector')).'">Settings</a>');
    return $links;
});

// ---- plumbing ---------------------------------------------------------------

function banimark_connector_json($status, array $data)
{
    $response = new WP_REST_Response($data, (int) $status);
    $response->header('Cache-Control', 'no-store');
    return $response;
}

function banimark_connector_b64($raw)
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** A database error without anything that looks like a credential in it. */
function banimark_connector_scrub($text)
{
    return (string) preg_replace('/(password|pwd|pass)=\S+/i', '$1=***', (string) $text);
}
