<?php

namespace Banimark\Update;

/**
 * Updating an install whose core CANNOT run - a trial build past its 36 hours,
 * a build for the wrong PHP.
 *
 * The normal updater lives behind the admin, and the admin lives in the core,
 * so without this an expired build is a dead end until someone opens a
 * terminal. This is the same update - HQ's list, the licence-gated download,
 * the size and checksum check, the two-rename swap with a backup - with the one
 * core-only step replaced: the release SIGNATURE is checked here against HQ's
 * public key, which the build recorded in core-build.json when it was made.
 * Same payload, same key, same answer as Master::verifyRelease (a test holds
 * them to it); nothing unsigned gets in.
 *
 * Deliberately free of every core class: `Master` would autoload the very file
 * that cannot run.
 */
final class Recovery
{
    /** @var callable|null */
    private $http;

    /** @var callable|null */
    private $download;

    /**
     * @param string $srcDir       the package's src/ (its parent is what gets replaced)
     * @param string $licenseKey   the install's licence: HQ gates downloads on it, and
     *                             lists TEST releases only to licences marked for testing
     * @param string $pingEndpoint the install's HQ address; '' = the one the build names
     */
    public function __construct(
        private string $srcDir,
        private string $licenseKey,
        private string $pingEndpoint = '',
        ?callable $http = null,
        ?callable $download = null,
    ) {
        $this->http = $http;
        $this->download = $download;
        // Resolved, because the package root is dirname() of this, and dirname()
        // does not resolve "..": given ".../src/Laravel/..", it answered
        // ".../src/Laravel" and the updater went looking for Banimark there
        // (caught driving the page live, not by a test).
        $this->srcDir = realpath($srcDir) ?: $srcDir;
    }

    public function build(): array
    {
        return \Banimark\CoreHealth::build($this->srcDir);
    }

    /** The version on disk, from what the build says about itself. */
    public function installed(): string
    {
        return ltrim((string) ($this->build()['version'] ?? ''), 'vV') ?: Paths::versionOnDisk(dirname($this->srcDir));
    }

    public function pingEndpoint(): string
    {
        return trim($this->pingEndpoint) !== '' ? trim($this->pingEndpoint)
            : (string) ($this->build()['default_endpoint'] ?? '');
    }

    /**
     * Is there something newer to install?
     *
     * @return array{reachable: bool, offer: ?array{version: string, notes: string, test: bool, manifest: ?array}, download_url: string}
     */
    public function check(): array
    {
        $ping = $this->pingEndpoint();
        if ($ping === '') {
            return ['reachable' => false, 'offer' => null, 'download_url' => ''];
        }
        $feed = (new UpdateCheck(UpdateCheck::endpointFrom($ping), $this->http, $this->licenseKey))->fetch();
        if (empty($feed['ok'])) {
            return ['reachable' => false, 'offer' => null, 'download_url' => ''];
        }
        $installed = $this->installed();
        $offer = null;
        foreach ((array) $feed['releases'] as $r) {   // newest first
            $v = ltrim((string) ($r['version'] ?? ''), 'vV');
            // version_compare directly: UpdateCheck::isNewer defaults to a Master constant
            // a release that says it needs a newer PHP than this server runs is
            // passed over for an older one that can run here
            $minPhp = trim((string) ($r['download']['min_php'] ?? $r['min_php'] ?? ''));
            if ($minPhp !== '' && version_compare(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, $minPhp, '<')) {
                continue;
            }
            if ($v !== '' && ($installed === '' || version_compare($v, $installed, '>'))) {
                $offer = [
                    'version' => $v,
                    'notes' => (string) ($r['notes'] ?? ''),
                    'test' => !empty($r['test']),
                    'manifest' => is_array($r['download'] ?? null) ? $r['download'] : null,
                ];
                break;
            }
        }
        return [
            'reachable' => true,
            'offer' => $offer,
            'download_url' => (string) ($feed['download_url'] ?? '') ?: (string) preg_replace('#/ping$#', '/download', $ping),
        ];
    }

    /**
     * Install $version - looked up again here, never taken from a form: it must
     * be the newer version HQ offers right now. fetch() then apply(), the two
     * halves the error screen runs as separate steps to show real progress.
     *
     * @return array{ok: bool, message: string}
     */
    public function install(string $version): array
    {
        $got = $this->fetch($version);
        if (!$got['ok']) {
            return ['ok' => false, 'message' => $got['message']];
        }
        return $this->apply($got['staged'], $got['version']);
    }

    /**
     * Download $version, check its signature (against the key the build
     * recorded) and unpack it beside the install. Nothing live changes.
     *
     * @return array{ok: bool, staged: string, version: string, message: string}
     */
    public function fetch(string $version): array
    {
        $fail = fn (string $m) => ['ok' => false, 'staged' => '', 'version' => '', 'message' => $m];
        $check = $this->check();
        if (!$check['reachable']) {
            return $fail('HQ could not be reached, so nothing was installed. Check this server can reach the internet, then try again.');
        }
        $offer = $check['offer'];
        if ($offer === null || $offer['version'] !== ltrim($version, 'vV')) {
            return $fail('That version is no longer the one on offer. Reload the page to see what is available now.');
        }
        if ($offer['manifest'] === null) {
            return $fail('Banimark '.$offer['version'].' has to be installed by hand: composer require "banimark/banimark:^'.$offer['version'].'"');
        }
        $installer = $this->installer($check['download_url']);
        // The normal updater shows its checks as rows above its button; this
        // page has no such rows, so a failed check has to name itself here.
        $failing = array_filter($installer->preflight($offer['manifest']), fn ($row) => empty($row['ok']));
        if ($failing !== []) {
            return $fail('This server cannot install it by itself: '
                .implode(' ', array_map(fn ($row) => rtrim((string) $row['label'], '.').' - '.(string) ($row['hint'] ?? ''), $failing)));
        }
        $got = $installer->fetch($offer['version'], $offer['manifest']);
        return ['ok' => (bool) $got['ok'], 'staged' => (string) $got['staged'], 'version' => $offer['version'], 'message' => (string) $got['message']];
    }

    /**
     * Swap a staged release in, keeping one backup. The Installer only accepts
     * a directory it staged itself, inside its own working folder.
     *
     * @return array{ok: bool, message: string}
     */
    public function apply(string $staged, string $version): array
    {
        $installer = $this->installer('');
        $done = $installer->apply($staged, $version);
        if (!empty($done['ok'])) {
            $installer->pruneBackups();   // the same one-previous-version rule as every update
        }
        return ['ok' => (bool) $done['ok'], 'message' => (string) $done['message']];
    }

    private function installer(string $downloadUrl): Installer
    {
        return new Installer($downloadUrl, $this->licenseKey, $this->download, dirname($this->srcDir),
            fn (string $v, string $sha, int $size, string $sig) => $this->verifySignature($v, $sha, $size, $sig));
    }

    /** Mirrors Master::verifyRelease exactly - same payload, same key. */
    public function verifySignature(string $version, string $sha256, int $size, string $signatureB64): bool
    {
        $key = base64_decode((string) ($this->build()['hq_public_key'] ?? ''), true);
        $sig = base64_decode(trim($signatureB64), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        $payload = 'banimark-release:v1|'.ltrim(trim($version), 'vV').'|'.strtolower(trim($sha256)).'|'.$size;
        try {
            return sodium_crypto_sign_verify_detached($sig, $payload, $key);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
