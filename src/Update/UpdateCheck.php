<?php

namespace Banimark\Update;

use Banimark\Licensing\Master;

/**
 * "Is there a newer Banimark, and what changed?"
 *
 * Deliberately NOT licence-gated. A customer whose licence has lapsed still
 * sees that an update exists and what it contains - otherwise the first thing
 * a renewal conversation hits is "nobody told me". It is also fail-open and
 * cached: the panel must render normally when HQ is unreachable.
 */
class UpdateCheck
{
    /** Ask HQ at most this often; the answer is cached in settings between times. */
    public const INTERVAL = 21600; // 6 hours

    /** @var callable(string): array{status: int, body: string} */
    private $transport;

    public function __construct(private string $endpoint = '', ?callable $transport = null)
    {
        $this->endpoint = $endpoint !== '' ? $endpoint : self::endpointFrom(Master::DEFAULT_ENDPOINT);
        $this->transport = $transport ?? \Closure::fromCallable([$this, 'curlTransport']);
    }

    /** The releases feed sits beside the ping endpoint on the same HQ. */
    public static function endpointFrom(string $pingEndpoint): string
    {
        return preg_replace('#/ping$#', '/releases', trim($pingEndpoint)) ?: '';
    }

    /**
     * @return array{ok: bool, latest: ?string, releases: array, update_command: string, sdks: array}
     */
    public function fetch(): array
    {
        $empty = ['ok' => false, 'latest' => null, 'releases' => [], 'update_command' => 'composer update banimark/banimark', 'sdks' => []];
        try {
            $res = ($this->transport)($this->endpoint);
            $data = ($res['status'] ?? 0) === 200 ? json_decode((string) ($res['body'] ?? ''), true) : null;
            if (!is_array($data) || empty($data['ok'])) {
                return $empty;
            }
            return [
                'ok' => true,
                'latest' => isset($data['latest']) ? (string) $data['latest'] : null,
                'releases' => array_values((array) ($data['releases'] ?? [])),
                'update_command' => (string) ($data['update_command'] ?? $empty['update_command']),
                // companion SDKs the vendor advertises (e.g. sdks.flutter = {version,url,notes})
                'sdks' => is_array($data['sdks'] ?? null) ? $data['sdks'] : [],
            ];
        } catch (\Throwable $e) {
            return $empty; // never break the panel over a version check
        }
    }

    /** Is $latest newer than what is installed? */
    public static function isNewer(?string $latest, string $installed = Master::PACKAGE_VERSION): bool
    {
        return is_string($latest) && $latest !== '' && version_compare($latest, $installed, '>');
    }

    public static function due(?string $lastCheckedAt, ?int $now = null): bool
    {
        return (($now ?? time()) - (int) $lastCheckedAt) >= self::INTERVAL;
    }

    /** @return array{status: int, body: string} */
    private function curlTransport(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 4,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}
