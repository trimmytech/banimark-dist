<?php

namespace Banimark\Update;

use Banimark\Licensing\Master;
use Banimark\Ui\Icons;

/**
 * "Banimark X is available" at the top of every admin page.
 *
 * Owners only: nobody else can act on it, and an agent working the inbox does
 * not need a version number in their way.
 *
 * Reads the CACHE and nothing else - never the network. The releases feed is
 * fetched on the Changelog page, on its own six-hourly throttle; a banner that
 * phoned home would put an outbound request in the path of every page in the
 * panel, including the inbox.
 *
 * Same shape as Licensing\HqNotice, for the same reason: one narrow strip at
 * the top of the content area, rendered by the layout in both runtimes.
 */
final class Notice
{
    /**
     * @param array<string, string> $settings the banimark_settings rows
     * @param bool   $isOwner      only owners see it
     * @param string $changelogUrl where "see what's new" goes
     * @param bool   $onChangelog  suppress it on the page it points at
     */
    public static function html(array $settings, bool $isOwner, string $changelogUrl, bool $onChangelog = false): string
    {
        if (!$isOwner || $onChangelog) {
            return '';
        }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // A database that has not caught up with the code is BROKEN, not merely
        // out of date, so it outranks a new release. It only gets here when the
        // automatic step after an update did not manage it (or somebody updated
        // from the command line), which is exactly when it needs saying loudly.
        if (Status::schemaBehind($settings)) {
            return '<div class="flash-err" data-update-notice>'
                .Icons::get('escalation', 16)
                .'<span><b>Banimark\'s database has not caught up.</b> The files are on '
                .$e(Master::PACKAGE_VERSION).' but the tables were not updated, so parts of the panel may misbehave. '
                .'<a href="'.$e($changelogUrl).'">Update the database</a> &mdash; it takes a second.</span>'
                .'</div>';
        }

        $cache = json_decode((string) ($settings['updates_cache'] ?? ''), true);
        if (!is_array($cache)) {
            return '';
        }
        $latest = (string) ($cache['latest'] ?? '');
        if (!UpdateCheck::isNewer($latest)) {
            return '';
        }

        // does it come with a one-click artifact, or will they need a terminal?
        $oneClick = false;
        $test = false;
        foreach ((array) ($cache['releases'] ?? []) as $r) {
            if ((string) ($r['version'] ?? '') === $latest) {
                $oneClick = is_array($r['download'] ?? null);
                $test = !empty($r['test']);
                break;
            }
        }

        return '<div class="flash-warn" data-update-notice>'
            .Icons::get('bolt', 16)
            .'<span><b>Banimark '.$e($latest).($test ? ' (TEST build)' : '').' is available.</b> You are running '.$e(Master::PACKAGE_VERSION).'. '
            .($oneClick ? 'You can install it from this panel.' : 'This one has to be installed by hand.')
            .' <a href="'.$e($changelogUrl).'">See what is new</a></span>'
            .'<button type="button" class="btn-ghost btn-sm" data-dismiss="[data-update-notice]" aria-label="Hide this notice">Not now</button>'
            .'</div>';
    }
}
