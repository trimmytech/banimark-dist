<?php

namespace Banimark\Notify;

/** Wraps a closure - the Laravel/standalone wiring injects the actual send. */
class CallbackNotifier implements EscalationNotifier
{
    /** @var callable */
    private $fn;

    public function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    public function escalated(string $sessionId, string $visitorLabel, string $reason): void
    {
        ($this->fn)($sessionId, $visitorLabel, $reason);
    }
}
