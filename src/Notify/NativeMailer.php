<?php

namespace Banimark\Notify;

/** PHP's mail(). The fallback when no SMTP is configured - fine on hosts with
 *  a working local MTA, useless on most cloud boxes, which is exactly why the
 *  panel offers SMTP. */
class NativeMailer implements Mailer
{
    private string $error = '';

    public function __construct(private string $fromEmail = '', private string $fromName = 'Banimark')
    {
    }

    public function send(array $to, string $subject, string $body): bool
    {
        $to = array_values(array_filter(array_map('trim', $to)));
        if ($to === []) {
            $this->error = 'No recipient address.';
            return false;
        }
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        if ($this->fromEmail !== '') {
            $headers .= 'From: '.Message::header($this->fromName, $this->fromEmail)."\r\n";
        }
        // the ENVELOPE sender too (-f): without it the local mailer uses the
        // system user at the box's hostname, which Gmail refuses as unauthenticated
        $envelope = $this->envelope();
        $ok = true;
        foreach ($to as $addr) {
            $sent = $envelope !== ''
                ? @mail($addr, $subject, $body, $headers, '-f'.$envelope)
                : @mail($addr, $subject, $body, $headers);
            $ok = $sent && $ok;
        }
        $this->error = $ok ? '' : 'PHP mail() returned false - this host probably has no local mail transport. Configure SMTP.';
        return $ok;
    }

    public function lastError(): string
    {
        return $this->error;
    }

    /**
     * The envelope sender passed to mail() as -f, or '' when the address is
     * not one that may safely go on a command line: only the plain characters
     * of an email address, nothing a shell or sendmail could read as an option.
     */
    public function envelope(): string
    {
        $from = trim($this->fromEmail);
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/', $from)) {
            return '';
        }
        return $from;
    }
}
