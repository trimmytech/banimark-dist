<?php

namespace Banimark\Notify;

/** Sends mail. Implementations are swapped by configuration, never by code. */
interface Mailer
{
    /**
     * @param string[] $to recipient addresses
     * @return bool true when the message was handed off successfully
     */
    public function send(array $to, string $subject, string $body): bool;

    /** Why the last send failed - for the panel's "send test email" button. */
    public function lastError(): string;
}
