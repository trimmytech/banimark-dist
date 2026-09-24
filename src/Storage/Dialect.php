<?php

namespace Banimark\Storage;

/**
 * The handful of places SQL genuinely differs between the engines Banimark
 * runs on. Kept in one file so a new engine is one set of branches, not a
 * hunt through every query.
 *
 * `key`, `value` and `sql` are reserved words somewhere in every engine, so
 * identifiers go through quote() rather than being backticked by hand -
 * backticks are MySQL's alone and are a syntax error on PostgreSQL.
 */
final class Dialect
{
    public const MYSQL = 'mysql';
    public const SQLITE = 'sqlite';
    public const PGSQL = 'pgsql';

    /** The engines Banimark can keep its OWN tables in. */
    public const SUPPORTED = [self::MYSQL, self::SQLITE, self::PGSQL];

    public static function of(\PDO $pdo): string
    {
        $name = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        return $name === 'mariadb' ? self::MYSQL : $name;
    }

    /** Quote an identifier for this engine. */
    public static function quote(string $driver, string $identifier): string
    {
        $clean = str_replace(['`', '"'], '', $identifier);
        return $driver === self::PGSQL ? '"'.$clean.'"' : '`'.$clean.'`';
    }

    /** An auto-incrementing primary key. */
    public static function primaryKey(string $driver): string
    {
        return match ($driver) {
            self::SQLITE => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            self::PGSQL => 'BIGSERIAL PRIMARY KEY',
            default => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        };
    }

    /** A 0/1 flag. PostgreSQL has a real boolean, but SMALLINT keeps every
     *  comparison in the codebase ("enabled = 1") working unchanged. */
    public static function boolean(string $driver): string
    {
        return match ($driver) {
            self::SQLITE => 'INTEGER',
            self::PGSQL => 'SMALLINT',
            default => 'TINYINT(1)',
        };
    }

    /**
     * What follows CREATE TABLE (...). MySQL needs to be told utf8mb4 or a
     * server defaulting to utf8mb3 rejects the first emoji a visitor sends.
     * SQLite and PostgreSQL are UTF-8 throughout.
     */
    public static function tableTail(string $driver): string
    {
        return $driver === self::MYSQL ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    }

    /** Some engines reject a length on TEXT-ish columns in an index. */
    public static function supportsCreateIndexIfNotExists(string $driver): bool
    {
        return $driver !== self::MYSQL; // stock MySQL has no IF NOT EXISTS for indexes
    }
}
