<?php

namespace Banimark\Notify;

/**
 * A minimal SMTP client written against the raw protocol - no Swiftmailer, no
 * PHPMailer, no Composer dependency, because the core ships with none. Covers
 * what a self-hosted owner actually configures: implicit TLS (465), STARTTLS
 * (587), plain (25), AUTH LOGIN / PLAIN, or no auth at all.
 *
 * The socket is injectable so the test suite can drive a scripted SMTP
 * conversation without a network.
 */
class SmtpMailer implements Mailer
{
    private string $error = '';

    /** @var null|callable(string, int, float): mixed opens the transport */
    private $connector;

    /**
     * @param string $encryption 'tls' (STARTTLS) | 'ssl' (implicit) | 'none'
     */
    public function __construct(
        private string $host,
        private int $port = 587,
        private string $username = '',
        private string $password = '',
        private string $encryption = 'tls',
        private string $fromEmail = '',
        private string $fromName = 'Banimark',
        private int $timeout = 12,
        ?callable $connector = null,
    ) {
        $this->connector = $connector;
    }

    public static function fromSettings(array $s): self
    {
        return new self(
            (string) ($s['smtp_host'] ?? ''),
            (int) ($s['smtp_port'] ?? 587),
            (string) ($s['smtp_user'] ?? ''),
            (string) ($s['smtp_pass'] ?? ''),
            (string) ($s['smtp_encryption'] ?? 'tls'),
            (string) ($s['smtp_from_email'] ?? ''),
            (string) ($s['smtp_from_name'] ?? 'Banimark'),
        );
    }

    public function send(array $to, string $subject, string $body): bool
    {
        $this->error = '';
        $to = array_values(array_filter(array_map('trim', $to)));
        if ($to === []) {
            $this->error = 'No recipient address.';
            return false;
        }
        if ($this->host === '' || $this->fromEmail === '') {
            $this->error = 'SMTP host and "from" address are required.';
            return false;
        }

        $socket = null;
        try {
            $socket = $this->open();
            $this->expect($socket, 220);

            $ehlo = $this->command($socket, 'EHLO '.$this->helo(), 250);
            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed.');
                }
                $ehlo = $this->command($socket, 'EHLO '.$this->helo(), 250);
            }

            if ($this->username !== '') {
                if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'PLAIN') !== false) {
                    $this->command($socket, 'AUTH PLAIN '.base64_encode("\0".$this->username."\0".$this->password), 235);
                } else {
                    $this->command($socket, 'AUTH LOGIN', 334);
                    $this->command($socket, base64_encode($this->username), 334);
                    $this->command($socket, base64_encode($this->password), 235);
                }
            }

            $this->command($socket, 'MAIL FROM:<'.$this->fromEmail.'>', 250);
            foreach ($to as $addr) {
                $this->command($socket, 'RCPT TO:<'.$addr.'>', [250, 251]);
            }
            $this->command($socket, 'DATA', 354);
            $this->write($socket, Message::build($this->fromName, $this->fromEmail, $to, $subject, $body)."\r\n.");
            $this->expect($socket, 250);
            $this->write($socket, 'QUIT');
            return true;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    public function lastError(): string
    {
        return $this->error;
    }

    /* ---------------- protocol plumbing ---------------- */

    private function open()
    {
        if ($this->connector !== null) {
            return ($this->connector)($this->host, $this->port, $this->timeout);
        }
        $host = $this->encryption === 'ssl' ? 'ssl://'.$this->host : $this->host;
        $socket = @stream_socket_client($host.':'.$this->port, $errno, $errstr, $this->timeout);
        if (!$socket) {
            throw new \RuntimeException('Could not connect to '.$this->host.':'.$this->port.' - '.($errstr ?: 'no route'));
        }
        stream_set_timeout($socket, $this->timeout);
        return $socket;
    }

    /** @param int|int[] $expect */
    private function command($socket, string $line, $expect): string
    {
        $this->write($socket, $line);
        return $this->expect($socket, $expect);
    }

    private function write($socket, string $line): void
    {
        if (@fwrite($socket, $line."\r\n") === false) {
            throw new \RuntimeException('Lost the connection to the mail server.');
        }
    }

    /** @param int|int[] $expect */
    private function expect($socket, $expect): string
    {
        $codes = (array) $expect;
        $response = '';
        while (($line = fgets($socket, 8192)) !== false) {
            $response .= $line;
            // multiline replies look like "250-EXTENSION"; the last has a space
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if ($response === '') {
            throw new \RuntimeException('The mail server closed the connection without replying.');
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('Mail server said: '.trim($response));
        }
        return $response;
    }

    private function helo(): string
    {
        $host = explode('@', $this->fromEmail)[1] ?? 'localhost';
        return preg_match('/^[A-Za-z0-9.\-]+$/', $host) === 1 ? $host : 'localhost';
    }
}
