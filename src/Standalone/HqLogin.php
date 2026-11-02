<?php

namespace Banimark\Standalone;

use Banimark\Licensing\Master;

/**
 * "Open this desk as its owner" from Banimark HQ. An HQ admin presses one
 * button; HQ signs a token with the SAME Ed25519 key that signs licences,
 * and the hosted desk - which already ships HQ's public key - verifies it and
 * signs the admin in as the desk's owner, saying so on every page.
 *
 * The token names the desk (its slug), who at HQ asked, and is good for five
 * minutes and one use. Only a HOSTED desk honours it: a self-hosted install
 * belongs to its owner, and HQ has no business inside it.
 *
 *   token = base64url(json payload) . "." . base64url(ed25519 signature)
 *   payload = {"t":"hq-login","slug":"acme","by":"Ada at HQ","iat":...,"exp":...,"jti":"..."}
 */
final class HqLogin
{
    public const PURPOSE = 'hq-login';
    public const TTL = 300;
    public const ROUTE = '/hq-login';

    private static ?string $trusted = null;

    /** Test seam: verify against this base64 public key instead of HQ's. null restores HQ's. */
    public static function trust(?string $publicKey): void
    {
        self::$trusted = $publicKey;
    }

    /** What HQ signs: built here so HQ and the desk cannot disagree on the shape. */
    public static function payload(string $slug, string $by, ?int $now = null): string
    {
        $now = $now ?? time();
        return (string) json_encode([
            't' => self::PURPOSE,
            'slug' => strtolower(trim($slug)),
            'by' => mb_substr(trim($by), 0, 80),
            'iat' => $now,
            'exp' => $now + self::TTL,
            'jti' => bin2hex(random_bytes(8)),
        ], JSON_UNESCAPED_SLASHES);
    }

    /** The token, given HQ's raw Ed25519 secret key (HQ's Signer holds it). */
    public static function sign(string $secretKey, string $slug, string $by, ?int $now = null): string
    {
        $payload = self::payload($slug, $by, $now);
        return self::b64($payload).'.'.self::b64(sodium_crypto_sign_detached($payload, $secretKey));
    }

    /**
     * Is this token HQ's, for THIS desk, still fresh? Replay is the caller's
     * job (the jti comes back for it).
     *
     * @param string      $slug      this desk's slug
     * @param string|null $publicKey base64 Ed25519 public key; null = HQ's (tests pass their own)
     * @return array{ok: bool, error?: string, by?: string, jti?: string, exp?: int}
     */
    public static function verify(string $token, string $slug, ?int $now = null, ?string $publicKey = null): array
    {
        $now = $now ?? time();
        $parts = explode('.', trim($token));
        if (count($parts) !== 2) {
            return ['ok' => false, 'error' => 'That link is not a Banimark HQ sign-in link.'];
        }
        $payload = (string) base64_decode(strtr($parts[0], '-_', '+/'));
        $sig = (string) base64_decode(strtr($parts[1], '-_', '+/'));
        if ($payload === '' || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['ok' => false, 'error' => 'That link is not a Banimark HQ sign-in link.'];
        }
        try {
            $ok = sodium_crypto_sign_verify_detached($sig, $payload, (string) base64_decode($publicKey ?? self::$trusted ?? Master::HQ_PUBLIC_KEY));
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            return ['ok' => false, 'error' => 'That sign-in link was not signed by Banimark HQ.'];
        }
        $data = json_decode($payload, true);
        if (!is_array($data) || ($data['t'] ?? '') !== self::PURPOSE) {
            return ['ok' => false, 'error' => 'That link is not a Banimark HQ sign-in link.'];
        }
        if (strtolower(trim($slug)) === '' || !hash_equals(strtolower(trim($slug)), (string) ($data['slug'] ?? ''))) {
            return ['ok' => false, 'error' => 'That sign-in link is for a different desk.'];
        }
        if ((int) ($data['exp'] ?? 0) < $now || (int) ($data['iat'] ?? 0) > $now + 60) {
            return ['ok' => false, 'error' => 'That sign-in link has expired - open the desk from HQ again.'];
        }
        return ['ok' => true, 'by' => (string) ($data['by'] ?? 'Banimark HQ'), 'jti' => (string) ($data['jti'] ?? ''), 'exp' => (int) $data['exp']];
    }

    /** This desk's slug, from the address its config welded it to. */
    public static function slugFor(array $server): string
    {
        return ltrim(Master::hostedSuffix(Hosted::siteUrlFor($server)), '/c/');
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
