<?php

namespace Banimark\Standalone;

use Banimark\Ui\Layout;

/**
 * Page chrome for the standalone runtime. The look lives in the shared design
 * system (resources/design/) so the framework-free panel, the Laravel panel
 * and HQ stay visually identical - only the templating differs.
 */
class Html
{
    /** The app shell: sidebar + sticky topbar + content. */
    public static function page(string $title, string $content, string $nav = '', string $sub = '', string $actions = ''): string
    {
        if ($nav === '') {
            return self::bare($title, $content);
        }
        return '<!doctype html><html lang="en"><head>'.Layout::head('Banimark - '.$title).'</head><body>'
            .'<div class="bm-app">'
            .'<aside class="bm-side">'
            .'<div class="bm-brand">'.Layout::logo('Banimark', 'Support desk').'</div>'
            .'<nav class="bm-nav">'.$nav.'</nav>'
            .'</aside><div class="bm-scrim"></div>'
            .'<main class="bm-main">'
            .'<header class="bm-top">'.Layout::burger()
            .'<div><h1>'.self::e($title).'</h1>'.($sub !== '' ? '<div class="sub">'.self::e($sub).'</div>' : '').'</div>'
            .'<div class="spacer"></div>'.$actions.Layout::soundButton().Layout::themeButton()
            .'</header>'
            .'<div class="bm-wrap">'.$content.'</div>'
            .'</main></div>'.Layout::scripts().'</body></html>';
    }

    /** Centred card with no shell - the installer and the sign-in screen. */
    public static function bare(string $title, string $content): string
    {
        return '<!doctype html><html lang="en"><head>'.Layout::head('Banimark - '.$title).'</head><body>'
            .'<div class="bm-auth"><div class="box" style="max-width:640px">'.$content.'</div></div>'
            .Layout::scripts().'</body></html>';
    }

    /** The sign-in screen. */
    public static function auth(string $action, string $error = '', string $notice = ''): string
    {
        return self::bare('Sign in', '<div class="bm-card" style="max-width:372px;margin:0 auto">'
            .'<div class="head"><div class="bm-logo"></div><h2>Welcome back</h2>'
            .'<p class="muted">Sign in to your Banimark support desk.</p></div>'
            .($notice !== '' ? '<div class="flash-ok"><span>'.self::e($notice).'</span></div>' : '')
            .($error !== '' ? '<div class="flash-err"><span>'.self::e($error).'</span></div>' : '')
            .'<form method="post" action="'.self::e($action).'">'
            .'<label>Email</label><input type="text" name="email" autofocus autocomplete="username" placeholder="you@company.com">'
            .'<label>Password</label><input type="password" name="password" autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">'
            .'<button type="submit" style="width:100%;margin-top:18px">Sign in</button></form></div>');
    }

    /** An invited colleague sets their own password. $agent null = link expired or used. */
    public static function activate(string $action, string $loginUrl, ?array $agent, string $error = ''): string
    {
        $head = $agent
            ? '<h2>Welcome, '.self::e($agent['name']).'</h2><p class="muted">Choose a password to activate your support desk account ('.self::e($agent['email']).').</p>'
            : '<h2>This link has expired</h2><p class="muted">Invitation links work for 7 days and once only. Ask an owner to resend yours.</p>';
        $body = $agent
            ? '<form method="post" action="'.self::e($action).'">'
                .'<label>Your name</label><input type="text" name="name" value="'.self::e($agent['name']).'" autocomplete="name">'
                .'<label>Password <span class="muted">(at least 8 characters)</span></label><input type="password" name="password" autocomplete="new-password" autofocus>'
                .'<label>Password again</label><input type="password" name="password_confirmation" autocomplete="new-password">'
                .'<button type="submit" style="width:100%;margin-top:18px">Activate &amp; sign in</button></form>'
            : '<a class="btn-ghost" href="'.self::e($loginUrl).'" style="display:block;text-align:center">Back to sign in</a>';
        return self::bare('Activate your account', '<div class="bm-card" style="max-width:372px;margin:0 auto">'
            .'<div class="head"><div class="bm-logo"></div>'.$head.'</div>'
            .($error !== '' ? '<div class="flash-err"><span>'.self::e($error).'</span></div>' : '').$body.'</div>');
    }

    /** Second sign-in step: the authenticator code. */
    public static function totp(string $action, string $logout, string $error = ''): string
    {
        return self::bare('Verify', '<div class="bm-card" style="max-width:372px;margin:0 auto">'
            .'<div class="head"><div class="bm-logo"></div><h2>One more step</h2>'
            .'<p class="muted">Enter the 6-digit code from your authenticator app.</p></div>'
            .($error !== '' ? '<div class="flash-err"><span>'.self::e($error).'</span></div>' : '')
            .'<form method="post" action="'.self::e($action).'">'
            .'<label>Code</label><input type="text" name="code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" autofocus placeholder="123 456" style="font-size:22px;letter-spacing:.3em;text-align:center">'
            .'<button type="submit" style="width:100%;margin-top:18px">Verify</button></form>'
            .'<a class="btn-ghost" href="'.self::e($logout).'" style="display:block;text-align:center;margin-top:10px">Use a different account</a></div>'
            .'<p class="muted" style="text-align:center">Lost your device? An owner can reset your 2FA from Staff.</p>');
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
