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
        // a hosted desk with no SMTP of its own sends through Banimark's: its SMTP
        // when HQ has one, else the host's local mailer from Banimark's address
        if (($hosted = \Banimark\Standalone\Hosted::mail()) !== null) {
            return trim((string) ($hosted['smtp_host'] ?? '')) !== ''
                ? SmtpMailer::fromSettings($hosted)
                : new NativeMailer((string) $hosted['smtp_from_email'], (string) ($hosted['smtp_from_name'] ?? 'Banimark'));
        }
        return new NativeMailer($from, (string) ($settings['smtp_from_name'] ?? 'Banimark'));
    }

    /** What the Notifications page says is in use when SMTP is not set up here. */
    public static function fallbackLabel(): string
    {
        return \Banimark\Standalone\Hosted::mail() !== null
            ? "otherwise Banimark's own mail service sends for you"
            : 'otherwise PHP mail(), which most cloud hosts silently drop';
    }
}
