<?php

namespace Banimark\Notify;

use Banimark\Storage\PdoStore;

/**
 * "The visitor walked away - email them the reply."
 *
 * A support chat is not a phone call: people close the tab. The widget marks
 * presence on every poll, so when an agent answers we can tell whether anyone
 * is still watching, and post the reply on to them if not.
 *
 * Rules that keep this from becoming spam:
 *  - an email address is required (guest form, init params, or identity token);
 *  - the visitor must have been away longer than the grace period;
 *  - one email per absence - we do not mail again until they have come back.
 */
class FollowUp
{
    public const DEFAULT_GRACE = 120;

    public function __construct(
        private PdoStore $store,
        private Mailer $mailer,
        private array $settings = [],
    ) {
    }

    /** @return bool whether an email was actually sent */
    public function afterAgentReply(string $sessionId, string $replyText, ?int $now = null): bool
    {
        $now = $now ?? time();
        if (($this->settings['visitor_followup'] ?? '1') !== '1') {
            return false;
        }
        $p = $this->store->presence($sessionId);
        if ($p === null || !filter_var($p['visitor_email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (!self::isAway($p, $now, $this->grace())) {
            return false; // still watching - they will see it in the widget
        }
        // one mail per absence: stay quiet until they have been back since
        if ($p['followup_at'] > 0 && $p['followup_at'] >= $p['last_seen_at']) {
            return false;
        }

        $name = trim($p['visitor_label']) !== '' ? trim($p['visitor_label']) : 'there';
        $site = trim((string) ($this->settings['site_name'] ?? ($this->settings['title'] ?? 'Support')));
        $subject = 'Re: your chat with '.$site;
        $body = "Hi {$name},\n\n"
            ."You stepped away from the chat, so here is our reply:\n\n"
            .'"'.trim($replyText)."\"\n\n"
            ."Just reply to this email, or come back to the chat on our site and we'll pick up where we left off.\n\n"
            ."- {$site}";

        $sent = $this->mailer->send([$p['visitor_email']], $subject, $body);
        if ($sent) {
            $this->store->markFollowedUp($sessionId, $now);
        }
        return $sent;
    }

    /** Away = has polled at least once, and not within the grace period. */
    public static function isAway(array $presence, int $now, int $grace): bool
    {
        $seen = (int) ($presence['last_seen_at'] ?? 0);
        if ($seen <= 0) {
            return true; // never checked in at all: treat as gone
        }
        return ($now - $seen) >= $grace;
    }

    private function grace(): int
    {
        return max(30, (int) ($this->settings['visitor_followup_after'] ?? self::DEFAULT_GRACE));
    }
}
