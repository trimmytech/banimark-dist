<?php

namespace Banimark\Ui;

/** Inline SVG icon set (Lucide-style, 24px grid, 1.8 stroke). Inline so the
 *  panel needs no icon font, no sprite request and works offline. */
class Icons
{
    private const PATHS = [
        'dashboard'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'inbox'      => '<path d="M3 12h4l2 3h6l2-3h4"/><path d="M5.5 5h13l2.5 7v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/>',
        'tools'      => '<path d="M14.7 6.3a4 4 0 0 0 5 5l-9.4 9.4a2.1 2.1 0 0 1-3-3z"/><path d="M14.7 6.3 17 4"/>',
        'rules'      => '<path d="M4 5h16M4 12h16M4 19h10"/>',
        'providers'  => '<path d="M12 2v4M12 18v4M2 12h4M18 12h4M5 5l3 3M16 16l3 3M19 5l-3 3M8 16l-3 3"/><circle cx="12" cy="12" r="3.2"/>',
        'escalation' => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
        'staff'      => '<path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="3.2"/><path d="M22 20v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.6a4 4 0 0 1 0 7"/>',
        'widget'     => '<path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z"/>',
        'license'    => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/>',
        'customers'  => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'installs'   => '<rect x="2.5" y="3.5" width="19" height="13" rx="2"/><path d="M8 21h8M12 16.5V21"/>',
        'logout'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'sun'        => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'       => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'menu'       => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'plus'       => '<path d="M12 5v14M5 12h14"/>',
        'trash'      => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
        'back'       => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'send'       => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/>',
        'check'      => '<path d="M20 6 9 17l-5-5"/>',
        'chat'       => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 20.5l1.5-5.2a8.4 8.4 0 0 1-.9-3.8 8.4 8.4 0 0 1 8.4-9 8.4 8.4 0 0 1 9 8.4z"/>',
        'bolt'       => '<path d="M13 2 4.1 12.9a1 1 0 0 0 .8 1.6H11l-1 7.5 8.9-10.9a1 1 0 0 0-.8-1.6H12z"/>',
        'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
        'bell'       => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
        'play'       => '<path d="M7 5v14l11-7z"/>',
        'files'      => '<path d="M4 5a2 2 0 0 1 2-2h4l2 2.5h6a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/>',
        'shield'     => '<path d="M12 22s8-3.6 8-10V5.5l-8-3-8 3V12c0 6.4 8 10 8 10z"/>',
        'globe'      => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>',
        'key'        => '<circle cx="7.5" cy="15.5" r="4"/><path d="M10.5 12.5 21 2M17 6l3 3M14 9l2.5 2.5"/>',
    ];

    public static function get(string $name, int $size = 18): string
    {
        $p = self::PATHS[$name] ?? self::PATHS['dashboard'];
        return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="currentColor"'
            .' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$p.'</svg>';
    }
}
