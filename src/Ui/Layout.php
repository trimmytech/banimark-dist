<?php

namespace Banimark\Ui;

/**
 * The shared app shell. The stylesheet and behaviour script live in
 * resources/design/ and are INLINED rather than linked: the panel must render
 * inside any host app, on any path, offline, with no asset pipeline and no
 * extra HTTP round trip.
 */
class Layout
{
    private static ?string $css = null;
    private static ?string $js = null;

    public static function css(): string
    {
        return self::$css ??= (string) @file_get_contents(__DIR__.'/../../resources/design/panel.css');
    }

    public static function js(): string
    {
        return self::$js ??= (string) @file_get_contents(__DIR__.'/../../resources/design/panel.js');
    }

    /** <style> + the theme pre-paint guard, for a layout's <head>. */
    public static function head(string $title): string
    {
        return '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.self::e($title).'</title>'
            // set the theme before first paint so a dark-mode reload never flashes white
            .'<script>try{var t=localStorage.getItem("bm-theme");if(t)document.documentElement.setAttribute("data-theme",t);}catch(e){}</script>'
            .'<style>'.self::css().'</style>';
    }

    public static function scripts(): string
    {
        return '<script>'.self::js().'</script>';
    }

    /** Product mark + wordmark used in the sidebar and on the auth screen. */
    public static function logo(string $name = 'Banimark', string $sub = ''): string
    {
        return '<div class="bm-logo"></div><div><b>'.self::e($name).'</b>'
            .($sub !== '' ? '<span>'.self::e($sub).'</span>' : '').'</div>';
    }

    /**
     * One sidebar link.
     * @param array{href: string, icon: string, label: string, on?: bool} $item
     */
    public static function navLink(array $item): string
    {
        return '<a href="'.self::e($item['href']).'"'.(!empty($item['on']) ? ' class="on"' : '').'>'
            .Icons::get($item['icon']).'<span>'.self::e($item['label']).'</span></a>';
    }

    public static function themeButton(): string
    {
        return '<button type="button" class="btn2 btn-icon theme-btn" data-theme-toggle title="Toggle theme" aria-label="Toggle theme">'
            .'<span class="sun">'.Icons::get('sun', 16).'</span><span class="moon">'.Icons::get('moon', 16).'</span></button>';
    }

    public static function burger(): string
    {
        return '<button type="button" class="btn2 btn-icon bm-burger" data-nav-toggle aria-label="Menu">'.Icons::get('menu', 16).'</button>';
    }

    /** A dashboard stat tile. $delta is a signed percentage, or null when there is no baseline. */
    public static function stat(string $label, string $value, string $icon = '', ?int $delta = null, string $foot = '', string $spark = ''): string
    {
        $d = '';
        if ($delta !== null) {
            $cls = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
            $arrow = $delta > 0 ? '&uarr;' : ($delta < 0 ? '&darr;' : '&rarr;');
            $d = '<span class="delta '.$cls.'">'.$arrow.' '.abs($delta).'%</span>';
        }
        return '<div class="bm-card stat">'
            .'<span class="k">'.($icon !== '' ? Icons::get($icon, 14) : '').self::e($label).'</span>'
            .'<span class="v">'.self::e($value).'</span>'
            .'<span class="foot">'.$d.($foot !== '' ? '<span>'.self::e($foot).'</span>' : '').'</span>'
            .($spark !== '' ? '<div style="margin-top:2px">'.$spark.'</div>' : '')
            .'</div>';
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
