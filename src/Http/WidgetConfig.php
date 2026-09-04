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
    ];

    public const DEFAULTS = [
        'color' => '#6F04D9',
        'position' => 'right',
        'title' => 'Support',
        'greeting' => 'Hi! How can we help you today?',
        'poll_seconds' => 10,
        'guest_mode' => 'off',
        'offline_note' => '',
    ];

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
        $cfg['position'] = $cfg['position'] === 'left' ? 'left' : 'right';
        $cfg['guest_mode'] = in_array($cfg['guest_mode'], ['off', 'optional', 'required'], true) ? $cfg['guest_mode'] : 'off';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $cfg['color'])) {
            $cfg['color'] = self::DEFAULTS['color'];
        }
        $cfg['endpoint'] = $endpoint;
        return $cfg;
    }
}
