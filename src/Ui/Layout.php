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
        return self::$css ??= Assets::content('panel.css');
    }

    public static function js(): string
    {
        return self::$js ??= Assets::content('panel.js');
    }

    /**
     * URL of a panel asset, when the runtime has told us where it serves them
     * (configure(['assets' => ...])). Null = not configured (the standalone
     * installer runs before any route exists) - callers fall back to inline.
     */
    public static function assetUrl(string $name): ?string
    {
        $base = (string) (self::$config['assets'] ?? '');
        return $base === '' ? null : rtrim($base, '/').'/'.$name.'?v='.Assets::version();
    }

    /**
     * The <head>: stylesheet + the theme pre-paint guard. External files when
     * an assets URL is configured (CSP-safe); inline only as the fallback.
     */
    public static function head(string $title): string
    {
        $out = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.self::e($title).'</title>';
        if (($css = self::assetUrl('panel.css')) !== null) {
            return $out.'<link rel="stylesheet" href="'.self::e($css).'">'
                .'<script src="'.self::e((string) self::assetUrl('theme.js')).'"></script>';
        }
        return $out.'<script>'.Assets::content('theme.js').'</script><style>'.self::css().'</style>';
    }

    /** @var array<string, mixed> runtime facts the panel script needs (event feed url, current user) */
    private static array $config = [];

    /** Set once per request by the runtime that knows its URLs; emitted by scripts(). */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * Runtime facts travel in a JSON data block - CSP never executes those, so
     * no nonce is needed - and the behaviour is an external file.
     */
    public static function scripts(): string
    {
        $config = '<script type="application/json" id="bm-config">'
            .json_encode(self::$config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS).'</script>';
        if (($js = self::assetUrl('panel.js')) !== null) {
            return $config.'<script src="'.self::e($js).'" defer></script>';
        }
        return $config.'<script>'.self::js().'</script>';
    }

    /** Live staff conversation view, on that page only. */
    public static function chatScript(): string
    {
        return self::script('chat.js');
    }

    private static function script(string $name): string
    {
        if (($url = self::assetUrl($name)) !== null) {
            return '<script src="'.self::e($url).'" defer></script>';
        }
        return '<script>'.Assets::content($name).'</script>';
    }

    /** Header button: staff can mute/unmute the new-message chime (remembered per browser). */
    public static function soundButton(): string
    {
        return '<button type="button" class="btn-ghost btn-icon" data-sound-toggle title="New message sound">'.Icons::get('bell', 16).'</button>';
    }

    /** The visual Tool Builder, on the tools page only. */
    public static function toolBuilderScript(): string
    {
        return self::script('toolbuilder.js');
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
