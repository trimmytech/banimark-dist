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
            created_at INTEGER NOT NULL DEFAULT 0
        )");
        self::index($pdo, "{$prefix}conv_session", "{$prefix}conversations", 'session_id', true);

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}messages (
            id {$pk},
            conversation_id INTEGER NOT NULL,
            role VARCHAR(12) NOT NULL,
            content TEXT NOT NULL,
            payload TEXT NULL,
            created_at INTEGER NOT NULL DEFAULT 0
        )");
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
        )");
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
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}rules (
            id {$pk},
            folder_id INTEGER NOT NULL DEFAULT 0,
            title VARCHAR(190) NOT NULL,
            content TEXT NOT NULL,
            sort INTEGER NOT NULL DEFAULT 0,
            enabled {$bool} NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NULL,
            updated_at VARCHAR(32) NULL
        )");

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
        )");
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
            created_at VARCHAR(32) NULL
        )");
        self::index($pdo, "{$prefix}agent_email", "{$prefix}agents", 'email', true);

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}settings (
            `key` VARCHAR(80) NOT NULL,
            `value` TEXT NOT NULL
        )");
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
