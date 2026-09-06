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
    public function chat(Request $request, ChatEndpoint $endpoint, \Banimark\Http\RateLimiter $limiter)
    {
        // flood rules first: a script hammering this route costs no storage and no model call
        $settings = \Banimark\Laravel\BanimarkServiceProvider::settings();
        $sid = (string) $request->input('session_id', '');
        $blocked = \Banimark\Http\Flood::check($limiter, $settings, (string) $request->ip(), $sid, 'chat')
            ?? ($sid === '' ? \Banimark\Http\Flood::check($limiter, $settings, (string) $request->ip(), '', 'session') : null);
        if ($blocked !== null) {
            return response()->json(['ok' => false, 'error' => $blocked['error'], 'session_id' => $sid, 'reply' => ''], 429)
                ->header('Retry-After', (string) $blocked['retry_after']);
        }
        $out = $endpoint->handle([
            'message' => (string) $request->input('message', ''),
            'session_id' => (string) $request->input('session_id', ''),
            'token' => (string) $request->input('token', ''),
            'visitor' => (array) $request->input('visitor', []),
            'attachments' => (array) $request->input('attachments', []),
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
            'typing' => (string) $request->query('typing', ''), // visitor is typing - shown to staff
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

    /** POST /banimark/upload - a visitor sends a file */
    public function upload(Request $request, \Banimark\Http\UploadEndpoint $endpoint, \Banimark\Http\RateLimiter $limiter)
    {
        $blocked = \Banimark\Http\Flood::check($limiter, \Banimark\Laravel\BanimarkServiceProvider::settings(), (string) $request->ip(), (string) $request->input('session_id', ''), 'upload');
        if ($blocked !== null) {
            return response()->json(['ok' => false, 'error' => $blocked['error']], 429)->header('Retry-After', (string) $blocked['retry_after']);
        }
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'That upload did not arrive in one piece.'], 422);
        }
        $out = $endpoint->handle([
            'session_id' => (string) $request->input('session_id', ''),
            'token' => (string) $request->input('token', ''),
            'filename' => (string) $file->getClientOriginalName(),
            'bytes' => (string) @file_get_contents($file->getRealPath()),
            'size' => (int) $file->getSize(),
        ]);
        if (!empty($out['detail'])) {
            // the visitor gets a plain apology; the owner needs the real reason
            \Illuminate\Support\Facades\Log::warning('Banimark upload failed: '.$out['detail']);
            unset($out['detail']);
        }
        return response()->json($out, $out['ok'] ? 200 : 422);
    }

    /** GET /banimark/file/{token} - stream a shared file (or bounce to object storage) */
    public function file(string $token, Request $request, \Banimark\Http\FileEndpoint $endpoint)
    {
        $out = $endpoint->handle($token, $request->boolean('download'));
        if (!$out['ok']) {
            abort(404);
        }
        if (isset($out['redirect'])) {
            return redirect()->away($out['redirect']);
        }
        return response($out['bytes'], 200, [
            'Content-Type' => $out['mime'] ?: 'application/octet-stream',
            // never let a shared file execute in the browser
            'Content-Disposition' => ($out['download'] ? 'attachment' : 'inline').'; filename="'.addslashes($out['name']).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /**
     * GET /banimark/chat-page - the chat as a stand-alone page, for links in
     * emails, signatures, QR codes... anywhere the widget cannot be embedded.
     * No inline script (host CSPs): the widget reads its mode from data-mode
     * and an optional short-lived identity token from ?t=.
     */
    public function page(Request $request)
    {
        $token = preg_replace('/[^A-Za-z0-9._~-]/', '', (string) $request->query('t', ''));
        $cfg = WidgetConfig::build($this->settings(), url('/banimark/chat'));
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.htmlspecialchars((string) $cfg['title'], ENT_QUOTES).'</title>'
            .'<style>html,body{margin:0;height:100%;background:'.($cfg['theme'] === 'dark' ? '#101015' : '#f7f7fb').'}</style></head><body>'
            .'<script src="'.htmlspecialchars(route('banimark.widget'), ENT_QUOTES).'" defer data-mode="page"'
            .($token !== '' ? ' data-token="'.htmlspecialchars($token, ENT_QUOTES).'"' : '').'></script>'
            .'</body></html>';
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Frame-Options' => 'SAMEORIGIN']);
    }

    /** GET /banimark/widget/appearance - the public widget settings as JSON (the Flutter SDK reads these). */
    public function appearance()
    {
        $cfg = WidgetConfig::build($this->settings(), url('/banimark/chat'));
        unset($cfg['endpoint']);
        return response()->json($cfg)->header('Cache-Control', 'public, max-age=300');
    }

    private function settings(): array
    {
        $settings = (array) config('banimark.widget', []);
        try {
            $settings = array_merge($settings, \Illuminate\Support\Facades\DB::table('banimark_settings')->pluck('value', 'key')->all());
        } catch (\Throwable $e) {
        }
        return $settings;
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
            // the emoji picker is shared with the panel; the widget captures it
            // and removes the global, so the host page is left exactly as it was
            .file_get_contents(__DIR__.'/../../resources/design/emoji.js')."\n"
            .file_get_contents(__DIR__.'/../../resources/design/markdown.js')."\n"
            .file_get_contents(__DIR__.'/../../resources/widget/banimark-widget.js');

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
