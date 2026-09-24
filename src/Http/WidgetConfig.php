<?php

namespace Banimark\Http;

/**
 * What the widget is allowed to know.
 *
 * The settings table holds secrets - the identity HMAC secret, SMTP
 * credentials, the licence key - and the widget script is public to every
 * visitor. So the config is built from an EXPLICIT ALLOW-LIST: a new setting
 * is private until someone deliberately adds it here. (A merge of "all
 * settings minus a couple" leaked the identity secret, which is the key that
 * signs VisitorTokens - with it, a visitor could forge any identity and read
 * rows scoped to :_key placeholders.)
 */
class WidgetConfig
{
    /** Only these ever reach the browser. */
    public const PUBLIC_KEYS = [
        'color',
        'position',
        'title',
        'greeting',
        'poll_seconds',
        'guest_mode',
        'offline_note',
        'theme',
        'guest_intro',
        // whitelabel. Gated where it is SAVED, not here: the widget must render
        // the same tomorrow whatever the licence says, so a lapsed or
        // unreachable licence can never put our name back on a customer's site.
        'hide_brand',
        // the look (2026-09): header status line, launcher, corners, density,
        // sound, when the greeting peeks out, and which pages carry the widget
        'status_line',
        'launcher_icon',
        'launcher_label',
        'corner',
        'density',
        'sound',
        'auto_open',
        'auto_open_after',
        // unread badge while the chat is closed: how often to look, and how long
        // a launcher the visitor dismissed stays away (0 = until their next visit)
        'poll_idle_seconds',
        'launcher_reappear_minutes',
    ];

    /** The choices behind each select; anything else falls back to the default. */
    public const LAUNCHER_ICONS = ['chat' => 'Speech bubble', 'help' => 'Question mark', 'headset' => 'Headset', 'sparkle' => 'Sparkle'];
    public const CORNERS = ['rounded' => 'Rounded', 'soft' => 'Soft', 'square' => 'Square'];
    public const DENSITIES = ['comfortable' => 'Comfortable', 'compact' => 'Compact'];
    public const AUTO_OPEN = ['teaser' => 'Show the welcome message as a bubble', 'open' => 'Open the chat by itself', 'off' => 'Stay closed until clicked'];
    /** seconds; 0 = straight away (the teaser's old fixed 1.4 s) */
    public const AUTO_OPEN_AFTER = [0 => 'Straight away', 5 => 'After 5 seconds', 10 => 'After 10 seconds', 20 => 'After 20 seconds', 30 => 'After 30 seconds', 60 => 'After a minute'];
    /** Page rules: at most this many lines each, and each line a path. */
    public const MAX_PAGE_RULES = 20;

    /** Which details a guest may be asked for, and how insistently. */
    public const GUEST_FIELDS = [
        'name' => ['label' => 'Your name', 'type' => 'text', 'autocomplete' => 'name'],
        'email' => ['label' => 'you@example.com', 'type' => 'email', 'autocomplete' => 'email'],
        'phone' => ['label' => 'Phone number', 'type' => 'tel', 'autocomplete' => 'tel'],
    ];

    public const FIELD_MODES = ['off' => 'Do not ask', 'optional' => 'Ask, but let them skip it', 'required' => 'Must be filled in'];

    /** Not settings the owner types - derived, so the widget can hide the clip,
     *  say when a person is next around, and build the first-run screen. */
    public const DERIVED = ['enabled', 'files', 'away_note', 'guest_fields', 'starters', 'logo_url', 'show_on', 'hide_on', 'auto_open_pages'];

    public const DEFAULTS = [
        'color' => '#6F04D9',
        'position' => 'right',
        'title' => 'Support',
        'greeting' => 'Hi! How can we help you today?',
        'poll_seconds' => 10,
        'guest_mode' => 'off',
        'offline_note' => '',
        'theme' => 'auto', // auto = follow the visitor's OS; light / dark force it
        'guest_intro' => 'Tell us where to reach you and we can follow up even if you close this tab.',
        'hide_brand' => '0',
        'status_line' => '',
        'launcher_icon' => 'chat',
        'launcher_label' => '',
        'corner' => 'rounded',
        'density' => 'comfortable',
        'sound' => '1',
        'auto_open' => 'teaser',
        'auto_open_after' => 0,
        'poll_idle_seconds' => 30,
        'launcher_reappear_minutes' => 10,
    ];

    /**
     * The fields a guest is asked for, in order, already resolved so the widget
     * and the Flutter SDK render exactly the same form.
     *
     * @return array<int, array{key:string, label:string, type:string, required:bool, autocomplete:string}>
     */
    public static function guestFields(array $settings): array
    {
        $out = [];
        foreach (self::GUEST_FIELDS as $key => $meta) {
            $mode = (string) ($settings['guest_ask_'.$key] ?? '');
            // a value we do not recognise means the default, never "drop the
            // field" - only an explicit "off" hides one
            if (!isset(self::FIELD_MODES[$mode])) {
                $mode = self::defaultMode($key);
            }
            if ($mode === 'off') {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => trim((string) ($settings['guest_label_'.$key] ?? '')) ?: $meta['label'],
                'type' => $meta['type'],
                'required' => $mode === 'required',
                'autocomplete' => $meta['autocomplete'],
            ];
        }
        return $out;
    }

    /** The default for an install that has never touched these settings. */
    private static function defaultMode(string $key): string
    {
        return $key === 'phone' ? 'off' : 'optional';
    }

    /** Tappable openers - "commonly asked" phrased the way a visitor would. */
    public static function starters(array $settings): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', (string) ($settings['starters'] ?? '')) ?: [] as $line) {
            $line = trim(strip_tags($line));
            if ($line !== '') {
                $out[] = mb_substr($line, 0, 80);
            }
        }
        return array_slice($out, 0, 6); // more than six is a menu, not a nudge
    }

    /**
     * Page rules, one path per line: "/checkout", "/blog/*", "/". Anything that
     * is not a path is dropped rather than guessed at - a stray full URL would
     * otherwise match nothing and hide the widget everywhere a "show only on"
     * list is set.
     *
     * @return string[]
     */
    public static function pageRules(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '/' || strlen($line) > 120 || !preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#', $line)) {
                continue;
            }
            $out[] = $line;
        }
        return array_slice(array_values(array_unique($out)), 0, self::MAX_PAGE_RULES);
    }

    /**
     * @param array $settings the settings table (and/or config), unfiltered
     * @return array safe to embed in public JavaScript
     */
    public static function build(array $settings, string $endpoint): array
    {
        $cfg = self::DEFAULTS;
        foreach (self::PUBLIC_KEYS as $k) {
            if (array_key_exists($k, $settings) && $settings[$k] !== null && $settings[$k] !== '') {
                $cfg[$k] = $settings[$k];
            }
        }
        // clamp: a bad value here becomes a request storm on the host's server
        $cfg['poll_seconds'] = max(3, min(600, (int) $cfg['poll_seconds']));
        $cfg['poll_idle_seconds'] = max(10, min(600, (int) $cfg['poll_idle_seconds']));
        $cfg['launcher_reappear_minutes'] = max(0, min(1440, (int) $cfg['launcher_reappear_minutes']));
        $cfg['position'] = $cfg['position'] === 'left' ? 'left' : 'right';
        $cfg['guest_mode'] = in_array($cfg['guest_mode'], ['off', 'optional', 'required'], true) ? $cfg['guest_mode'] : 'off';
        $cfg['theme'] = in_array($cfg['theme'], ['auto', 'light', 'dark'], true) ? $cfg['theme'] : 'auto';
        $cfg['hide_brand'] = in_array($cfg['hide_brand'], ['1', 1, true, 'true'], true);
        $pick = fn (string $k, array $allowed) => isset($allowed[(string) $cfg[$k]]) ? (string) $cfg[$k] : self::DEFAULTS[$k];
        $cfg['launcher_icon'] = $pick('launcher_icon', self::LAUNCHER_ICONS);
        $cfg['corner'] = $pick('corner', self::CORNERS);
        $cfg['density'] = $pick('density', self::DENSITIES);
        $cfg['auto_open'] = $pick('auto_open', self::AUTO_OPEN);
        $cfg['auto_open_after'] = max(0, min(120, (int) $cfg['auto_open_after']));
        $cfg['sound'] = !in_array($cfg['sound'], ['0', 0, false, 'false'], true);
        $cfg['status_line'] = mb_substr(trim(strip_tags((string) $cfg['status_line'])), 0, 80);
        $cfg['launcher_label'] = mb_substr(trim(strip_tags((string) $cfg['launcher_label'])), 0, 30);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $cfg['color'])) {
            $cfg['color'] = self::DEFAULTS['color'];
        }
        $cfg['endpoint'] = $endpoint;
        // never-activated install (no trial, no key) = the chat is not live yet.
        // Crypto-free (Master::widgetActivated) because this is the visitor path.
        // Once activated it stays live for good - an expiry never gates the widget.
        $cfg['enabled'] = \Banimark\Licensing\Master::widgetActivated($settings);
        // uploads on? (the clip button is hidden entirely when they are not)
        $cfg['files'] = ($settings['files_enabled'] ?? '1') === '1';
        // "a person is back tomorrow at 9:00" - empty while the team is in
        $cfg['away_note'] = \Banimark\Desk\BusinessHours::fromSettings($settings)->awayNote();
        // out of hours the owner may greet differently ("We're closed - leave a
        // message"). Resolved HERE so the widget, the link and the app agree.
        $awayGreeting = trim((string) ($settings['away_greeting'] ?? ''));
        if ($cfg['away_note'] !== '' && $awayGreeting !== '') {
            $cfg['greeting'] = $awayGreeting;
        }
        // the first-run screen: what a guest is asked for, and what they can tap
        $cfg['guest_fields'] = self::guestFields($settings);
        $cfg['starters'] = self::starters($settings);
        // the header logo is served by its own public route; the ?v= changes
        // with the picture so a new logo is not stuck behind a cache
        $logo = WidgetLogo::fromSettings($settings);
        $cfg['logo_url'] = $logo === null ? '' : preg_replace('#/chat$#', '', $endpoint).'/widget/logo?v='.substr(sha1($logo['bytes']), 0, 10);
        $cfg['show_on'] = self::pageRules((string) ($settings['show_on'] ?? ''));
        $cfg['hide_on'] = self::pageRules((string) ($settings['hide_on'] ?? ''));
        $cfg['auto_open_pages'] = self::pageRules((string) ($settings['auto_open_pages'] ?? ''));
        return $cfg;
    }
}
