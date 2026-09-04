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
            .'<div class="spacer"></div>'.$actions.Layout::themeButton()
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
    public static function auth(string $action, string $error = ''): string
    {
        return self::bare('Sign in', '<div class="bm-card" style="max-width:372px;margin:0 auto">'
            .'<div class="head"><div class="bm-logo"></div><h2>Welcome back</h2>'
            .'<p class="muted">Sign in to your Banimark support desk.</p></div>'
            .($error !== '' ? '<div class="flash-err"><span>'.self::e($error).'</span></div>' : '')
            .'<form method="post" action="'.self::e($action).'">'
            .'<label>Email</label><input type="text" name="email" autofocus autocomplete="username" placeholder="you@company.com">'
            .'<label>Password</label><input type="password" name="password" autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">'
            .'<button type="submit" style="width:100%;margin-top:18px">Sign in</button></form></div>');
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
