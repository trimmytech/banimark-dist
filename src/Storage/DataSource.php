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
        'tools_db_database', 'tools_db_username', 'tools_db_password', 'tools_db_sqlite_path', 'tools_db_schema',
        'connector_url', 'connector_secret', 'shopify_store', 'shopify_token', 'shopify_client_id', 'shopify_client_secret'];

    /**
     * Where database tools read from:
     *  own        Banimark's own connection (self-hosted default)
     *  separate   another database the owner typed in
     *  connector  the owner's own website, through the connector they installed
     *             (Connector\Protocol) - the only database mode a hosted desk has
     *  shopify    the owner's Shopify store, through its Admin API (Shopify\Client) -
     *             seen as three tables, since nothing can be installed on Shopify
     *  none       a hosted desk with no connector: API and spreadsheet tools only
     */
    public const MODE_OWN = 'own';
    public const MODE_SEPARATE = 'separate';
    public const MODE_CONNECTOR = 'connector';
    public const MODE_NONE = 'none';
    public const MODE_SHOPIFY = 'shopify';

    public static function mode(array $settings): string
    {
        $v = (string) ($settings['tools_db_enabled'] ?? '0');
        if ($v === self::MODE_CONNECTOR) {
            return self::MODE_CONNECTOR;
        }
        if ($v === self::MODE_SHOPIFY) {
            return self::MODE_SHOPIFY;
        }
        if ($v === '1' && !\Banimark\Standalone\Hosted::on()) {
            return self::MODE_SEPARATE;
        }
        // a hosted desk's "own" database is OURS, never the owner's
        return \Banimark\Standalone\Hosted::on() ? self::MODE_NONE : self::MODE_OWN;
    }

    /** The connector is chosen AND set up: an address and a secret. */
    public static function isConnector(array $settings): bool
    {
        return self::mode($settings) === self::MODE_CONNECTOR
            && trim((string) ($settings['connector_url'] ?? '')) !== ''
            && trim((string) ($settings['connector_secret'] ?? '')) !== '';
    }

    /** Shopify is chosen AND set up: a store name, and either a Client ID + secret (Dev Dashboard app) or a permanent token (admin app). */
    public static function isShopify(array $settings): bool
    {
        return self::mode($settings) === self::MODE_SHOPIFY
            && \Banimark\Shopify\Client::store((string) ($settings['shopify_store'] ?? '')) !== ''
            && (self::shopifyCredentials($settings) || trim((string) ($settings['shopify_token'] ?? '')) !== '');
    }

    /** A Dev Dashboard app's Client ID and secret are both there (they win over a token when both are typed). */
    public static function shopifyCredentials(array $settings): bool
    {
        return trim((string) ($settings['shopify_client_id'] ?? '')) !== '' && trim((string) ($settings['shopify_client_secret'] ?? '')) !== '';
    }

    /**
     * The owner's OWN data is reachable from here - through the connector on
     * their site or their Shopify store - so database tools may exist on a
     * hosted desk too (ToolFactory refuses them otherwise).
     */
    public static function ownerData(array $settings): bool
    {
        return self::isConnector($settings) || self::isShopify($settings);
    }

    /** @throws \RuntimeException when the store is not set up */
    public static function shopify(array $settings, ?callable $transport = null): \Banimark\Shopify\Client
    {
        if (!self::isShopify($settings)) {
            throw new \RuntimeException('The Shopify store is not connected yet: type its myshopify.com name and the Admin API access token of the app you made for it.');
        }
        $store = \Banimark\Shopify\Client::store((string) $settings['shopify_store']);
        return self::shopifyCredentials($settings)
            ? \Banimark\Shopify\Client::withCredentials($store, (string) $settings['shopify_client_id'], (string) $settings['shopify_client_secret'], $transport)
            : new \Banimark\Shopify\Client($store, trim((string) $settings['shopify_token']), $transport);
    }

    /** @throws \RuntimeException when the connector is not set up */
    public static function connector(array $settings, ?callable $transport = null): \Banimark\Connector\Client
    {
        if (!self::isConnector($settings)) {
            throw new \RuntimeException('The connector is not set up yet: download it, install it on your website, and paste its address here.');
        }
        return new \Banimark\Connector\Client(trim((string) $settings['connector_url']), trim((string) $settings['connector_secret']), $transport);
    }

    /** The desk's connector secret - minted on first sight, kept until the owner asks for a new one. */
    public static function ensureSecret(array $settings, callable $set): string
    {
        $secret = trim((string) ($settings['connector_secret'] ?? ''));
        if ($secret === '') {
            $secret = \Banimark\Connector\Protocol::secret();
            $set('connector_secret', $secret);
        }
        return $secret;
    }

    /**
     * The callable every SqlTool runs through, whatever the mode. A hosted
     * desk without a connector gets a runner that refuses, so a stale tool
     * row can never read OUR database.
     */
    public static function runnerFor(array $settings, \PDO $own, ?callable $transport = null): callable
    {
        return match (self::mode($settings)) {
            self::MODE_CONNECTOR => self::connector($settings, $transport)->runner(),
            self::MODE_SHOPIFY => \Banimark\Shopify\Query::runner(self::shopify($settings, $transport)),
            self::MODE_NONE => function () { throw new \RuntimeException(\Banimark\Standalone\Hosted::NO_SQL); },
            default => self::runner(self::pdo($settings, $own)),
        };
    }

    /** Tables and columns for the Tool Builder, whatever the mode. @throws \RuntimeException */
    public static function schemaFor(array $settings, \PDO $own, ?callable $transport = null): array
    {
        return match (self::mode($settings)) {
            self::MODE_CONNECTOR => self::connector($settings, $transport)->schema(),
            self::MODE_SHOPIFY => \Banimark\Shopify\Catalog::schema(),
            self::MODE_NONE => [],
            default => (new \Banimark\Tools\SchemaInspector(self::pdo($settings, $own)))->all(),
        };
    }

    /** The "dialect" of a Shopify store: the SELECT subset Shopify\Query accepts. */
    public const DIALECT_SHOPIFY = 'shopify';

    /** Which SQL dialect the builder and the draftsman should write. Never throws. */
    public static function dialectFor(array $settings, \PDO $own, ?callable $transport = null): string
    {
        try {
            return match (self::mode($settings)) {
                self::MODE_CONNECTOR => self::connector($settings, $transport)->dialect(),
                self::MODE_SHOPIFY => self::DIALECT_SHOPIFY,
                self::MODE_NONE => Dialect::MYSQL,
                default => Dialect::of(self::pdo($settings, $own)),
            };
        } catch (\Throwable $e) {
            return Dialect::MYSQL;
        }
    }

    public const DRIVERS = [
        Dialect::MYSQL => 'MySQL / MariaDB',
        Dialect::PGSQL => 'PostgreSQL',
        Dialect::SQLITE => 'SQLite (a file on this server)',
    ];

    private static ?\PDO $cached = null;
    private static string $cacheKey = '';

    public static function isSeparate(array $settings): bool
    {
        return self::mode($settings) === self::MODE_SEPARATE;
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
        if (self::mode($settings) === self::MODE_SHOPIFY) {
            $store = trim((string) ($settings['shopify_store'] ?? ''));
            if ($store === '') {
                return 'Type your store\'s name - the part before .myshopify.com in your Shopify admin address.';
            }
            if (\Banimark\Shopify\Client::store($store) === '') {
                return '"'.$store.'" is not a myshopify.com store name. Use the name from your admin address (admin.shopify.com/store/NAME), not your shop\'s own domain.';
            }
            if (!self::shopifyCredentials($settings) && trim((string) ($settings['shopify_token'] ?? '')) === '') {
                return trim((string) ($settings['shopify_client_id'] ?? '')) !== ''
                    ? 'Paste the Client secret too - it is next to the Client ID on the app\'s Settings page in the Dev Dashboard.'
                    : 'Paste the Client ID and Client secret of the app you made in Shopify\'s Dev Dashboard (or, for an app made in your Shopify admin, its Admin API access token).';
            }
            return '';
        }
        if (self::mode($settings) === self::MODE_CONNECTOR) {
            $url = trim((string) ($settings['connector_url'] ?? ''));
            if ($url === '') {
                return 'Paste the connector\'s address - the one its page shows after you install it.';
            }
            if (!preg_match('#^https?://#i', $url) || parse_url($url, PHP_URL_HOST) === null) {
                return 'The connector address must start with https:// (or http:// for a site without a certificate).';
            }
            if (\Banimark\Standalone\Hosted::on() && (!preg_match('#^https://#i', $url) || !\Banimark\Standalone\Hosted::publicAddress($url))) {
                return 'The connector must be on the public internet over https:// - localhost and private network addresses are this server, not your website.';
            }
            if (trim((string) ($settings['connector_secret'] ?? '')) === '') {
                return 'No connector secret yet - download the connector once and one is made.';
            }
            return '';
        }
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
    public static function check(array $settings, \PDO $own, ?callable $transport = null): array
    {
        $problem = self::misconfigured($settings);
        if ($problem !== '') {
            return ['ok' => false, 'message' => $problem, 'tables' => 0, 'writable' => null];
        }
        if (self::mode($settings) === self::MODE_CONNECTOR) {
            try {
                $h = self::connector($settings, $transport)->health();
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage(), 'tables' => 0, 'writable' => null];
            }
            $n = (int) ($h['tables'] ?? 0);
            return ['ok' => true, 'tables' => $n, 'writable' => false,
                'message' => 'Connected through the connector on '.(string) (parse_url(trim((string) $settings['connector_url']), PHP_URL_HOST) ?: 'your website')
                    .' ('.(string) ($h['platform'] ?? 'php').', '.(string) ($h['dialect'] ?? 'mysql').'): '.$n.' table'.($n === 1 ? '' : 's')
                    .'. Lookups are signed, read-only, and never return columns that look like secrets.'];
        }
        if (self::mode($settings) === self::MODE_SHOPIFY) {
            try {
                $shop = self::shopify($settings, $transport)->shop();
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage(), 'tables' => 0, 'writable' => null];
            }
            $missing = [];
            foreach (\Banimark\Shopify\Catalog::SCOPES as $table => $scope) {
                if (!in_array($scope, $shop['scopes'], true)) {
                    $missing[] = $scope.' (for '.$table.')';
                }
            }
            $who = 'Connected to '.($shop['name'] !== '' ? $shop['name'].' ('.$shop['domain'].')' : $shop['domain']).($shop['currency'] !== '' ? ', prices in '.$shop['currency'] : '').'.';
            $fix = 'in your Shopify admin open the app, Configuration, tick those scopes, save, then install the app again and paste the new token if it shows one.';
            if ($missing !== []) {
                return ['ok' => false, 'tables' => 3 - count($missing), 'writable' => false,
                    'message' => $who.' But the app is missing '.implode(', ', $missing).': '.$fix];
            }
            $extra = [];
            foreach (\Banimark\Shopify\Catalog::EXTRA_SCOPES as $scope => $why) {
                if (!in_array($scope, $shop['scopes'], true)) {
                    $extra[] = $scope.' ('.$why.')';
                }
            }
            return ['ok' => true, 'tables' => 3, 'writable' => false,
                'message' => $who.' The token can read orders, customers and products, and nothing else - the assistant can never change anything in the store.'
                    .($extra !== [] ? ' It is missing '.implode(' and ', $extra).', so the assistant will not see those: '.$fix : '')];
        }
        if (self::mode($settings) === self::MODE_NONE) {
            return ['ok' => false, 'message' => \Banimark\Standalone\Hosted::NO_SQL, 'tables' => 0, 'writable' => null];
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
        $choice = (string) ($input['tools_db_enabled'] ?? '0');
        // a hosted desk has three choices: no database, the connector, or Shopify - never ours, never a typed-in one
        $set('tools_db_enabled', in_array($choice, [self::MODE_CONNECTOR, self::MODE_SHOPIFY], true) ? $choice : ($choice === '1' && !\Banimark\Standalone\Hosted::on() ? '1' : '0'));
        if (array_key_exists('connector_url', $input)) {
            $set('connector_url', trim((string) $input['connector_url']));
        }
        if (array_key_exists('shopify_store', $input)) {
            $store = trim((string) $input['shopify_store']);
            $set('shopify_store', \Banimark\Shopify\Client::store($store) ?: $store);   // the raw text is kept so the message can say what is wrong with it
        }
        $token = trim((string) ($input['shopify_token'] ?? ''));
        if ($token !== '') {
            $set('shopify_token', $token);
        }
        if (array_key_exists('shopify_client_id', $input)) {
            $id = trim((string) $input['shopify_client_id']);
            $set('shopify_client_id', $id);
            // whatever was minted for the previous credentials is stale now
            $store = trim((string) ($input['shopify_store'] ?? ''));
            \Banimark\Shopify\Client::forget(\Banimark\Shopify\Client::store($store) ?: $store, $id);
        }
        $secret = trim((string) ($input['shopify_client_secret'] ?? ''));
        if ($secret !== '') {
            $set('shopify_client_secret', $secret);
        }
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
