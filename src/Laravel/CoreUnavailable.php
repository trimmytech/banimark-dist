<?php

namespace Banimark\Laravel;

use Banimark\CoreHealth;
use Closure;
use Illuminate\Http\Request;

/**
 * Answers every Banimark route when the core cannot run on this server, and
 * never calls the next layer - so neither the controller nor the core behind
 * it is ever touched.
 *
 * It runs as GLOBAL middleware, ahead of routing. As route middleware it was
 * too late (measured on the pilot): the `web` group runs first, and its
 * SubstituteBindings inspects the controller method's parameter types -
 * `AgentAuth $auth` - which autoloads a core class, and a trial encode on a
 * loader-less PHP then prints its payload before this ever ran. Global also
 * means a host that ran `php artisan route:cache` is covered: cached routes
 * keep whatever middleware they were cached with.
 *
 * The routes themselves stay registered, with their names. The host app may
 * call route('banimark.widget') in its own layout; dropping the routes would
 * turn "Banimark is down" into "every page of the customer's site is down".
 *
 * Each caller gets an answer it can use: the widget and the Flutter SDK parse
 * JSON (a framework error page is a dead end for them), the widget script gets
 * a 503 the browser simply will not run, and a person gets the fix.
 */
final class CoreUnavailable
{
    public function handle(Request $request, Closure $next)
    {
        // Registered GLOBALLY when the core cannot run, so it sees every request
        // of the host app - and must leave everything that is not ours alone.
        $admin = \Banimark\Laravel\Urls::admin();
        $w = \Banimark\Laravel\Urls::widget();
        if (!$request->is($w, $w.'/*', $admin, $admin.'/*')) {
            return $next($request);
        }
        $problem = CoreHealth::problem(dirname(__DIR__));
        if ($problem === null) {
            return $next($request);   // fixed since boot - e.g. the loader was just installed
        }
        // the real reason, for whoever reads the logs
        logger()->error('Banimark core unavailable: '.$problem['reason'].' - '.$problem['message']);

        $path = '/'.ltrim($request->path(), '/');
        if (str_ends_with($path, '/widget.js')) {
            return response('/* Banimark is temporarily unavailable. */', 503, [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }
        $visitorApi = (bool) preg_match('~^/'.preg_quote(\Banimark\Laravel\Urls::widget(), '~').'/(chat|upload|file|widget/appearance)(/|$)~', $path);
        if ($visitorApi || $request->expectsJson()) {
            return response()->json(CoreHealth::forVisitor(), 503, ['Cache-Control' => 'no-store']);
        }
        $extra = !empty($problem['updatable']) ? self::updateSection($request) : '';
        return response(CoreHealth::page($problem, self::supportEmail(), $extra), 503, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Retry-After' => '300',
        ]);
    }

    /**
     * "Is there an update that fixes this?" - the updater that needs nothing
     * but readable code (Update\Recovery), never the core or a login.
     *
     * It CHECKS first and only then offers to install: no reinstall, no menu.
     * A newer version is installed with the site's LICENCE KEY (the page may
     * be public - the login can be what is broken); no newer version means the
     * owner is sent to support, since nothing here can fix it.
     *
     * Two pages carry it. The "core cannot start" page posts back to itself
     * (that middleware runs globally, ahead of session and CSRF). The admin
     * error screen posts to the recover route with a CSRF token, and with
     * $opts['ajax'] it also runs as live, ticked steps (resources/design/
     * recover.js) - the same experience as the changelog page. Without
     * JavaScript the same two steps are plain form posts.
     *
     * @param string $action  where the form posts; '' = this page
     * @param array<string,string> $hidden extra fields (the CSRF token, back)
     * @param string $back    a same-site admin URL to return to once installed
     * @param array{ajax?: array<string,string>, support?: string} $opts
     *        ajax: check/fetch/apply/database/script URLs; support: a mailto: URL
     */
    public static function updateSection(Request $request, string $action = '', array $hidden = [], string $back = '', array $opts = []): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $here = '/'.ltrim($request->path(), '/');
        $postTo = $e($action !== '' ? $action : $here);
        $fields = '';
        foreach ($hidden as $name => $value) {
            $fields .= '<input type="hidden" name="'.$e($name).'" value="'.$e($value).'">';
        }
        $checkButton = '<form method="post" action="'.$postTo.'" data-recover-check>'.$fields
            .'<button type="submit" name="banimark_recover_check" value="1">Check for an update</button></form>';
        $support = (string) ($opts['support'] ?? '');
        $supportLine = '<p>No update is available yet, so this has to be looked at by a person. '
            .($support !== '' ? 'Please email support - the details of the error go with it.</p><p><a class="btn" href="'.$e($support).'" data-recover-support>Email support</a></p>'
                : 'Please send the details on this page to whoever supplies your Banimark.</p>');

        $post = $request->isMethod('post');
        $wantsInstall = $post && $request->input('banimark_recover_version') !== null;
        $wantsCheck = $post && $request->input('banimark_recover_check') !== null;

        if ($wantsInstall) {
            $given = trim((string) $request->input('banimark_recover_key', ''));
            if (!self::keyMatches($given)) {
                $note = '<p class="bad"><b>That is not this site\'s licence key.</b> Nothing was installed.</p>';
            } else {
                $done = self::recovery()->install((string) $request->input('banimark_recover_version'));
                if ($done['ok']) {
                    logger()->info('Banimark updated from the recovery page: '.$done['message']);
                    $admin = $e('/'.\Banimark\Laravel\Urls::admin());
                    return '<div class="upd"><h2 class="good">Installed.</h2><p>'.$e($done['message']).'</p>'
                        .'<p>'.($back !== '' ? '<a class="btn" href="'.$e($back).'">Back to the page you were on</a> ' : '')
                        .'<a class="btn'.($back !== '' ? ' ghost' : '').'" href="'.$admin.'">Open Banimark</a></p></div>';
                }
                $note = '<p class="bad"><b>That did not install.</b> '.$e($done['message']).'</p>';
            }
        }

        if (!$wantsInstall && !$wantsCheck) {
            // the first view asks HQ nothing: an error page must not wait on
            // the network, and the owner decides when to look
            $ajax = (array) ($opts['ajax'] ?? []);
            $data = '';
            foreach (['check', 'fetch', 'apply', 'database'] as $k) {
                if (!empty($ajax[$k])) {
                    $data .= ' data-'.$k.'="'.$e($ajax[$k]).'"';
                }
            }
            if ($data !== '' && $support !== '') {
                $data .= ' data-support="'.$e($support).'"';
            }
            return '<div class="upd"'.($data !== '' ? ' data-recover'.$data : '').'><h2>Is there an update that fixes this?</h2>'
                .'<p>A newer version of Banimark often fixes errors like this one. Check, and install it from here if there is one.</p>'
                .$checkButton
                .(!empty($ajax['script']) ? '<script src="'.$e($ajax['script']).'" defer></script>' : '')
                .'</div>';
        }

        $recovery = self::recovery();
        $check = $recovery->check();
        if (!$check['reachable']) {
            return '<div class="upd"><h2>Is there an update that fixes this?</h2>'.($note ?? '')
                .'<p class="bad">Could not reach Banimark HQ to check. Make sure this server can reach the internet, then try again.</p>'
                .$checkButton.'</div>';
        }
        $offer = $check['offer'];
        if ($offer === null) {
            return '<div class="upd"><h2>You are on the newest version</h2>'.($note ?? '')
                .'<p>This site runs Banimark '.$e($recovery->installed() ?: '(unknown version)').'.</p>'.$supportLine.'</div>';
        }
        $title = 'Banimark '.$e($offer['version']).' is available'.($offer['test'] ? '<span class="pill">TEST BUILD</span>' : '');
        $notes = $offer['notes'] !== '' ? '<p class="muted" style="white-space:pre-wrap">'.$e(mb_substr($offer['notes'], 0, 600)).'</p>' : '';
        if ($offer['manifest'] === null) {
            return '<div class="upd"><h2>'.$title.'</h2>'.$notes.($note ?? '')
                .'<p>This version has to be installed by hand: <code>composer require "banimark/banimark:^'.$e($offer['version']).'"</code></p></div>';
        }
        return '<div class="upd"><h2>'.$title.'</h2>'.$notes.($note ?? '')
            .'<form method="post" action="'.$postTo.'">'.$fields
            .self::keyField()
            .'<button type="submit" name="banimark_recover_version" value="'.$e($offer['version']).'">Install '.$e($offer['version']).' now</button>'
            .'</form>'
            .'<p class="muted">Downloads the release from HQ, checks Banimark\'s signature, and swaps it in with a backup kept. Your data is not touched.</p></div>';
    }

    /** The licence-key input - the same markup for the form and for recover.js. */
    public static function keyField(): string
    {
        return '<label for="bmk"><b>Your licence key</b> <span class="muted">(from the License page or your purchase email - it proves you own this site)</span></label>'
            .'<input id="bmk" type="password" name="banimark_recover_key" autocomplete="off" placeholder="BM-XXXX-XXXX-XXXX-XXXX" required>';
    }

    /** Is $given this site's licence key? Constant-time; an unset key never matches. */
    public static function keyMatches(string $given): bool
    {
        $key = self::licenseKey();
        return $key !== '' && trim($given) !== '' && hash_equals($key, trim($given));
    }

    public static function recovery(): \Banimark\Update\Recovery
    {
        // a bound instance wins - how tests point it at a scratch folder and a
        // fake HQ instead of the real package and network
        if (app()->bound(\Banimark\Update\Recovery::class)) {
            return app(\Banimark\Update\Recovery::class);
        }
        return new \Banimark\Update\Recovery(dirname(__DIR__), self::licenseKey(), self::setting('hq_url')
            ?: (string) (config('banimark.license.hq_url') ?? env('BANIMARK_HQ_URL', '')));
    }

    /** The key the panel stored first, then config/env - the same order the panel uses. */
    private static function licenseKey(): string
    {
        return trim(self::setting('license_key') ?: (string) (config('banimark.license.key') ?? env('BANIMARK_LICENSE_KEY', '')));
    }

    private static function setting(string $key): string
    {
        try {
            return (string) (\Illuminate\Support\Facades\DB::table('banimark_settings')->where('key', $key)->value('value') ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** From HQ, when this install has ever reached it; never fatal. */
    private static function supportEmail(): string
    {
        return self::setting('support_email');
    }
}
