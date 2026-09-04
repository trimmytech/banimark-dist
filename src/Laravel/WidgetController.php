<?php

namespace Banimark\Laravel;

use Banimark\Http\ChatEndpoint;
use Banimark\Http\WidgetConfig;
use Illuminate\Http\Request;

/**
 * Thin Laravel skin over the framework-agnostic ChatEndpoint. The host binds
 * Banimark\Http\ChatEndpoint in the container (the service provider wires a
 * default from config once the DB store lands in step 5).
 */
class WidgetController
{
    /** POST /banimark/chat */
    public function chat(Request $request, ChatEndpoint $endpoint)
    {
        $out = $endpoint->handle([
            'message' => (string) $request->input('message', ''),
            'session_id' => (string) $request->input('session_id', ''),
            'token' => (string) $request->input('token', ''),
            'visitor' => (array) $request->input('visitor', []),
        ]);

        return response()->json($out, $out['ok'] ? 200 : 422);
    }

    /** GET /banimark/chat/poll - agent replies while a human owns the chat */
    public function poll(Request $request, \Banimark\Http\PollEndpoint $endpoint)
    {
        return response()->json($endpoint->handle([
            'session_id' => (string) $request->query('session_id', ''),
            'after' => (int) $request->query('after', 0),
            'token' => (string) $request->query('token', ''),
        ]));
    }

    /** GET /banimark/chat/history - replay the chat when a visitor returns */
    public function history(Request $request, \Banimark\Http\HistoryEndpoint $endpoint)
    {
        return response()->json($endpoint->handle([
            'session_id' => (string) $request->query('session_id', ''),
            'token' => (string) $request->query('token', ''),
        ]));
    }

    /** GET /banimark/widget.js - the widget with server-side config injected */
    public function script()
    {
        $settings = (array) config('banimark.widget', []);
        try {
            // panel-saved settings win over config
            $settings = array_merge($settings, \Illuminate\Support\Facades\DB::table('banimark_settings')->pluck('value', 'key')->all());
        } catch (\Throwable $e) {
            // not migrated yet - config only
        }
        // allow-list: this script is public, and the settings table holds secrets
        $cfg = WidgetConfig::build($settings, url('/banimark/chat'));
        $js = 'window.__BANIMARK_CFG = '.json_encode($cfg, JSON_UNESCAPED_SLASHES).";\n"
            .file_get_contents(__DIR__.'/../../resources/widget/banimark-widget.js');

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
