<?php

namespace Banimark\Licensing;

/**
 * The ONE way an install talks to HQ about its licence - shared by the Laravel
 * middleware, the standalone dispatcher and both "Save & check" buttons.
 *
 * Rule that must never regress: an HQ that does not answer changes NOTHING.
 * The signed token and the last verdict stay exactly as they were, the outage
 * is merely stamped (license_unreachable_since) so the panel can say so. Both
 * runtimes used to write the (empty) token back on failure - one click of
 * "Save & check" during an outage locked the admin until HQ returned. The
 * standalone runtime also never re-checked on its own, so its 14-day grace
 * simply ran out. Now both re-check daily, fail-open, from the same code.
 *
 * Storage is injected as closures so the same logic runs over Laravel's DB
 * facade and the standalone Settings table alike.
 */
final class PhoneHome
{
    /**
     * @param array<string, string> $settings  the license_* rows as they are now
     * @param callable(string, string): void $set     write one setting
     * @param callable(string): void          $forget delete one setting
     * @param callable(): array|null          $ping   returns Master::ping()'s shape; null = build from $settings
     * @return array|null the ping result, or null when nothing was attempted (no key / not due)
     */
    public static function run(array $settings, string $siteUrl, callable $set, callable $forget, bool $force = false, ?callable $ping = null, ?int $now = null): ?array
    {
        $now = $now ?? time();
        $key = trim((string) ($settings['license_key'] ?? ''));
        if ($key === '') {
            return null;
        }
        if (!$force && !Master::due((string) ($settings['license_last_ping'] ?? ''), $now)) {
            return null;
        }
        // stamped BEFORE the request: an unreachable HQ must not slow every page for a day
        $set('license_last_ping', (string) $now);

        $ping ??= fn () => (new Master(
            (string) (($settings['hq_url'] ?? '') !== '' ? $settings['hq_url'] : Master::DEFAULT_ENDPOINT),
            $key,
            $siteUrl,
        ))->ping();
        try {
            $result = $ping();
        } catch (\Throwable $e) {
            // a transport that blows up is exactly as informative as one that times out
            $result = ['ok' => false, 'license' => 'unreachable', 'expires_at' => '', 'message' => '', 'token' => ''];
        }

        if (empty($result['ok'])) {
            // no answer is not a verdict: keep token + status, remember since when
            if (trim((string) ($settings['license_unreachable_since'] ?? '')) === '') {
                $set('license_unreachable_since', (string) $now);
            }
            return $result;
        }

        $set('license_status', (string) ($result['license'] ?? 'unknown'));
        if (($result['token'] ?? '') !== '') {
            $set('license_token', (string) $result['token']);
        }
        if (($result['support_email'] ?? '') !== '') {
            $set('support_email', (string) $result['support_email']);
        }
        $forget('license_unreachable_since');
        return $result;
    }

    /** What "Save & check" tells the owner when HQ did not answer. */
    public static function unreachableMessage(array $settings): string
    {
        $status = (string) ($settings['license_status'] ?? '');
        $token = (string) ($settings['license_token'] ?? '');
        if ($status === 'active' && $token !== '') {
            return 'Could not reach Banimark HQ right now, so nothing changed: your licence stays active and the panel stays open. We will re-check automatically.';
        }
        return 'Could not reach Banimark HQ right now, so the key could not be verified yet. Check your connection or try again in a moment - your chat widget keeps working either way.';
    }
}
