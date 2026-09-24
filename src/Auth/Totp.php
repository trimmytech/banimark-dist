<?php

namespace Banimark\Auth;

/**
 * RFC 6238 time-based one-time passwords, in ~60 lines of pure PHP so the core
 * keeps its zero dependencies. Compatible with Google Authenticator, Authy,
 * 1Password and friends: 30-second steps, 6 digits, SHA-1 (the only algorithm
 * every authenticator app supports).
 */
class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    /** A fresh 160-bit secret, base32 - what the user types or scans. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The URI authenticator apps understand (also what a QR code would carry). */
    public static function uri(string $secret, string $account, string $issuer = 'Banimark'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), self::PERIOD);
        $bin = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $bin, self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Accepts the current step and one either side - phones drift a little. */
    public static function verify(string $secret, string $input, ?int $time = null): bool
    {
        $input = preg_replace('/\D/', '', $input);
        if (strlen($input) !== self::DIGITS || $secret === '') {
            return false;
        }
        $time = $time ?? time();
        foreach ([-1, 0, 1] as $drift) {
            if (hash_equals(self::code($secret, $time + $drift * self::PERIOD), $input)) {
                return true;
            }
        }
        return false;
    }

    public static function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
