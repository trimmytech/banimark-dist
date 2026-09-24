<?php

namespace Banimark\Laravel;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/**
 * A host that ran `php artisan route:cache` keeps answering from the route
 * list compiled at THAT moment. Update Banimark afterwards and its new code is
 * live while its new routes are not: a page that links to one throws "Route
 * [...] not defined", and a new visitor endpoint answers 404 (found live on
 * banimark.com: the conversation page 500'd on the Keep button's route, and
 * the app's "delete conversation" got a 404).
 *
 * So: compare the route names our own route files declare with what Laravel
 * has. Readable on purpose - it decides nothing about licences.
 */
final class RouteCache
{
    /** @var array<string, string[]>|null route file => names it declares */
    private static ?array $declared = null;

    /** @return array<string, string[]> */
    public static function declared(): array
    {
        if (self::$declared === null) {
            self::$declared = [];
            foreach (['admin.php', 'widget.php'] as $file) {
                $src = (string) @file_get_contents(__DIR__.'/../../routes/'.$file);
                preg_match_all("/->name\\('([^']+)'\\)/", $src, $m);
                self::$declared[$file] = array_values(array_unique($m[1]));
            }
        }
        return self::$declared;
    }

    /**
     * Route names our files declare that Laravel does not know. Only while the
     * routes are cached (otherwise they are always current), and only for a
     * file Laravel loaded at all - a host may switch the widget routes off.
     *
     * @return string[]
     */
    public static function missing(?bool $cached = null): array
    {
        try {
            if (!($cached ?? self::routesCached())) {
                return [];
            }
            $out = [];
            foreach (self::declared() as $names) {
                $known = array_filter($names, fn (string $n) => Route::has($n));
                if ($known === []) {
                    continue; // this file is not mounted on this host
                }
                foreach ($names as $n) {
                    if (!Route::has($n)) {
                        $out[] = $n;
                    }
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The admin banner: what is wrong and the one command that fixes it. */
    public static function notice(?bool $cached = null): string
    {
        $missing = self::missing($cached);
        if ($missing === []) {
            return '';
        }
        // normally heal() has already fixed this before the page drew; the
        // banner is for a host where Banimark may not run artisan itself
        return '<div class="flash-err" data-route-cache><span><b>Laravel\'s route cache is older than this version of Banimark.</b> '
            .count($missing).' of Banimark\'s pages and chat actions are not reachable until it is rebuilt, and Banimark could not rebuild it itself on this server. '
            .'Ask whoever manages the server to run <code>php artisan route:cache</code> in your app\'s folder.</span></div>';
    }

    /**
     * Laravel's own caches that outlive an update. Each: is it on, and the
     * artisan commands that rebuild / drop it. Views have no "on" - compiled
     * Blade is always there, and an update unpacked from a zip can carry
     * file times OLDER than the compiled copies, so they are always dropped.
     */
    private const CACHES = [
        'routes' => ['getCachedRoutesPath', 'route:cache', 'route:clear'],
        'config' => ['getCachedConfigPath', 'config:cache', 'config:clear'],
        'events' => ['getCachedEventsPath', 'event:cache', 'event:clear'],
    ];

    private const PENDING = 'caches_to_rebuild';

    /**
     * Update step "install": the old code is still what runs in this request,
     * so the caches are DROPPED here and remembered; the next step (the
     * database, a new request on the new code) rebuilds them.
     *
     * @return string[] what was cleared
     */
    public static function clearForUpdate(): array
    {
        $on = [];
        foreach (self::CACHES as $name => [$path, , $clear]) {
            try {
                // the file itself, not routesAreCached(): same answer on a real
                // host, and the only one that is right under every harness
                if (is_file(app()->{$path}())) {
                    self::artisan($clear);
                    $on[] = $name;
                }
            } catch (\Throwable $e) { /* best effort */ }
        }
        try { self::artisan('view:clear'); } catch (\Throwable $e) { /* best effort */ }
        self::remember(implode(',', $on));
        return $on;
    }

    /**
     * Update step "database" (new code): put back the caches the host had on,
     * built from the new files. A cache that will not build is left cleared -
     * slower, never broken.
     *
     * @return string[] what was rebuilt
     */
    public static function rebuildAfterUpdate(): array
    {
        $want = array_filter(explode(',', self::recalled()));
        self::remember('');
        $done = [];
        foreach ($want as $name) {
            [, $cache, $clear] = self::CACHES[$name] ?? [null, null, null];
            if ($cache === null) {
                continue;
            }
            try {
                self::artisan($cache);
                $done[] = $name;
            } catch (\Throwable $e) {
                try { self::artisan($clear); } catch (\Throwable $e2) {}
            }
        }
        \Banimark\Update\CacheRefresh::opcache();
        return $done;
    }

    /**
     * An admin page found the route cache stale (e.g. updated with composer,
     * not the panel): rebuild it once, then the caller reloads. Throttled to
     * one try per 10 minutes, so a host where it cannot work gets the banner
     * instead of a loop.
     */
    public static function heal(?int $now = null): bool
    {
        $now ??= time();
        if (self::missing() === []) {
            return false;
        }
        $last = (int) self::setting('route_heal_at');
        if ($now - $last < 600) {
            return false;
        }
        self::setting('route_heal_at', (string) $now);
        try {
            self::artisan('route:cache');
        } catch (\Throwable $e) {
            try { self::artisan('route:clear'); } catch (\Throwable $e2) { return false; }
        }
        \Banimark\Update\CacheRefresh::opcache();
        return true;
    }

    /**
     * route:cache / config:cache boot a FRESH application to read the files,
     * and booting it makes that fresh app the global container and the facade
     * root - the rest of THIS request would then talk to a different app
     * (a different DB connection, session, config). Put ours back.
     */
    private static function artisan(string $command): int
    {
        $app = app();
        try {
            return Artisan::call($command);
        } finally {
            \Illuminate\Container\Container::setInstance($app);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        }
    }

    private static function routesCached(): bool
    {
        return is_file(app()->getCachedRoutesPath());
    }

    /** Kept for callers of the first version of this fix. */
    public static function clearIfCached(): bool
    {
        return self::clearForUpdate() !== [];
    }

    private static function remember(string $v): void
    {
        self::setting(self::PENDING, $v);
    }

    private static function recalled(): string
    {
        return (string) self::setting(self::PENDING);
    }

    private static function setting(string $key, ?string $value = null): ?string
    {
        try {
            $t = \Illuminate\Support\Facades\DB::table('banimark_settings');
            if ($value === null) {
                return $t->where('key', $key)->value('value');
            }
            \Illuminate\Support\Facades\DB::table('banimark_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
        } catch (\Throwable $e) {}
        return null;
    }

    /** route() that cannot throw on a stale cache: null when the route is not known yet. */
    public static function url(string $name, mixed $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }
}
