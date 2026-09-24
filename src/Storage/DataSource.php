<?php

namespace Banimark\Storage;

/**
 * Where a TOOL reads from. By default that is the same database Banimark keeps
 * its own tables in - the common case, where the desk sits inside the app it
 * answers for. An owner can point it somewhere else instead, and should:
 *
 *  - a READ-ONLY database user, so a tool cannot write even if something
 *    slipped past SqlToolValidator;
 *  - a read replica, so support lookups never touch the primary;
 *  - a different server or engine entirely - which is how a Node, Rails or
 *    Django app connects its PostgreSQL data while Banimark keeps its own
 *    tables in SQLite or MySQL.
 *
 * This ships readable on purpose: connecting a customer's own database is
 * exactly the part they need to be able to inspect and adapt.
 */
final class DataSource
{
    /** Settings keys, all optional - absent means "use Banimark's own connection". */
    public const KEYS = ['tools_db_enabled', 'tools_db_driver', 'tools_db_host', 'tools_db_port',
        'tools_db_database', 'tools_db_username', 'tools_db_password', 'tools_db_sqlite_path', 'tools_db_schema'];

    public const DRIVERS = [
        Dialect::MYSQL => 'MySQL / MariaDB',
        Dialect::PGSQL => 'PostgreSQL',
        Dialect::SQLITE => 'SQLite (a file on this server)',
    ];

    private static ?\PDO $cached = null;
    private static string $cacheKey = '';

    public static function isSeparate(array $settings): bool
    {
        return ($settings['tools_db_enabled'] ?? '0') === '1';
    }

    /**
     * The connection tools should use. Falls back to Banimark's own whenever a
     * separate one is not configured - and, deliberately, NEVER falls back
     * when one IS configured but is broken: a tool that quietly reads the
     * wrong database is worse than a tool that refuses.
     *
     * @throws \RuntimeException when a configured connection cannot be opened
     */
    public static function pdo(array $settings, \PDO $own): \PDO
    {
        if (!self::isSeparate($settings)) {
            return $own;
        }
        $key = md5(json_encode(array_intersect_key($settings, array_flip(self::KEYS))));
        if (self::$cached !== null && self::$cacheKey === $key) {
            return self::$cached;
        }
        [$dsn, $user, $pass] = self::dsn($settings);
        try {
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not open the data connection: '.$e->getMessage(), 0, $e);
        }
        // a PostgreSQL install that keeps its tables outside "public"
        $schema = trim((string) ($settings['tools_db_schema'] ?? ''));
        if ($schema !== '' && self::driver($settings) === Dialect::PGSQL && preg_match('/^[A-Za-z0-9_, ]+$/', $schema)) {
            $pdo->exec('SET search_path TO '.$schema);
        }
        self::$cached = $pdo;
        self::$cacheKey = $key;
        return $pdo;
    }

    /** Never throws - for pages that must render even when the connection is wrong. */
    public static function tryPdo(array $settings, \PDO $own): ?\PDO
    {
        try {
            return self::pdo($settings, $own);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function driver(array $settings): string
    {
        $d = (string) ($settings['tools_db_driver'] ?? Dialect::MYSQL);
        return in_array($d, Dialect::SUPPORTED, true) ? $d : Dialect::MYSQL;
    }

    /** @return array{0: string, 1: ?string, 2: ?string} dsn, user, password */
    public static function dsn(array $settings): array
    {
        $driver = self::driver($settings);
        if ($driver === Dialect::SQLITE) {
            $path = trim((string) ($settings['tools_db_sqlite_path'] ?? ''));
            if ($path === '') {
                throw new \RuntimeException('The SQLite file to read from has not been set.');
            }
            return ['sqlite:'.$path, null, null];
        }
        $host = trim((string) ($settings['tools_db_host'] ?? '')) ?: '127.0.0.1';
        $database = trim((string) ($settings['tools_db_database'] ?? ''));
        if ($database === '') {
            throw new \RuntimeException('The database name to read from has not been set.');
        }
        $port = (int) ($settings['tools_db_port'] ?? 0) ?: ($driver === Dialect::PGSQL ? 5432 : 3306);
        $dsn = $driver === Dialect::PGSQL
            ? "pgsql:host={$host};port={$port};dbname={$database}"
            : "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        return [$dsn, (string) ($settings['tools_db_username'] ?? ''), (string) ($settings['tools_db_password'] ?? '')];
    }

    /** What is missing before this can be used at all. */
    public static function misconfigured(array $settings): string
    {
        if (!self::isSeparate($settings)) {
            return '';
        }
        try {
            self::dsn($settings);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return '';
    }

    /**
     * "Test the connection": open it, read the table list, and - the part that
     * matters - find out whether the user can WRITE. A support desk should be
     * reading through an account that cannot.
     *
     * @return array{ok: bool, message: string, tables: int, writable: ?bool}
     */
    public static function check(array $settings, \PDO $own): array
    {
        $problem = self::misconfigured($settings);
        if ($problem !== '') {
            return ['ok' => false, 'message' => $problem, 'tables' => 0, 'writable' => null];
        }
        try {
            $pdo = self::pdo($settings, $own);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'tables' => 0, 'writable' => null];
        }
        try {
            $tables = (new \Banimark\Tools\SchemaInspector($pdo))->tables();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connected, but could not list the tables: '.$e->getMessage(), 'tables' => 0, 'writable' => null];
        }
        $writable = self::probeWritable($pdo);
        $where = self::isSeparate($settings) ? 'that database' : 'the database';
        $message = 'Connected to '.$where.' and found '.count($tables).' table'.(count($tables) === 1 ? '' : 's').'. ';
        $message .= $writable === true
            ? 'The account can WRITE - tools only ever read, but a read-only user would be safer.'
            : ($writable === false ? 'The account is read-only, which is exactly right.' : 'Whether the account can write could not be determined.');
        return ['ok' => true, 'message' => $message, 'tables' => count($tables), 'writable' => $writable];
    }

    /** Can this account write? Asked without leaving anything behind. */
    private static function probeWritable(\PDO $pdo): ?bool
    {
        $name = 'banimark_write_probe_'.bin2hex(random_bytes(4));
        try {
            $pdo->exec("CREATE TABLE {$name} (id INTEGER)");
        } catch (\Throwable $e) {
            return false; // refused = read-only, which is what we hope to see
        }
        try {
            $pdo->exec("DROP TABLE {$name}");
        } catch (\Throwable $e) {
            return true; // created but cannot drop: still writable, and now untidy
        }
        return true;
    }

    /**
     * The callable a SqlTool runs through. Values are BOUND, never
     * interpolated - the trust model depends on it - and the same code serves
     * the engine, "Try it" and the schema picker.
     */
    public static function runner(\PDO $pdo): callable
    {
        return function (string $sql, array $bindings) use ($pdo) {
            $st = $pdo->prepare($sql);
            foreach ($bindings as $name => $value) {
                $st->bindValue(':'.$name, $value);
            }
            $st->execute();
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        };
    }

    /**
     * Store the form. A blank password keeps the stored one, like every other
     * secret in this panel.
     *
     * @param callable(string, string): void $set
     */
    public static function save(array $input, callable $set): void
    {
        $set('tools_db_enabled', ($input['tools_db_enabled'] ?? '0') === '1' ? '1' : '0');
        $driver = (string) ($input['tools_db_driver'] ?? '');
        $set('tools_db_driver', in_array($driver, Dialect::SUPPORTED, true) ? $driver : Dialect::MYSQL);
        foreach (['tools_db_host', 'tools_db_database', 'tools_db_username', 'tools_db_schema', 'tools_db_sqlite_path'] as $k) {
            $set($k, trim((string) ($input[$k] ?? '')));
        }
        $port = (int) ($input['tools_db_port'] ?? 0);
        $set('tools_db_port', $port > 0 && $port <= 65535 ? (string) $port : '');
        $password = (string) ($input['tools_db_password'] ?? '');
        if ($password !== '') {
            $set('tools_db_password', $password);
        }
        self::forget();
    }

    /** Forget the cached handle - settings changed. */
    public static function forget(): void
    {
        self::$cached = null;
        self::$cacheKey = '';
    }
}
