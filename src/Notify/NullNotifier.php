<?php

namespace Banimark\Notify;

/** Staff-inbox mode: escalations appear in the inbox, no push needed. */
class NullNotifier implements EscalationNotifier
{
    public function escalated(string $sessionId, string $visitorLabel, string $reason): void
    {
    }
}
