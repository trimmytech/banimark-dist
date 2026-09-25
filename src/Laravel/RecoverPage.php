<?php

namespace Banimark\Laravel;

use Banimark\CoreHealth;
use Illuminate\Http\Request;

/**
 * `<admin>/recover` - update or reinstall Banimark when the admin itself is
 * broken. The admin error screen's update form posts here; opening it directly
 * works too, as the way out when no admin page loads at all.
 *
 * It sits OUTSIDE the admin group on purpose: no staff login, no licence gate,
 * no panel controller - any of those may be what throws. It touches nothing
 * but readable code (CoreUnavailable::updateSection -> Update\Recovery), and
 * installing asks for the site's licence key instead of a login. The `web`
 * group still applies, so the form carries a CSRF token.
 *
 * When the core cannot load at all, CoreUnavailable (global) answers this path
 * before it is reached, with the same update box.
 */
final class RecoverPage
{
    public function __invoke(Request $request)
    {
        $back = self::safeBack((string) $request->input('back', ''));
        $support = self::setting('support_email');
        $section = CoreUnavailable::updateSection($request, self::url(), self::hidden($back), $back, [
            'ajax' => self::ajaxUrls(),
            'support' => $support !== '' ? 'mailto:'.$support.'?subject='.rawurlencode('Banimark admin error - no update available') : '',
        ]);
        return response(CoreHealth::page([
            'title' => 'Update Banimark',
            'message' => 'Install a newer version of Banimark, or put this one back, without signing in to the admin. Installing needs this site\'s licence key.',
            'steps' => [],
        ], self::setting('support_email'), $section), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /* ---- the live steps (recover.js), one real server call each ---- */

    /** Step 1: is there anything newer? Asks HQ; no key needed to ask. */
    public function check(Request $request)
    {
        return self::guard(function () {
            $recovery = CoreUnavailable::recovery();
            $check = $recovery->check();
            if (!$check['reachable']) {
                return ['ok' => false, 'error' => 'Could not reach Banimark HQ to check. Make sure this server can reach the internet, then try again.'];
            }
            $installed = $recovery->installed();
            $offer = $check['offer'];
            if ($offer === null) {
                return ['ok' => true, 'available' => false, 'installed' => $installed,
                    'message' => 'You are on the newest version'.($installed !== '' ? ', '.$installed : '').'.'];
            }
            if ($offer['manifest'] === null) {
                return ['ok' => true, 'available' => false, 'installed' => $installed, 'manual' => true,
                    'message' => 'Banimark '.$offer['version'].' is out, but it has to be installed by hand: composer require "banimark/banimark:^'.$offer['version'].'"'];
            }
            return ['ok' => true, 'available' => true, 'installed' => $installed, 'version' => $offer['version'],
                'test' => (bool) $offer['test'], 'notes' => mb_substr((string) $offer['notes'], 0, 600),
                'message' => 'Banimark '.$offer['version'].' is available.'];
        });
    }

    /** Step 2: download it and check it is ours. The licence key gates this. */
    public function fetch(Request $request)
    {
        return self::guard(function () use ($request) {
            if (!CoreUnavailable::keyMatches((string) $request->input('banimark_recover_key', ''))) {
                return ['ok' => false, 'error' => 'That is not this site\'s licence key. Nothing was downloaded.', 'bad_key' => true];
            }
            $got = CoreUnavailable::recovery()->fetch((string) $request->input('banimark_recover_version', ''));
            if (!$got['ok']) {
                return ['ok' => false, 'error' => $got['message']];
            }
            // the staged path stays server-side; the browser never names a directory
            $request->session()->put('banimark_recover_staged', ['path' => $got['staged'], 'version' => $got['version']]);
            return ['ok' => true, 'message' => $got['message'], 'version' => $got['version']];
        });
    }

    /** Step 3: swap it in, keeping a backup. Only what step 2 staged, in this session. */
    public function apply(Request $request)
    {
        return self::guard(function () use ($request) {
            $staged = (array) $request->session()->pull('banimark_recover_staged', []);
            if (($staged['path'] ?? '') === '') {
                return ['ok' => false, 'error' => 'Nothing is downloaded yet - check for the update again.'];
            }
            $done = CoreUnavailable::recovery()->apply((string) $staged['path'], (string) $staged['version']);
            if (!$done['ok']) {
                return ['ok' => false, 'error' => $done['message']];
            }
            logger()->info('Banimark updated from the error screen: '.$done['message']);
            \Banimark\Laravel\RouteCache::clearForUpdate();
            $request->session()->put('banimark_recover_applied', (string) $staged['version']);
            return ['ok' => true, 'message' => $done['message']];
        });
    }

    /**
     * Step 4: bring the database up to the new version. A NEW request, so the
     * code it runs is the version step 3 just put on disk - the same order a
     * manual update follows. Only right after an apply in this session.
     */
    public function database(Request $request)
    {
        return self::guard(function () use ($request) {
            $version = (string) $request->session()->pull('banimark_recover_applied', '');
            if ($version === '') {
                return ['ok' => false, 'error' => 'There is no fresh update to finish.'];
            }
            $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
            $current = \Banimark\Licensing\Master::PACKAGE_VERSION;
            \Banimark\Laravel\RouteCache::rebuildAfterUpdate();
            if (\Banimark\Storage\Schema::ensureCurrent($pdo, $current)) {
                return ['ok' => true, 'message' => 'Your database is up to date with '.$current.'.'];
            }
            $stored = self::setting('schema_version');
            return $stored === $current
                ? ['ok' => true, 'message' => 'Your database was already up to date.']
                : ['ok' => false, 'error' => 'The new version is installed, but the database did not change. The database user Banimark connects with probably cannot alter tables; ask your host for CREATE, ALTER and INDEX rights, then open the Updates page.'];
        });
    }

    /** recover.js as a same-origin file: customer CSPs block inline script. */
    public function script()
    {
        $file = dirname(__DIR__, 2).'/resources/design/recover.js';
        return response((string) @file_get_contents($file), is_file($file) ? 200 : 404, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{check: string, fetch: string, apply: string, database: string, script: string} */
    public static function ajaxUrls(): array
    {
        $base = self::url();
        $v = substr((string) @md5_file(dirname(__DIR__, 2).'/resources/design/recover.js'), 0, 10);
        return ['check' => $base.'/check', 'fetch' => $base.'/fetch', 'apply' => $base.'/apply',
            'database' => $base.'/database', 'script' => $base.'/recover.js?v='.$v];
    }

    /**
     * Every step answers JSON, whatever happens: recover.js reads `ok` and a
     * sentence, and a framework error page would leave it with neither.
     */
    private static function guard(\Closure $step)
    {
        try {
            $out = $step();
        } catch (\Throwable $e) {
            try {
                logger()->error('Banimark recover step failed: '.$e->getMessage(), ['exception' => $e]);
            } catch (\Throwable $ignore) {
            }
            $out = ['ok' => false, 'error' => 'Something went wrong on the server: '.$e->getMessage()];
        }
        return response()->json($out, 200, ['Cache-Control' => 'no-store']);
    }

    public static function url(): string
    {
        return '/'.\Banimark\Laravel\Urls::admin().'/recover';
    }

    /** The form's hidden fields: CSRF (the web group checks it) and where to return. */
    public static function hidden(string $back): array
    {
        $fields = [];
        try {
            $fields['_token'] = (string) csrf_token();
        } catch (\Throwable $e) {
            // no session (the throw came before StartSession): the post will be
            // refused as expired, and the page says so - nothing is installed
        }
        if ($back !== '') {
            $fields['back'] = $back;
        }
        return $fields;
    }

    /**
     * Only a path inside this site's admin: the value comes from a form, and an
     * open redirect on a page that asks for a licence key is a phishing kit.
     */
    public static function safeBack(string $back): string
    {
        $admin = '/'.\Banimark\Laravel\Urls::admin();
        $path = (string) parse_url($back, PHP_URL_PATH);
        $query = (string) parse_url($back, PHP_URL_QUERY);
        if ($back === '' || str_starts_with($back, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $back)
            || !($path === $admin || str_starts_with($path, $admin.'/'))
            || str_contains($path, '..') || $path === self::url()) {
            return '';
        }
        return $path.($query !== '' ? '?'.$query : '');
    }

    private static function setting(string $key): string
    {
        try {
            return (string) (\Illuminate\Support\Facades\DB::table('banimark_settings')->where('key', $key)->value('value') ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
