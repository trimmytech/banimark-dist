<?php

namespace Banimark\Notify;

/** The email a newly invited staff member receives. Plain text: it must survive every mail client. */
final class Invite
{
    /** @return array{0: string, 1: string} [subject, body] */
    public static function message(string $inviteeName, string $inviterName, string $deskTitle, string $activateUrl): array
    {
        $subject = $inviterName !== '' ? "{$inviterName} invited you to the {$deskTitle} support desk" : "You are invited to the {$deskTitle} support desk";
        $body = "Hi {$inviteeName},\n\n"
            .($inviterName !== '' ? "{$inviterName} has added you" : 'You have been added')." to the {$deskTitle} support desk on Banimark.\n\n"
            ."Set your password and activate your account here (the link works for 7 days):\n\n"
            ."{$activateUrl}\n\n"
            ."If you were not expecting this, you can ignore this email - nothing happens until the link is used.\n";
        return [$subject, $body];
    }
}
