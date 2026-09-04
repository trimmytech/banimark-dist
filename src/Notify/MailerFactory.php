<?php

namespace Banimark\Notify;

/** Builds the configured mailer from the settings table. SMTP when the owner
 *  has set it up, PHP mail() otherwise - the panel says plainly which is in use. */
class MailerFactory
{
    public static function make(array $settings): Mailer
    {
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $from = trim((string) ($settings['smtp_from_email'] ?? ''));
        if (($settings['smtp_enabled'] ?? '') === '1' && $host !== '' && $from !== '') {
            return SmtpMailer::fromSettings($settings);
        }
        return new NativeMailer($from, (string) ($settings['smtp_from_name'] ?? 'Banimark'));
    }
}
