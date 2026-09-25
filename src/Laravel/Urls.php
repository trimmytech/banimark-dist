<?php

namespace Banimark\Laravel;

/**
 * Where Banimark lives on a Laravel site. Both are the owner's choice:
 *
 *   BANIMARK_ADMIN_PATH=support/control   (default banimark/admin) - the panel
 *   BANIMARK_WIDGET_PATH=help             (default banimark)       - widget.js,
 *       the chat API, the chat link, file links: <path>/widget.js, <path>/chat...
 *
 * Read through config (banimark.admin.prefix / banimark.widget.prefix), so a
 * config:cache keeps working; a config file published before these keys
 * existed simply keeps the defaults. Readable and core-free on purpose: the
 * page shown when the core cannot load needs it too.
 */
final class Urls
{
    public const ADMIN_DEFAULT = 'banimark/admin';
    public const WIDGET_DEFAULT = 'banimark';

    public static function admin(): string
    {
        return self::clean((string) config('banimark.admin.prefix', self::ADMIN_DEFAULT), self::ADMIN_DEFAULT);
    }

    public static function widget(): string
    {
        return self::clean((string) config('banimark.widget.prefix', self::WIDGET_DEFAULT), self::WIDGET_DEFAULT);
    }

    /** "/help/chat" style path under the widget prefix. */
    public static function widgetPath(string $rest = ''): string
    {
        return '/'.self::widget().($rest !== '' ? '/'.ltrim($rest, '/') : '');
    }

    /**
     * A path of plain segments, never empty: an empty prefix would put
     * /chat or /widget.js at the root of the host's site, and a stray "//" or
     * ".." is how a prefix turns into a redirect somewhere else.
     */
    public static function clean(string $value, string $default): string
    {
        if (str_contains($value, '//') || str_contains($value, ':') || str_contains($value, '\\')) {
            return $default; // never a scheme, a host or a network path - only a folder on this site
        }
        $v = trim($value, "/ \t");
        return preg_match('#^[A-Za-z0-9_~-][A-Za-z0-9._~-]*(/[A-Za-z0-9_~-][A-Za-z0-9._~-]*)*$#', $v) === 1 ? $v : $default;
    }
}
