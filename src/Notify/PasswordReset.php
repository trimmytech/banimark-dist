<?php

namespace Banimark\Notify;

/** The email behind "Forgot your password". Plain text: it must survive every mail client. */
final class PasswordReset
{
    /** @return array{0: string, 1: string} [subject, body] */
    public static function message(string $name, string $deskTitle, string $resetUrl): array
    {
        $subject = "Choose a new password for the {$deskTitle} support desk";
        $body = "Hi {$name},\n\n"
            ."Someone asked for a new password for your account on the {$deskTitle} support desk. If that was you, choose one here (the link works for an hour, once):\n\n"
            ."{$resetUrl}\n\n"
            ."If it was not you, ignore this email - your password stays as it is and nothing happens until the link is used.\n";
        return [$subject, $body];
    }
}
