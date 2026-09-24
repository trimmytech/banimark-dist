<?php

namespace Banimark\Update;

/**
 * Where Banimark actually lives on this server, and whether we are allowed to
 * replace it.
 *
 * The root is derived from this very file rather than from any configured
 * path: `src/Update/Paths.php` is two levels below the package root in every
 * install, plaintext or encoded, Laravel or standalone. A wrong answer here
 * would mean unpacking a release over someone else's directory, so it is
 * checked against composer.json before anything is written.
 */
final class Paths
{
    /** The banimark/banimark package root - the directory the updater replaces. */
    public static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** True when that directory really is the package and not something else. */
    public static function looksLikeBanimark(string $dir): bool
    {
        $composer = rtrim($dir, '/').'/composer.json';
        if (!is_file($composer)) {
            return false;
        }
        $json = json_decode((string) @file_get_contents($composer), true);
        return is_array($json) && ($json['name'] ?? '') === 'banimark/banimark';
    }

    /** The version recorded in the package on disk (not the loaded classes). */
    public static function versionOnDisk(?string $dir = null): string
    {
        $json = json_decode((string) @file_get_contents(rtrim($dir ?? self::packageRoot(), '/').'/composer.json'), true);
        return is_array($json) ? ltrim((string) ($json['version'] ?? ''), 'vV') : '';
    }

    /**
     * Somewhere to download and unpack to. The package's parent is preferred:
     * the final step is a rename, and a rename is only atomic - only
     * instantaneous - within one filesystem. Falling back to the system temp
     * directory risks a slow cross-device copy with the site half-swapped.
     */
    public static function workDir(?string $root = null): ?string
    {
        foreach ([dirname($root ?? self::packageRoot()), sys_get_temp_dir()] as $base) {
            if (is_dir($base) && is_writable($base)) {
                return $base;
            }
        }
        return null;
    }

    /** Backups of previous versions, newest first. */
    public static function backups(?string $root = null): array
    {
        $found = glob(($root ?? self::packageRoot()).'.bak-*') ?: [];
        rsort($found);
        return $found;
    }
}
