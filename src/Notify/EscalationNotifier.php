<?php

namespace Banimark\Notify;

/** Told when a conversation is handed to humans. Implementations decide what
 *  "escalation mode" means - email a mailbox, ping staff, both, or nothing
 *  (staff just watch the inbox). */
interface EscalationNotifier
{
    public function escalated(string $sessionId, string $visitorLabel, string $reason): void;
}
