<?php

namespace Banimark\Laravel\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

/**
 * Turns a button's flash-and-redirect into a JSON answer when panel.js posted
 * the form (header X-Banimark-Form: 1), so the page can print the message at
 * the top and as a toast without a round trip that loses what the owner typed.
 *
 *   { ok: bool, message: string, redirect: string|null }
 *
 * The controllers stay exactly as they are - `back()->with('bm_ok', …)` - and
 * a browser without JavaScript gets the same redirect it always did. Which is
 * why this is a wrapper and not a second set of handlers: one truth per action.
 *
 * An error that would have redirected back to the SAME page (the usual
 * `back()`) is answered in place, and its flash is taken out of the session so
 * it does not surface on the next page the owner opens. An error that sends
 * the owner elsewhere (the licence gate bouncing a POST to the licence page)
 * keeps its flash: the page it lands on renders it at the top.
 *
 * Readable on purpose (not in the encoded core): it decides nothing about
 * licences, and a host may want to read what rewrites its responses.
 * HQ mounts the same class with its own flash keys.
 */
final class FormAnswer
{
    public const HEADER = 'X-Banimark-Form';
    public const PAGE_HEADER = 'X-Banimark-Page';

    public function handle(Request $request, Closure $next, string $okKey = 'bm_ok', string $errKey = 'bm_error')
    {
        $response = $next($request);
        if (!self::wants($request) || !$response instanceof RedirectResponse) {
            return $response;
        }
        $session = $request->hasSession() ? $request->session() : null;
        $ok = $session?->get($okKey);
        $err = $session?->get($errKey);
        if ($err === null && $session !== null) {
            $bag = $session->get('errors');
            if ($bag instanceof ViewErrorBag && $bag->any()) {
                $err = $bag->first();
            }
        }
        $isOk = $err === null;
        $target = $response->getTargetUrl();
        $stay = !$isOk && self::samePage($target, (string) $request->header(self::PAGE_HEADER, ''));
        if ($stay && $session !== null) {
            // answered in place: the flash must not haunt the next page
            $session->forget([$errKey, 'errors', '_old_input']);
        }
        return new JsonResponse([
            'ok' => $isOk,
            'message' => (string) ($isOk ? ($ok ?? '') : $err),
            'redirect' => $stay ? null : $target,
        ], $isOk ? 200 : 422);
    }

    /** panel.js marks its posts; anything else is a browser and keeps the redirect. */
    public static function wants(Request $request): bool
    {
        return $request->isMethod('POST') && (string) $request->header(self::HEADER, '') === '1';
    }

    /** Same page = same URL but for the fragment and a trailing slash. */
    public static function samePage(string $a, string $b): string|bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        $norm = static fn (string $u): string => rtrim((string) strtok($u, '#'), '/');
        return $norm($a) === $norm($b);
    }
}
