<?php

namespace Banimark\Laravel;

use Illuminate\Support\Facades\DB;

/**
 * The key a host signs visitor tokens with - the ONE place the Laravel runtime
 * looks it up, and what a host's own code should call when it mints:
 *
 *     VisitorToken::mint(['user_id' => auth()->id()], IdentitySecret::current())
 *
 * It lives in Banimark's settings table so the owner can generate a new one
 * from the Widget page (shown once, never rendered again) without a deploy,
 * and without a web request rewriting `.env`. Installs made before this kept
 * it in `.env` as BANIMARK_IDENTITY_SECRET: that stays the fallback whenever no
 * settings row exists, so an upgrade changes nothing until the owner
 * generates one. From that moment the settings row wins - a host still
 * reading config() would mint tokens Banimark rejects, which is why the page
 * says so before the button.
 *
 * Readable on purpose: a customer must be able to see where their signing key
 * comes from. It decides nothing about licences.
 */
final class IdentitySecret
{
    public const KEY = 'identity_secret';
    public const GENERATED_AT = 'identity_secret_generated_at';

    /** @var array{0: string, 1: string}|null per-request cache: [secret, source] */
    private static ?array $cache = null;

    /** The active secret: the settings row when set, else the .env value, else ''. */
    public static function current(): string
    {
        return self::resolve()[0];
    }

    /** 'settings', 'env' or '' (none set). */
    public static function source(): string
    {
        return self::resolve()[1];
    }

    /** True when both homes hold a value and they differ: a host still on config() is minting bad tokens. */
    public static function envDiffers(): bool
    {
        $env = trim((string) config('banimark.identity_secret', ''));
        return $env !== '' && self::source() === 'settings' && !hash_equals(self::current(), $env);
    }

    /** When the settings row was generated (ISO date) or '' - .env values carry no date. */
    public static function generatedAt(): string
    {
        return (string) (self::settings()[self::GENERATED_AT] ?? '');
    }

    /**
     * Generate and store a new secret. Every token signed with the old one is
     * invalid from this moment - callers show the new value ONCE and never
     * render it again.
     */
    public static function generate(): string
    {
        $secret = bin2hex(random_bytes(32));
        self::store(self::KEY, $secret);
        self::store(self::GENERATED_AT, gmdate('c'));
        self::forget();
        return $secret;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** @return array{0: string, 1: string} */
    private static function resolve(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $row = trim((string) (self::settings()[self::KEY] ?? ''));
        if ($row !== '') {
            return self::$cache = [$row, 'settings'];
        }
        $env = trim((string) config('banimark.identity_secret', ''));
        return self::$cache = [$env, $env !== '' ? 'env' : ''];
    }

    /** @return array<string, string> [] before the tables exist */
    private static function settings(): array
    {
        try {
            return DB::table('banimark_settings')->whereIn('key', [self::KEY, self::GENERATED_AT])->pluck('value', 'key')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function store(string $key, string $value): void
    {
        DB::table('banimark_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }
}
