<?php

namespace Banimark\Ui;

/**
 * The panel's CSS/JS as SAME-ORIGIN FILES, not inline blocks.
 *
 * Customer apps ship Content-Security-Policies; the first pilot's is
 * `script-src 'self' 'nonce-…'`, which silently kills every inline <script>
 * and every onclick= attribute - the theme toggle, the Tool Builder, the New
 * folder button all "did nothing". A file served from the panel's own origin
 * passes 'self' on any policy, so both runtimes expose these at
 * <admin>/assets/<name> with no auth (they carry no secrets) and a content
 * hash in the URL so browsers may cache them forever.
 */
final class Assets
{
    public const FILES = [
        'panel.css' => 'text/css; charset=utf-8',
        'theme.js' => 'application/javascript; charset=utf-8',
        'panel.js' => 'application/javascript; charset=utf-8',
        'toolbuilder.js' => 'application/javascript; charset=utf-8',
        'chat.js' => 'application/javascript; charset=utf-8',
    ];

    private static ?string $version = null;

    public static function exists(string $name): bool
    {
        return isset(self::FILES[$name]) && is_file(self::path($name));
    }

    public static function path(string $name): string
    {
        return __DIR__.'/../../resources/design/'.basename($name);
    }

    public static function content(string $name): string
    {
        return self::exists($name) ? (string) file_get_contents(self::path($name)) : '';
    }

    public static function type(string $name): string
    {
        return self::FILES[$name] ?? 'application/octet-stream';
    }

    /** One hash over every file: any change busts every cached URL at once. */
    public static function version(): string
    {
        if (self::$version === null) {
            $h = hash_init('md5');
            foreach (array_keys(self::FILES) as $f) {
                hash_update($h, self::content($f));
            }
            self::$version = substr(hash_final($h), 0, 10);
        }
        return self::$version;
    }

    /** Response headers for a served asset - immutable because the URL carries the hash. */
    public static function headers(string $name): array
    {
        return [
            'Content-Type' => self::type($name),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
