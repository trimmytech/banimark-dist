<?php

namespace Banimark\Files;

/**
 * S3 and anything that speaks its API (AWS, Cloudflare R2, DigitalOcean
 * Spaces, Backblaze B2, MinIO) - signed by hand, because the core carries no
 * dependencies and a customer should not have to install an SDK to store a
 * screenshot.
 *
 * Reads go out as short-lived presigned URLs, so the bytes never travel
 * through the customer's PHP process twice.
 */
final class S3FileStore implements FileStore
{
    /** @var callable */
    private $transport;
    private string $error = '';

    /**
     * @param array{bucket:string, region:string, key:string, secret:string, endpoint?:string, prefix?:string, path_style?:bool} $config
     * @param callable|null $transport fn(string $method, string $url, array $headers, string $body): array{status:int, body:string, error:string}
     */
    public function __construct(private array $config, ?callable $transport = null)
    {
        $this->transport = $transport ?? \Closure::fromCallable([$this, 'curl']);
    }

    public function name(): string
    {
        return 's3';
    }

    public function lastError(): string
    {
        return $this->error;
    }

    public function put(string $key, string $bytes, string $mime): bool
    {
        $now = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $hash = hash('sha256', $bytes);
        $headers = [
            'Host' => $this->host(),
            'Content-Type' => $mime !== '' ? $mime : 'application/octet-stream',
            'x-amz-content-sha256' => $hash,
            'x-amz-date' => $amzDate,
        ];
        $headers['Authorization'] = SigV4::authorization(
            (string) $this->config['key'], (string) $this->config['secret'],
            (string) $this->config['region'], 's3',
            'PUT', $this->pathFor($key), '', $headers, $hash, $amzDate,
        );
        $res = ($this->transport)('PUT', $this->url($key), $headers, $bytes);
        if (($res['status'] ?? 0) >= 200 && ($res['status'] ?? 0) < 300) {
            return true;
        }
        $this->error = $this->explain($res);
        return false;
    }

    public function read(string $key): ?string
    {
        $res = $this->signedRequest('GET', $key);
        if (($res['status'] ?? 0) === 200) {
            return (string) $res['body'];
        }
        $this->error = $this->explain($res);
        return null;
    }

    public function delete(string $key): bool
    {
        $res = $this->signedRequest('DELETE', $key);
        $ok = ($res['status'] ?? 0) >= 200 && ($res['status'] ?? 0) < 300;
        if (!$ok) {
            $this->error = $this->explain($res);
        }
        return $ok;
    }

    public function temporaryUrl(string $key, int $ttlSeconds = 900): ?string
    {
        $parts = parse_url($this->endpointBase());
        return SigV4::presignedUrl(
            (string) $this->config['key'], (string) $this->config['secret'],
            (string) $this->config['region'], 's3',
            $this->host(), $this->pathFor($key), $ttlSeconds, null,
            $parts['scheme'] ?? 'https',
        );
    }

    /** The object key including any configured prefix. */
    public function keyWithPrefix(string $key): string
    {
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');
        return $prefix === '' ? ltrim($key, '/') : $prefix.'/'.ltrim($key, '/');
    }

    private function signedRequest(string $method, string $key): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $empty = hash('sha256', '');
        $headers = ['Host' => $this->host(), 'x-amz-content-sha256' => $empty, 'x-amz-date' => $amzDate];
        $headers['Authorization'] = SigV4::authorization(
            (string) $this->config['key'], (string) $this->config['secret'],
            (string) $this->config['region'], 's3',
            $method, $this->pathFor($key), '', $headers, $empty, $amzDate,
        );
        return ($this->transport)($method, $this->url($key), $headers, '');
    }

    /** https://s3.<region>.amazonaws.com by default; any S3-compatible endpoint otherwise. */
    private function endpointBase(): string
    {
        $endpoint = trim((string) ($this->config['endpoint'] ?? ''));
        if ($endpoint !== '') {
            return rtrim($endpoint, '/');
        }
        return 'https://s3.'.(string) $this->config['region'].'.amazonaws.com';
    }

    /**
     * Virtual-hosted style by default (bucket in the hostname); path style when
     * asked for, which is what MinIO and some proxies need.
     */
    private function host(): string
    {
        $host = (string) (parse_url($this->endpointBase(), PHP_URL_HOST) ?: '');
        $port = parse_url($this->endpointBase(), PHP_URL_PORT);
        if (!$this->pathStyle()) {
            $host = $this->config['bucket'].'.'.$host;
        }
        return $host.($port ? ':'.$port : '');
    }

    private function pathStyle(): bool
    {
        return !empty($this->config['path_style']);
    }

    private function pathFor(string $key): string
    {
        $path = SigV4::path($this->keyWithPrefix($key));
        return $this->pathStyle() ? '/'.rawurlencode((string) $this->config['bucket']).$path : $path;
    }

    private function url(string $key): string
    {
        $scheme = parse_url($this->endpointBase(), PHP_URL_SCHEME) ?: 'https';
        return $scheme.'://'.$this->host().$this->pathFor($key);
    }

    /** Turn S3's XML complaint into something an owner can act on. */
    private function explain(array $res): string
    {
        if (($res['error'] ?? '') !== '') {
            return 'Could not reach the bucket: '.$res['error'];
        }
        $body = (string) ($res['body'] ?? '');
        $code = '';
        if (preg_match('#<Code>([^<]+)</Code>#', $body, $m)) {
            $code = $m[1];
        }
        return match ($code) {
            'SignatureDoesNotMatch' => 'S3 rejected the signature - check the secret key (status '.($res['status'] ?? 0).').',
            'InvalidAccessKeyId' => 'S3 does not recognise that access key.',
            'AccessDenied' => 'Access denied - the key exists but is not allowed to write to this bucket/prefix.',
            'NoSuchBucket' => 'That bucket does not exist in this region.',
            'PermanentRedirect', 'AuthorizationHeaderMalformed' => 'Wrong region for this bucket (status '.($res['status'] ?? 0).').',
            default => 'S3 returned HTTP '.($res['status'] ?? 0).($code !== '' ? ' ('.$code.')' : '').'.',
        };
    }

    /** @return array{status:int, body:string, error:string} */
    private function curl(string $method, string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        $head = [];
        foreach ($headers as $name => $value) {
            if (strtolower($name) !== 'host') { // curl sets Host from the URL
                $head[] = $name.': '.$value;
            }
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $head,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($out) ? $out : '', 'error' => $err];
    }
}
