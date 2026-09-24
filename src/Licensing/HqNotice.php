<?php

namespace Banimark\Licensing;

use Banimark\Ui\Icons;

/**
 * The "we could not reach HQ" caution shown at the top of every admin page.
 *
 * Fail-open is deliberate - an HQ outage must never lock a paying customer
 * out - but silence is not: staff should SEE that the licence could not be
 * re-verified, since when, and the day the grace window closes and the panel
 * locks (Master::lock() on a 'stale' token). Rendering only; the verdict
 * itself stays inside the encoded Master.
 */
final class HqNotice
{
    /** @param array<string, string> $settings the banimark_settings rows */
    public static function html(array $settings, string $host = ''): string
    {
        $since = (int) ($settings['license_unreachable_since'] ?? 0);
        $key = trim((string) ($settings['license_key'] ?? ''));
        if ($since <= 0 || $key === '') {
            return '';
        }
        $v = Master::verify($key, (string) ($settings['license_token'] ?? ''), null, $host);
        $graceUntil = (int) ($v['grace_until'] ?? 0);
        $support = trim((string) ($settings['support_email'] ?? ''));

        $text = '<b>Licence not re-verified.</b> Banimark HQ has been unreachable since '
            .htmlspecialchars(date('j M Y, H:i', $since), ENT_QUOTES).'. ';
        if ($v['status'] === 'active' && $graceUntil > time()) {
            $days = (int) ceil(($graceUntil - time()) / 86400);
            $text .= 'Your licence stays active for '.$days.' more day'.($days === 1 ? '' : 's')
                .' (until '.htmlspecialchars(date('j M Y', $graceUntil), ENT_QUOTES).'); if HQ is still out of reach after that, the admin panel locks until it can be re-checked. Your chat widget is never affected.';
        } else {
            $text .= 'The grace window has passed - the admin panel locks until HQ can be reached again. Your chat widget is never affected.';
        }
        if ($support !== '') {
            $text .= ' Need help? <a href="mailto:'.htmlspecialchars($support, ENT_QUOTES).'">'.htmlspecialchars($support, ENT_QUOTES).'</a>';
        }
        return '<div class="flash-warn" data-hq-notice>'.Icons::get('shield', 16).'<span>'.$text.'</span></div>';
    }
}
