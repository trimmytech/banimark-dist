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
            if (!($cached ?? app()->routesAreCached())) {
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
        return '<div class="flash-err" data-route-cache><span><b>Laravel\'s route cache is older than this version of Banimark.</b> '
            .count($missing).' of Banimark\'s pages and chat actions are not reachable until it is rebuilt. On the server, in your app\'s folder, run '
            .'<code>php artisan route:cache</code> (or <code>php artisan route:clear</code>). Run it again after every Banimark update.</span></div>';
    }

    /** After a one-click update: drop the stale cache so the new routes are live at once. */
    public static function clearIfCached(): bool
    {
        try {
            if (!app()->routesAreCached()) {
                return false;
            }
            Artisan::call('route:clear');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** route() that cannot throw on a stale cache: null when the route is not known yet. */
    public static function url(string $name, mixed $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }
}
