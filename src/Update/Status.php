<?php

namespace Banimark\Update;

use Banimark\Licensing\Master;

/**
 * Everything the panel needs to answer one question for a non-technical owner:
 * "can I press a button, and what happens if I do?"
 *
 * Assembled here rather than in either runtime's controller so the Laravel
 * panel and the standalone panel can never drift into telling the same
 * customer two different stories.
 */
final class Status
{
    /**
     * @param array  $updates  the cached releases feed (UpdateCheck::fetch())
     * @param array  $settings the install's own settings rows
     * @return array{
     *   current: string, latest: ?string, outdated: bool, notes: string,
     *   reachable: bool, endpoint: string, download_url: string,
     *   manifest: ?array, one_click: bool, blocked_reason: string,
     *   preflight: array, ready: bool, schema_behind: bool,
     *   backups: array, command: string
     * }
     */
    /**
     * The oldest release a panel may SWITCH to (reinstall / go back). Since
     * 0.26 Composer's autoloader requires src/core_boot.php on every request;
     * anything older lacks it, and swapping it in would stop the whole host
     * site loading. (The Installer refuses such a zip as well.)
     */
    public const SWITCH_FLOOR = '0.26.0';

    public static function build(array $updates, array $settings, string $licenseKey, ?string $root = null, string $endpoint = ''): array
    {
        $current = Master::PACKAGE_VERSION;
        $latest = isset($updates['latest']) ? (string) $updates['latest'] : null;
        $outdated = UpdateCheck::isNewer($latest, $current);

        $manifest = null;
        $notes = '';
        $test = false;
        foreach ((array) ($updates['releases'] ?? []) as $r) {
            if ((string) ($r['version'] ?? '') === (string) $latest) {
                $notes = (string) ($r['notes'] ?? '');
                $manifest = is_array($r['download'] ?? null) ? $r['download'] : null;
                // HQ lists a TEST release only to licences marked as test installs
                $test = !empty($r['test']);
                break;
            }
        }

        // Why the button is not there, in words an owner can act on. Order
        // matters: the most actionable reason wins.
        $blocked = '';
        if ($outdated && $manifest === null) {
            $blocked = 'This release has to be installed by hand - it is not offered as a one-click update.';
        } elseif ($outdated && $licenseKey === '') {
            $blocked = 'Enter your licence key on the License page first - updates are part of your licence.';
        }

        $alternatives = self::alternatives((array) ($updates['releases'] ?? []), $current, $outdated ? (string) $latest : '', $root);

        $installer = new Installer('', $licenseKey, null, $root);
        $preflight = $outdated && $manifest !== null ? $installer->preflight($manifest) : [];

        return [
            'current' => $current,
            'latest' => $latest,
            'outdated' => $outdated,
            // Whether we actually managed to ASK. Without this the panel says
            // "you are up to date" to an install that has never once reached
            // HQ - which is a lie told confidently, and the worst of the three
            // things this card can say.
            'reachable' => (bool) ($updates['ok'] ?? false),
            'endpoint' => $endpoint,
            'notes' => $notes,
            'test' => $test,
            // other one-click installs from here, besides the update above:
            // reinstall this version, or leave a TEST build for the latest stable
            'alternatives' => $licenseKey !== '' ? $alternatives : [],
            'manifest' => $manifest,
            'one_click' => $outdated && $manifest !== null && $licenseKey !== '',
            'blocked_reason' => $blocked,
            'preflight' => $preflight,
            'ready' => $preflight !== [] && Installer::ready($preflight),
            'schema_behind' => self::schemaBehind($settings),
            'backups' => $installer->backups(),
            'command' => (string) ($updates['update_command'] ?? 'composer update banimark/banimark'),
            // HQ may serve artifacts from somewhere other than its own endpoint;
            // safe to follow, because the bytes are signature-checked either way
            'download_url' => (string) ($updates['download_url'] ?? ''),
        ];
    }

    /**
     * Do the tables still match the code? After a file update they do not,
     * until someone says so - which is the second button.
     *
     * A settings array with NO schema_version key means the caller cannot tell
     * us, not that the database is stale - the Laravel panel reads settings
     * through an allow-list, and leaving the key out of it once made every
     * healthy install nag forever and then blame its own database. Unknown is
     * answered "no": a missing nag is a far cheaper mistake than a false one.
     */
    /**
     * The updater only ever offers something NEWER, which left two dead ends
     * with no way out from the panel: a test install could never return to the
     * stable release (it is older than the TEST build), and an install with
     * damaged files could not put its own version back.
     *
     * @return array<int, array{version: string, label: string, confirm: string, manifest: array}>
     */
    public static function alternatives(array $releases, string $current, string $updateTarget, ?string $root = null): array
    {
        $runningTest = false;
        foreach ($releases as $r) {
            if ((string) ($r['version'] ?? '') === $current && !empty($r['test'])) {
                $runningTest = true;
            }
        }
        // what the build says about itself, for a TEST build HQ no longer lists
        $src = ($root ?? Paths::packageRoot()).'/src';
        if ((\Banimark\CoreHealth::build($src)['kind'] ?? '') === 'evaluation') {
            $runningTest = true;
        }

        $out = [];
        $usable = fn (array $r) => is_array($r['download'] ?? null)
            && version_compare((string) ($r['version'] ?? ''), self::SWITCH_FLOOR, '>=')
            && (string) $r['version'] !== $updateTarget;

        foreach ($releases as $r) {
            if ($usable($r) && (string) $r['version'] === $current) {
                $out[] = ['version' => $current, 'label' => 'Reinstall '.$current,
                    'confirm' => 'Download '.$current.' again and put it back in place? Your data is not touched.',
                    'manifest' => $r['download']];
                break;
            }
        }
        if ($runningTest) {
            foreach ($releases as $r) {   // newest first
                if (empty($r['test']) && $usable($r) && (string) $r['version'] !== $current) {
                    $v = (string) $r['version'];
                    $out[] = ['version' => $v, 'label' => 'Switch to the stable release, '.$v,
                        'confirm' => 'Leave this TEST build and install the stable release '.$v.'?'
                            .(version_compare($v, $current, '<') ? ' It is an older version number - that is expected.' : ''),
                        'manifest' => $r['download']];
                    break;
                }
            }
        }
        return $out;
    }

    public static function schemaBehind(array $settings): bool
    {
        if (!array_key_exists('schema_version', $settings)) {
            return false;
        }
        return (string) $settings['schema_version'] !== Master::PACKAGE_VERSION;
    }
}
