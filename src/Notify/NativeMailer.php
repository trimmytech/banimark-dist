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
        $ok = true;
        foreach ($to as $addr) {
            $ok = @mail($addr, $subject, $body, $headers) && $ok;
        }
        $this->error = $ok ? '' : 'PHP mail() returned false - this host probably has no local mail transport. Configure SMTP.';
        return $ok;
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
