<?php

namespace Banimark\Http;

/**
 * The picture in the widget's header.
 *
 * Kept small and in the settings table (base64): it is one image, the panel
 * shrinks it to 128 px before upload, and a settings row travels with every
 * backup and every install without a storage driver. Served from a public
 * route with the type taken from the BYTES, never from the upload's name or
 * header - and SVG is refused outright, because an SVG is a document that can
 * carry script.
 */
final class WidgetLogo
{
    /** Raw bytes. A 128 px PNG is 5-20 KB; base64 of this fits a MySQL TEXT. */
    public const MAX_BYTES = 40960;

    private const MAGIC = [
        'image/png' => "\x89PNG\r\n\x1a\n",
        'image/jpeg' => "\xFF\xD8\xFF",
        'image/gif' => 'GIF8',
    ];

    /** The image type these bytes really are, or null when they are not one we serve. */
    public static function sniff(string $bytes): ?string
    {
        foreach (self::MAGIC as $mime => $sig) {
            if (strncmp($bytes, $sig, strlen($sig)) === 0) {
                return $mime;
            }
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    /**
     * Why these bytes cannot be the logo, or null when they can.
     */
    public static function problem(string $bytes): ?string
    {
        if ($bytes === '') {
            return 'That file is empty.';
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return 'That picture is too large for the header ('.(int) ceil(strlen($bytes) / 1024).' KB). Use one under '.(self::MAX_BYTES / 1024).' KB, or pick it again with JavaScript on and the panel will shrink it for you.';
        }
        if (self::sniff($bytes) === null) {
            return 'The logo must be a PNG, JPEG, GIF or WebP picture.';
        }
        return null;
    }

    /** Bytes from a "data:image/...;base64," URL the panel produced, or null. */
    public static function fromDataUrl(string $url): ?string
    {
        if (!preg_match('#^data:image/(?:png|jpeg|webp|gif);base64,([A-Za-z0-9+/=]+)$#', trim($url), $m)) {
            return null;
        }
        $bytes = base64_decode($m[1], true);
        return $bytes === false ? null : $bytes;
    }

    /** @return array{bytes: string, mime: string}|null */
    public static function fromSettings(array $settings): ?array
    {
        $stored = (string) ($settings['logo'] ?? '');
        if ($stored === '') {
            return null;
        }
        $bytes = base64_decode($stored, true);
        if ($bytes === false || ($mime = self::sniff($bytes)) === null) {
            return null;
        }
        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** Response headers: typed by the bytes, never sniffed again, never a document. */
    public static function headers(string $mime): array
    {
        return [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // the URL carries a hash of the picture, so it can be kept for a long time
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ];
    }
}
