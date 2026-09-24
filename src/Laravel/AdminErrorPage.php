<?php

namespace Banimark\Laravel;

use Banimark\CoreHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders our own screen for an unhandled exception on a Banimark ADMIN page:
 * what broke, a check-for-update button, a one-click email to whoever supplies
 * Banimark - instead of a bare host 500, or worse.
 *
 * It is called from PanelController::callAction() - the ONLY place in a Laravel
 * request that runs before the framework's own catch. Illuminate\Routing\Pipeline
 * wraps every middleware AND the controller call in a try/catch of its own, hands
 * the exception to the host's ExceptionHandler and turns it into a response right
 * there. So a try/catch in an outer middleware never sees the exception; this
 * class used to be exactly such a middleware, and on the AidaSuite pilot the
 * host's handler (a bare RuntimeException is a "business rule" there: flash +
 * redirect back) got it first, the redirect hit the same page, it threw again -
 * a login/dashboard redirect loop and no error screen at all. Measured on a
 * scratch host with the same callback: the middleware's catch fired zero times.
 *
 * The provider also registers a `renderable` that delegates here, for exceptions
 * thrown OUTSIDE the controller (a middleware, a route binding). That one runs
 * after the host's own render callbacks, so it only wins when the host does not
 * claim the exception - the controller path is the one that always does.
 *
 * Admin/staff-facing only. The widget and visitor API are separate routes and
 * answer their own JSON (WidgetController).
 */
final class AdminErrorPage
{
    /**
     * Every optional detail (log line, support address, update link) is
     * best-effort: an error page that throws is worse than a plain one.
     */
    public static function render(Request $request, \Throwable $e): Response
    {
        try {
            logger()->error('Banimark admin error: '.$e->getMessage(), ['exception' => $e]);
        } catch (\Throwable $ignore) {
        }

        $headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'];

        // the core itself refused to load mid-request - the fix-the-server page
        if (CoreHealth::isCoreFailure($e)) {
            if ($request->expectsJson()) {
                return new JsonResponse(CoreHealth::forVisitor(), 503);
            }
            return new \Illuminate\Http\Response(CoreHealth::page([
                'reason' => 'refused',
                'title' => 'Banimark could not start',
                'message' => "The ionCube Loader is installed, but it could not run this copy of Banimark's core. The build may have expired, be damaged, or not match this PHP version.",
                'steps' => [
                    'Install the latest version, or reinstall it: composer reinstall banimark/banimark',
                    'Make sure the ionCube Loader matches this PHP version: '.CoreHealth::LOADERS_URL,
                    'Restart PHP afterwards - php-fpm, Apache, MAMP, or `php artisan serve`.',
                ],
            ], self::setting('support_email'),
                self::updateBox($request)),
                503, $headers);
        }

        if ($request->expectsJson()) {
            return new JsonResponse(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
        }
        $ctx = [
            'support_email' => self::setting('support_email'),
            'update_url' => self::updateUrl(),
            'version' => (string) (CoreHealth::build(dirname(__DIR__))['version'] ?? ''),
            'site' => $request->getHost(),
            'url' => $request->fullUrl(),
        ];
        // the updater IN the page: when every admin page throws, the changelog
        // page is one of them. It checks, installs if there is an update, and
        // otherwise hands over to support with this error's details.
        $ctx['update_html'] = self::updateBox($request, CoreHealth::errorMailto($e, $ctx));
        return new \Illuminate\Http\Response(CoreHealth::exceptionPage($e, $ctx), 500, $headers);
    }

    /**
     * Exceptions the framework must keep: abort(404) in an action, a thrown
     * redirect, a failed validation. Turning those into an error screen would
     * break the panel's own flow.
     */
    public static function isFrameworkFlow(\Throwable $e): bool
    {
        return $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            || $e instanceof \Illuminate\Http\Exceptions\HttpResponseException
            || $e instanceof \Illuminate\Validation\ValidationException
            || $e instanceof \Illuminate\Auth\AuthenticationException;
    }

    /**
     * The update box, posting to the recover route (outside the admin group).
     * Best-effort: asking HQ can fail, and the error screen must still render.
     */
    private static function updateBox(Request $request, string $supportMailto = ''): string
    {
        try {
            $back = $request->isMethod('get') ? RecoverPage::safeBack($request->getRequestUri()) : '';
            // a GET: this page's own request is not the updater's, so the box
            // must not read it as "check" or "install" (a throwing POST could
            // carry anything) - render it as a fresh first view
            $fresh = \Illuminate\Http\Request::create($request->getRequestUri(), 'GET');
            return CoreUnavailable::updateSection($fresh, RecoverPage::url(), RecoverPage::hidden($back), $back, [
                'ajax' => RecoverPage::ajaxUrls(),
                'support' => $supportMailto,
            ]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** A Banimark setting, best-effort; never fatal. */
    private static function setting(string $key): string
    {
        try {
            return (string) (\Illuminate\Support\Facades\DB::table('banimark_settings')->where('key', $key)->value('value') ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function updateUrl(): string
    {
        try {
            return \Illuminate\Support\Facades\Route::has('banimark.admin.changelog') ? route('banimark.admin.changelog') : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
