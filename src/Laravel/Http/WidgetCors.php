<?php

namespace Banimark\Laravel\Http;

use Banimark\Http\Cors;
use Closure;
use Illuminate\Http\Request;

/**
 * The CORS grant on the visitor routes (routes/widget.php), for the origins
 * the owner listed on the Widget page - see Banimark\Http\Cors for the rule.
 * The browser's OPTIONS preflight is answered here, before any controller.
 *
 * Readable on purpose, like FormAnswer: it decides nothing about licences and
 * an integrator has to be able to read why the browser blocked them.
 */
final class WidgetCors
{
    public function handle(Request $request, Closure $next)
    {
        $origin = (string) $request->headers->get('Origin', '');
        $preflight = $request->isMethod('OPTIONS');
        $headers = Cors::headers(\Banimark\Laravel\BanimarkServiceProvider::settings(), $origin, $preflight, $request->getSchemeAndHttpHost());
        $response = $preflight ? response('', 204) : $next($request);
        foreach ($headers as $h => $v) {
            $response->headers->set($h, $v);
        }
        return $response;
    }
}
