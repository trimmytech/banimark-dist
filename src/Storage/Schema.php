<?php

namespace Banimark\Storage;

/**
 * The ONE definition of every Banimark table, in portable SQL (MySQL/MariaDB
 * and SQLite). The Laravel migration, the standalone web installer and the
 * test suite all call this - they can never disagree.
 */
class Schema
{
    public static function create(\PDO $pdo, string $prefix = 'banimark_'): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $bool = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';
        // SQLite is UTF-8 throughout; MySQL is NOT unless it is told. A server
        // whose default is still `utf8` (= utf8mb3) rejects any 4-byte
        // character - every emoji - with "Incorrect string value", which used
        // to surface as a 500 the moment a visitor sent one. Say utf8mb4 here
        // and convert older installs in ensureUtf8mb4().
        $tail = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}conversations (
            id {$pk},
            session_id VARCHAR(64) NOT NULL,
            identity_hash VARCHAR(80) NOT NULL DEFAULT 'anon',
            visitor_label VARCHAR(190) NOT NULL DEFAULT '',
            visitor_email VARCHAR(190) NOT NULL DEFAULT '',
            mode VARCHAR(12) NOT NULL DEFAULT 'ai',
            last_seen_at INTEGER NOT NULL DEFAULT 0,
            followup_at INTEGER NOT NULL DEFAULT 0,
            last_message_at INTEGER NOT NULL DEFAULT 0,
            escalated_at INTEGER NOT NULL DEFAULT 0,
            visitor_typing_at INTEGER NOT NULL DEFAULT 0,
            agent_typing_at INTEGER NOT NULL DEFAULT 0,
            staff_seen_at INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT 0
        ){$tail}");
        self::index($pdo, "{$prefix}conv_session", "{$prefix}conversations", 'session_id', true);

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}messages (
            id {$pk},
            conversation_id INTEGER NOT NULL,
            role VARCHAR(12) NOT NULL,
            content TEXT NOT NULL,
            payload TEXT NULL,
            agent_id INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT 0
        ){$tail}");

        // fixed-window counters for flood protection and the daily AI cap
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}throttle (
            k VARCHAR(120) NOT NULL,
            bucket INTEGER NOT NULL,
            hits INTEGER NOT NULL DEFAULT 0,
            expires_at INTEGER NOT NULL DEFAULT 0
        ){$tail}");
        self::index($pdo, "{$prefix}throttle_kb", "{$prefix}throttle", 'k, bucket', true);
        self::index($pdo, "{$prefix}msg_conv", "{$prefix}messages", 'conversation_id, id', false);

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}providers (
            id {$pk},
            slug VARCHAR(40) NOT NULL,
            driver VARCHAR(40) NOT NULL,
            api_key TEXT NOT NULL,
            model VARCHAR(120) NOT NULL,
            base_url VARCHAR(190) NULL,
            temperature DECIMAL(3,2) NOT NULL DEFAULT 0.4,
            enabled {$bool} NOT NULL DEFAULT 1,
            is_default {$bool} NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NULL,
            updated_at VARCHAR(32) NULL
        ){$tail}");
        self::index($pdo, "{$prefix}provider_slug", "{$prefix}providers", 'slug', true);

        // rules live in FOLDERS (personality, business protection, ...): the
        // system instruction is built folder by folder, in folder order, so the
        // owner shapes the prompt's structure without ever seeing a prompt
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}rule_folders (
            id {$pk},
            title VARCHAR(190) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            sort INTEGER NOT NULL DEFAULT 0,
            enabled {$bool} NOT NULL DEFAULT 1
        ){$tail}");

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}rules (
            id {$pk},
            folder_id INTEGER NOT NULL DEFAULT 0,
            title VARCHAR(190) NOT NULL,
            content TEXT NOT NULL,
            sort INTEGER NOT NULL DEFAULT 0,
            enabled {$bool} NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NULL,
            updated_at VARCHAR(32) NULL
        ){$tail}");

        // column names match the Laravel layer's queries exactly; backtick
        // quoting works on MySQL/MariaDB AND SQLite
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}tools (
            id {$pk},
            name VARCHAR(80) NOT NULL,
            description TEXT NOT NULL,
            parameters TEXT NOT NULL,
            `sql` TEXT NOT NULL,
            columns TEXT NOT NULL,
            context TEXT NULL,
            max_rows INTEGER NOT NULL DEFAULT 10,
            enabled {$bool} NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NULL,
            updated_at VARCHAR(32) NULL
        ){$tail}");
        self::index($pdo, "{$prefix}tool_name", "{$prefix}tools", 'name', true);

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}agents (
            id {$pk},
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'agent',
            enabled {$bool} NOT NULL DEFAULT 1,
            totp_secret VARCHAR(64) NOT NULL DEFAULT '',
            totp_enabled INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            invite_token VARCHAR(64) NOT NULL DEFAULT '',
            invited_at VARCHAR(32) NULL,
            activated_at VARCHAR(32) NULL,
            permissions TEXT NULL,
            last_active_at INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NULL
        ){$tail}");
        self::index($pdo, "{$prefix}agent_email", "{$prefix}agents", 'email', true);

        // files shared in a chat, by a visitor or by staff. The BYTES live on the
        // configured disk (local or S3) - this table is only the record of them.
        // `token` is an unguessable capability: the URL carries it, so an <img>
        // works without a session while nobody can enumerate other people's files.
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}attachments (
            id {$pk},
            conversation_id INTEGER NOT NULL DEFAULT 0,
            sent INTEGER NOT NULL DEFAULT 0,
            token VARCHAR(64) NOT NULL,
            disk VARCHAR(20) NOT NULL DEFAULT 'local',
            path VARCHAR(255) NOT NULL,
            name VARCHAR(190) NOT NULL,
            mime VARCHAR(120) NOT NULL DEFAULT '',
            size INTEGER NOT NULL DEFAULT 0,
            source VARCHAR(10) NOT NULL DEFAULT 'visitor',
            created_at INTEGER NOT NULL DEFAULT 0
        ){$tail}");
        self::index($pdo, "{$prefix}attach_token", "{$prefix}attachments", 'token', true);
        self::index($pdo, "{$prefix}attach_conv", "{$prefix}attachments", 'conversation_id', false);

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}settings (
            `key` VARCHAR(80) NOT NULL,
            `value` TEXT NOT NULL
        ){$tail}");
        self::index($pdo, "{$prefix}settings_key", "{$prefix}settings", '`key`', true);

        self::upgrade($pdo, $prefix);
    }

    /**
     * Bring an EXISTING install up to date, once per version.
     *
     * Fresh installs get everything from create(); upgrades are the path that
     * bites - Laravel will not re-run a migration it has already recorded, and
     * the standalone installer only ever runs once. So both runtimes call this
     * and it re-runs the idempotent create() whenever the stored schema version
     * differs from the running package.
     *
     * Never throws: a panel that cannot write a settings row must still open.
     *
     * @return bool whether anything was brought up to date
     */
    public static function ensureCurrent(\PDO $pdo, string $version, string $prefix = 'banimark_'): bool
    {
        try {
            $st = $pdo->prepare("SELECT `value` FROM {$prefix}settings WHERE `key` = 'schema_version'");
            $st->execute();
            $stored = (string) ($st->fetchColumn() ?: '');
            if ($stored === $version) {
                return false;
            }
            self::create($pdo, $prefix);
            $pdo->prepare("DELETE FROM {$prefix}settings WHERE `key` = 'schema_version'")->execute();
            $pdo->prepare("INSERT INTO {$prefix}settings (`key`, `value`) VALUES ('schema_version', ?)")->execute([$version]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Columns added after 1.0 of a table. CREATE TABLE IF NOT EXISTS is a
     * no-op on an existing install, so every later column has to arrive by
     * ALTER as well - and idempotently, since create() runs on every boot of
     * the installer and on every migrate.
     */
    public static function upgrade(\PDO $pdo, string $prefix = 'banimark_'): void
    {
        self::addColumn($pdo, "{$prefix}conversations", 'visitor_email', "VARCHAR(190) NOT NULL DEFAULT ''");
        self::addColumn($pdo, "{$prefix}conversations", 'last_seen_at', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumn($pdo, "{$prefix}conversations", 'followup_at', 'INTEGER NOT NULL DEFAULT 0');
        // when the AI (or an agent) handed the chat to a human - drives the staff alert feed
        self::addColumn($pdo, "{$prefix}conversations", 'escalated_at', 'INTEGER NOT NULL DEFAULT 0');
        // live typing indicators, both directions (a timestamp; "typing" = within the last few seconds)
        self::addColumn($pdo, "{$prefix}conversations", 'visitor_typing_at', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumn($pdo, "{$prefix}conversations", 'agent_typing_at', 'INTEGER NOT NULL DEFAULT 0');
        // when a staff member last opened this conversation - drives "unread" in the inbox
        self::addColumn($pdo, "{$prefix}conversations", 'staff_seen_at', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumn($pdo, "{$prefix}rules", 'folder_id', 'INTEGER NOT NULL DEFAULT 0');
        // staff 2FA (TOTP): secret + whether it is switched on for this account
        self::addColumn($pdo, "{$prefix}agents", 'totp_secret', "VARCHAR(64) NOT NULL DEFAULT ''");
        self::addColumn($pdo, "{$prefix}agents", 'totp_enabled', 'INTEGER NOT NULL DEFAULT 0');
        // invitations: new staff are 'pending' until they set their own password
        // from the emailed link; existing rows default to 'active' and keep working
        self::addColumn($pdo, "{$prefix}agents", 'status', "VARCHAR(12) NOT NULL DEFAULT 'active'");
        self::addColumn($pdo, "{$prefix}agents", 'invite_token', "VARCHAR(64) NOT NULL DEFAULT ''");
        self::addColumn($pdo, "{$prefix}agents", 'invited_at', 'VARCHAR(32) NULL');
        self::addColumn($pdo, "{$prefix}agents", 'activated_at', 'VARCHAR(32) NULL');
        // per-staff permissions (JSON list); NULL = legacy account, treated as full editor
        self::addColumn($pdo, "{$prefix}agents", 'permissions', 'TEXT NULL');
        // 0.16: who sent an agent reply (team page), when staff were last in the panel
        self::addColumn($pdo, "{$prefix}messages", 'agent_id', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumn($pdo, "{$prefix}agents", 'last_active_at', 'INTEGER NOT NULL DEFAULT 0');
        // 0.16.1: emoji. Installs created before this release inherited the
        // server's default charset, which on MySQL is usually still utf8mb3.
        self::ensureUtf8mb4($pdo, $prefix);
    }

    /**
     * Bring Banimark's own tables to utf8mb4 on MySQL/MariaDB.
     *
     * A table created before 0.16.1 took the DATABASE default, and a database
     * created before MySQL 8 defaults to `utf8` - three bytes per character,
     * which cannot hold an emoji: the insert fails with SQLSTATE[HY000] 1366
     * "Incorrect string value". Only OUR tables are touched; the host
     * application's own schema is none of our business.
     *
     * Every indexed column here is at most VARCHAR(190) (760 bytes in utf8mb4),
     * so the conversion fits even under the old 767-byte index prefix limit.
     * Never throws: one stubborn table must not stop the others, and a schema
     * upgrade must never take the panel - or the chat - down with it.
     */
    public static function ensureUtf8mb4(\PDO $pdo, string $prefix = 'banimark_'): void
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return; // SQLite stores UTF-8 and nothing else
        }
        try {
            // `_` is a LIKE wildcard and the prefix ends in one - escape it, or
            // "banimark_%" would also claim a host table called "banimarkXlogs"
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%';
            $st = $pdo->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES'
                .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?'
                ." AND TABLE_COLLATION IS NOT NULL AND TABLE_COLLATION NOT LIKE 'utf8mb4%'"
            );
            $st->execute([$like]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $table) {
                try {
                    $pdo->exec('ALTER TABLE `'.str_replace('`', '', (string) $table).'` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                } catch (\Throwable $e) {
                    // e.g. an ancient MySQL refusing an index prefix - the
                    // 4-byte fallback in PdoStore keeps that install chatting
                }
            }
        } catch (\Throwable $e) {
            // no information_schema rights, or not MySQL at all
        }
    }

    /** ALTER ... ADD COLUMN works on MySQL and SQLite alike; a duplicate is fine. */
    private static function addColumn(\PDO $pdo, string $table, string $column, string $definition): void
    {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (\PDOException $e) {
            $m = $e->getMessage();
            if (stripos($m, 'duplicate') === false && stripos($m, 'already exists') === false) {
                throw $e;
            }
        }
    }

    /**
     * Portable index creation: SQLite/MariaDB accept IF NOT EXISTS, stock
     * MySQL does not - so everywhere else we create and swallow the
     * duplicate-index error. (Caught live by the first pilot install.)
     */
    public static function index(\PDO $pdo, string $name, string $table, string $cols, bool $unique): void
    {
        $unique = $unique ? 'UNIQUE ' : '';
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec("CREATE {$unique}INDEX IF NOT EXISTS {$name} ON {$table} ({$cols})");
            return;
        }
        try {
            $pdo->exec("CREATE {$unique}INDEX {$name} ON {$table} ({$cols})");
        } catch (\PDOException $e) {
            // 1061 duplicate key name / 42000 already exists - fine, idempotent
            if (strpos($e->getMessage(), 'Duplicate') === false && strpos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }

}
