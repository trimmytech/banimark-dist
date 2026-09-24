<?php

namespace Banimark\Update;

/**
 * The caches that outlive an update, for EVERY runtime.
 *
 * PHP's OPcache keeps the compiled copy of each file. On a server with
 * `opcache.validate_timestamps=0` (common in production) it never looks at
 * the disk again, so after an update - one-click, composer or a zip copied by
 * hand - PHP keeps running the OLD code until someone restarts it. Laravel has
 * its own caches on top (routes, config, events, views: Laravel\RouteCache).
 *
 * Framework-free and readable: the standalone runtime (plain PHP, WordPress,
 * CodeIgniter...) has no artisan, so this is the whole story there.
 */
final class CacheRefresh
{
    /**
     * Drop the compiled copies of every PHP file under $dir, then the whole
     * cache. Safe everywhere: a no-op without OPcache, silent when the host's
     * opcache.restrict_api forbids it. True when anything was reset.
     */
    public static function opcache(string $dir = ''): bool
    {
        $did = false;
        if ($dir !== '' && is_dir($dir) && function_exists('opcache_invalidate')) {
            try {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                        $did = @opcache_invalidate($f->getPathname(), true) || $did;
                    }
                }
            } catch (\Throwable $e) { /* best effort */ }
        }
        if (function_exists('opcache_reset')) {
            $did = @opcache_reset() || $did;
        }
        return $did;
    }

    /**
     * Is PHP running older code than what is on disk? The version this code
     * was built as against the version the package's composer.json says -
     * they differ exactly when OPcache still serves the previous release.
     */
    public static function codeIsStale(string $versionInMemory, ?string $root = null): bool
    {
        $onDisk = Paths::versionOnDisk($root);
        return $onDisk !== '' && $versionInMemory !== '' && $onDisk !== $versionInMemory;
    }

    /**
     * Self-heal on an admin page: when the running code is older than the
     * disk, reset OPcache once and tell the caller to reload. Throttled
     * through $lastTry (a unix time the caller stores), so a host that
     * forbids the reset gets one attempt per 10 minutes, never a loop.
     *
     * @return bool true = caches were reset; reload the page
     */
    public static function healStaleCode(string $versionInMemory, int $lastTry, callable $remember, ?int $now = null): bool
    {
        $now ??= time();
        if (!self::codeIsStale($versionInMemory) || $now - $lastTry < 600) {
            return false;
        }
        $remember($now);
        return self::opcache(Paths::packageRoot());
    }
}
