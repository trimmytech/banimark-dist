<?php

namespace Banimark\Notify;

/** Message formatting shared by the mailers: header encoding and the plain
 *  RFC 5322 body both transports send. */
class Message
{
    /** "Name <email>" with the display part encoded only when it needs to be. */
    public static function header(string $name, string $email): string
    {
        $name = trim(str_replace(["\r", "\n"], '', $name));
        $email = trim(str_replace(["\r", "\n"], '', $email));
        if ($name === '') {
            return $email;
        }
        $encoded = preg_match('/^[\x20-\x7E]*$/', $name) === 1
            ? '"'.addcslashes($name, '"\\').'"'
            : '=?UTF-8?B?'.base64_encode($name).'?=';
        return $encoded.' <'.$email.'>';
    }

    /** Full message text (headers + body) for the SMTP DATA command. */
    public static function build(string $fromName, string $fromEmail, array $to, string $subject, string $body): string
    {
        $subject = trim(str_replace(["\r", "\n"], '', $subject));
        $headers = [
            'Date: '.date('r'),
            'From: '.self::header($fromName, $fromEmail),
            'To: '.implode(', ', array_map('trim', $to)),
            'Subject: '.(preg_match('/^[\x20-\x7E]*$/', $subject) === 1 ? $subject : '=?UTF-8?B?'.base64_encode($subject).'?='),
            'Message-ID: <'.bin2hex(random_bytes(12)).'@'.(explode('@', $fromEmail)[1] ?? 'banimark.local').'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        // dot-stuffing: a line that is a single dot would end the DATA command
        $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
        return implode("\r\n", $headers)."\r\n\r\n".str_replace("\n", "\r\n", (string) $body);
    }
}
