<?php

namespace Banimark\Files;

/**
 * AWS Signature Version 4 - the small part of it S3 needs, with no SDK.
 *
 * Kept separate from the S3 store and free of I/O so it can be tested against
 * AWS's published example vectors: signing is the half that is easy to get
 * subtly wrong and impossible to debug against a live bucket.
 *
 * Reference: "Authenticating Requests (AWS Signature Version 4)".
 */
final class SigV4
{
    public const ALGO = 'AWS4-HMAC-SHA256';

    /**
     * The canonical request: the exact bytes AWS hashes on its side.
     *
     * @param array<string, string> $headers header name => value (any case)
     * @param string $query already-canonical query string ('' for none)
     */
    public static function canonicalRequest(string $method, string $path, string $query, array $headers, string $payloadHash): string
    {
        $canonHeaders = [];
        foreach ($headers as $name => $value) {
            // lowercase name, trimmed value with runs of spaces collapsed
            $canonHeaders[strtolower(trim($name))] = preg_replace('/\s+/', ' ', trim($value));
        }
        ksort($canonHeaders);
        $lines = '';
        foreach ($canonHeaders as $name => $value) {
            $lines .= $name.':'.$value."\n";
        }
        return strtoupper($method)."\n"
            .($path === '' ? '/' : $path)."\n"
            .$query."\n"
            .$lines."\n"
            .implode(';', array_keys($canonHeaders))."\n"
            .$payloadHash;
    }

    /** @param array<string, string> $headers */
    public static function signedHeaders(array $headers): string
    {
        $names = array_map(fn ($n) => strtolower(trim($n)), array_keys($headers));
        sort($names);
        return implode(';', $names);
    }

    public static function credentialScope(string $date, string $region, string $service): string
    {
        return $date.'/'.$region.'/'.$service.'/aws4_request';
    }

    public static function stringToSign(string $amzDate, string $scope, string $canonicalRequest): string
    {
        return self::ALGO."\n".$amzDate."\n".$scope."\n".hash('sha256', $canonicalRequest);
    }

    /** The date-, region- and service-scoped key. Raw bytes, never reused across days. */
    public static function signingKey(string $secret, string $date, string $region, string $service): string
    {
        $k = hash_hmac('sha256', $date, 'AWS4'.$secret, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);
        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    public static function signature(string $secret, string $date, string $region, string $service, string $stringToSign): string
    {
        return hash_hmac('sha256', $stringToSign, self::signingKey($secret, $date, $region, $service));
    }

    /**
     * The Authorization header for a signed request.
     *
     * @param array<string, string> $headers every header that must be signed
     */
    public static function authorization(string $accessKey, string $secret, string $region, string $service, string $method, string $path, string $query, array $headers, string $payloadHash, string $amzDate): string
    {
        $date = substr($amzDate, 0, 8);
        $scope = self::credentialScope($date, $region, $service);
        $canonical = self::canonicalRequest($method, $path, $query, $headers, $payloadHash);
        $signature = self::signature($secret, $date, $region, $service, self::stringToSign($amzDate, $scope, $canonical));
        return self::ALGO.' Credential='.$accessKey.'/'.$scope
            .', SignedHeaders='.self::signedHeaders($headers)
            .', Signature='.$signature;
    }

    /**
     * A presigned GET URL: the signature travels in the query string, so a
     * browser (or an <img> tag) can fetch a private object with no headers.
     */
    public static function presignedUrl(string $accessKey, string $secret, string $region, string $service, string $host, string $path, int $expires, ?int $now = null, string $scheme = 'https'): string
    {
        $now = $now ?? time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $date = substr($amzDate, 0, 8);
        $scope = self::credentialScope($date, $region, $service);
        $query = [
            'X-Amz-Algorithm' => self::ALGO,
            'X-Amz-Credential' => $accessKey.'/'.$scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(1, min(604800, $expires)),
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query);
        $canonicalQuery = self::query($query);
        $canonical = self::canonicalRequest('GET', $path, $canonicalQuery, ['host' => $host], 'UNSIGNED-PAYLOAD');
        $signature = self::signature($secret, $date, $region, $service, self::stringToSign($amzDate, $scope, $canonical));
        return $scheme.'://'.$host.$path.'?'.$canonicalQuery.'&X-Amz-Signature='.$signature;
    }

    /** RFC 3986 query encoding, sorted - AWS is strict about both. */
    public static function query(array $params): string
    {
        ksort($params);
        $out = [];
        foreach ($params as $k => $v) {
            $out[] = rawurlencode((string) $k).'='.rawurlencode((string) $v);
        }
        return implode('&', $out);
    }

    /** Encode a key as a URI path: every segment escaped, slashes kept. */
    public static function path(string $key): string
    {
        return '/'.implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }
}
