<?php

namespace Banimark\Update;

use Banimark\Licensing\Master;

/**
 * The one-click updater: download the release HQ signed, check it is really
 * ours, and swap it into place - for an owner who has never opened a terminal.
 *
 * This file ships READABLE on purpose. A customer should be able to read
 * exactly what is about to overwrite their server. What is deliberately NOT
 * readable is the signature check itself (Master::verifyRelease, inside the
 * encoded core), because that is the one line that must not be patchable.
 *
 * The shape of the operation, and why:
 *
 *  - preflight() answers "would this work?" BEFORE anything is downloaded, so
 *    a host that cannot write to vendor/ is told plainly instead of failing
 *    half way. A half-applied update is a dead site.
 *  - Nothing is written into the live directory. The new version is unpacked
 *    beside it and only then swapped in, with two renames.
 *  - The old version is kept, not deleted, so there is always a way back.
 *  - Every failure after the swap begins restores the backup.
 *
 * It never touches the database. Schema changes are a separate, explicit step
 * the owner takes afterwards - see Storage\Schema::ensureCurrent().
 */
final class Installer
{
    /** Refuse anything larger than this; a release is a few hundred KB. */
    public const MAX_BYTES = 64 * 1024 * 1024;

    /** @var callable(string, string): array{ok: bool, status: int, error: string} */
    private $download;

    /** @var callable(string, string, int, string): bool */
    private $verify;

    /**
     * @param string $licenseKey the key the download is gated on
     * @param callable|null $download injectable transport, so tests need no network
     */
    /** The directory being replaced. Injectable so the swap can be tested on a
     *  throwaway tree instead of on the running install. */
    private string $root;

    /**
     * @param callable|null $verify the release-signature check. Omitted, it is
     *        Master::verifyRelease - inside the encoded core, as described above.
     *        Only Update\Recovery passes one: it runs when the core CANNOT load
     *        (an expired trial build), and there the only thing standing between
     *        the owner and a dead admin is an update that can check HQ's
     *        signature without the core. It checks the SAME signature against the
     *        same public key; nothing it accepts is unsigned.
     */
    public function __construct(
        private string $endpoint,
        private string $licenseKey,
        ?callable $download = null,
        ?string $root = null,
        ?callable $verify = null,
        private ?CoreProbe $probe = null,
    ) {
        $this->download = $download ?? \Closure::fromCallable([$this, 'curlDownload']);
        $this->root = $root ?? Paths::packageRoot();
        $this->verify = $verify ?? fn (string $v, string $sha, int $size, string $sig) => Master::verifyRelease($v, $sha, $size, $sig);
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Backups of previous versions sitting beside this install, newest first. */
    public function backups(): array
    {
        return Paths::backups($this->root);
    }

    /**
     * Where to fetch the artifact.
     *
     * HQ can name it outright (Settings -> update service base URL), which is
     * how a vendor moves downloads to a CDN without touching one customer's
     * config. Following a server-named URL is safe here and nowhere else: the
     * bytes are checked against a signature made with a key we already hold.
     * With nothing advertised, it sits beside the ping endpoint.
     */
    public static function endpointFrom(string $pingEndpoint, string $advertised = ''): string
    {
        $advertised = trim($advertised);
        if ($advertised !== '' && filter_var($advertised, FILTER_VALIDATE_URL)) {
            return $advertised;
        }
        return (string) preg_replace('#/ping$#', '/download', trim($pingEndpoint));
    }

    /* ------------------------------------------------------------------ */
    /* preflight                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Can this server update itself in place? Returns one row per check so the
     * panel can show the owner exactly which line is red, and what to ask their
     * host for.
     *
     * @param array $manifest the `download` block from the releases feed
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    public function preflight(array $manifest = []): array
    {
        $root = $this->root;
        $parent = dirname($root);
        $size = (int) ($manifest['size'] ?? 0);
        $rows = [];

        $rows[] = ['label' => 'Banimark found at '.$root, 'ok' => Paths::looksLikeBanimark($root),
            'hint' => 'The updater could not identify its own install directory. Update by hand and tell us - this should not happen.'];

        $rows[] = ['label' => 'The zip extension', 'ok' => class_exists(\ZipArchive::class),
            'hint' => 'Ask your host to enable the PHP zip extension (ext-zip).'];

        $rows[] = ['label' => 'Can download (curl)', 'ok' => function_exists('curl_init'),
            'hint' => 'Ask your host to enable the PHP curl extension.'];

        $rows[] = ['label' => 'Banimark\'s own folder is writable', 'ok' => is_writable($root),
            'hint' => 'PHP cannot change its own files here. Ask your host to give the web server write access to '.$root.', or update by hand.'];

        // the swap is a rename INSIDE the parent, so that is what must be writable
        $rows[] = ['label' => 'The folder above it is writable', 'ok' => is_writable($parent),
            'hint' => 'The update is swapped in by renaming, which needs write access to '.$parent.'.'];

        $rows[] = ['label' => 'Somewhere to unpack', 'ok' => Paths::workDir($root) !== null,
            'hint' => 'No writable working directory. Ask your host about the temp directory.'];

        if ($size > 0) {
            $free = @disk_free_space($parent);
            // download + unpacked copy + the backup of what is there now
            $rows[] = ['label' => 'Enough disk space', 'ok' => $free === false || $free > $size * 4,
                'hint' => 'Free up some space on the server - the update needs room for the download, the new copy and a backup of the current one.'];
        }

        $minPhp = trim((string) ($manifest['min_php'] ?? ''));
        if ($minPhp !== '') {
            $rows[] = ['label' => 'PHP '.$minPhp.' or newer (found '.PHP_VERSION.')', 'ok' => version_compare(PHP_VERSION, $minPhp, '>='),
                'hint' => 'This release needs a newer PHP. Ask your host to upgrade before updating Banimark.'];
        }

        return $rows;
    }

    public static function ready(array $preflight): bool
    {
        foreach ($preflight as $row) {
            if (!$row['ok']) {
                return false;
            }
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* the update                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Fetch, verify and install $version.
     *
     * @param array $manifest ['sha256' => ..., 'size' => ..., 'signature' => ..., 'min_php' => ...]
     * @return array{ok: bool, message: string, from: string, to: string, backup: string}
     */
    public function install(string $version, array $manifest): array
    {
        $got = $this->fetch($version, $manifest);
        if (!$got['ok']) {
            return $got + ['from' => Paths::versionOnDisk($this->root), 'to' => $version, 'backup' => ''];
        }
        return $this->apply($got['staged'], $version);
    }

    /**
     * Stage one: get the release onto this server and prove it is ours.
     *
     * Split from the swap because this is the slow, network-bound half and the
     * swap is the dangerous, instantaneous half. Separating them lets the panel
     * report honest progress, and means a slow download cannot leave the live
     * directory half-moved if the request times out.
     *
     * Nothing outside the working directory is touched here. A failure at any
     * point costs a temp file.
     *
     * @return array{ok: bool, staged: string, message: string}
     */
    public function fetch(string $version, array $manifest): array
    {
        $fail = fn (string $m) => ['ok' => false, 'staged' => '', 'message' => $m];

        $sha = strtolower(trim((string) ($manifest['sha256'] ?? '')));
        $size = (int) ($manifest['size'] ?? 0);
        $signature = (string) ($manifest['signature'] ?? '');

        if ($sha === '' || $size <= 0 || $signature === '') {
            return $fail('This release is not published for one-click updates.');
        }
        if ($size > self::MAX_BYTES) {
            return $fail('That update is unexpectedly large, so it was not downloaded.');
        }
        // the SIGNATURE first: a manifest tampered with in transit is refused
        // without spending a single byte of bandwidth on it
        if (!($this->verify)($version, $sha, $size, $signature)) {
            return $fail('That update is not signed by Banimark. Nothing was downloaded. Please tell us about this.');
        }
        if (!self::ready($this->preflight($manifest))) {
            return $fail('This server cannot apply updates automatically yet - see the checks above.');
        }

        $work = Paths::workDir($this->root);
        $stamp = date('Ymd-His');
        $zipPath = $work.'/banimark-update-'.$stamp.'.zip';
        $unpacked = $work.'/banimark-update-'.$stamp;
        $cleanup = function () use ($zipPath, $unpacked) {
            @unlink($zipPath);
            self::rmrf($unpacked);
        };

        $url = $this->endpoint.'?key='.rawurlencode($this->licenseKey).'&version='.rawurlencode($version);
        $res = ($this->download)($url, $zipPath);
        if (empty($res['ok'])) {
            $cleanup();
            return $fail($res['error'] !== '' ? $res['error'] : 'The update could not be downloaded. Check the server can reach the internet, then try again.');
        }

        $gotSize = (int) @filesize($zipPath);
        $gotSha = strtolower((string) @hash_file('sha256', $zipPath));
        if ($gotSize !== $size || !hash_equals($sha, $gotSha)) {
            $cleanup();
            return $fail('The downloaded file did not match what Banimark signed, so it was thrown away. Try again; if it keeps happening, tell us.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true || !$zip->extractTo($unpacked)) {
            $zip->close();
            $cleanup();
            return $fail('The update downloaded but could not be unpacked. Nothing on your server was changed.');
        }
        $zip->close();
        @unlink($zipPath);

        // a zip with one wrapper folder inside is the classic packaging slip
        if (!Paths::looksLikeBanimark($unpacked)) {
            $inner = glob($unpacked.'/*', GLOB_ONLYDIR) ?: [];
            if (count($inner) === 1 && Paths::looksLikeBanimark($inner[0])) {
                $unpacked = $inner[0];
            }
        }
        if (!Paths::looksLikeBanimark($unpacked)) {
            $cleanup();
            return $fail('That update did not contain a Banimark package. Nothing on your server was changed.');
        }
        // Since 0.26 Composer's autoloader REQUIRES src/core_boot.php on every
        // request of the host app. A release from before it (a downgrade, or a
        // stray old zip) would leave that require pointing at nothing - and a
        // failed require is fatal for the WHOLE site, not just Banimark.
        if (is_file($this->root.'/src/core_boot.php') && !is_file($unpacked.'/src/core_boot.php')) {
            $cleanup();
            return $fail('That release is older than the way this install now loads Banimark, so installing it would stop your whole site loading. Nothing was changed.');
        }

        // The one failure the swap cannot undo by itself: a core THIS server's
        // PHP will not run (built for a newer PHP, or a PHP newer than the
        // build). The loader refuses such a file with a fatal error on every
        // Banimark request - the admin that would roll it back included - so it
        // is judged here, before anything live is touched.
        $fit = ($this->probe ?? new CoreProbe())->check($unpacked, $version);
        if ($fit['result'] === CoreProbe::REFUSED) {
            $cleanup();
            return $fail($fit['message'].' Your current version keeps running; nothing was changed.');
        }

        return ['ok' => true, 'staged' => $unpacked, 'message' => 'Downloaded and verified.'];
    }

    /**
     * Stage two: the swap. Two renames, and the backup goes straight back if
     * the second one fails. Everything before this point was reversible by
     * deleting a temp file; this is the only part that is not.
     *
     * @return array{ok: bool, message: string, from: string, to: string, backup: string}
     */
    public function apply(string $staged, string $version = ''): array
    {
        $from = Paths::versionOnDisk($this->root);
        $fail = fn (string $m) => ['ok' => false, 'message' => $m, 'from' => $from, 'to' => $version, 'backup' => ''];

        // only ever a directory WE staged, inside our own working directory
        $work = Paths::workDir($this->root);
        $real = realpath($staged) ?: '';
        if ($real === '' || $work === null || !str_starts_with($real, (string) (realpath($work) ?: $work).'/banimark-update-')) {
            return $fail('That is not a staged Banimark update.');
        }
        if (!Paths::looksLikeBanimark($real)) {
            return $fail('The staged files are not a Banimark package. Nothing was changed.');
        }
        $version = $version !== '' ? $version : Paths::versionOnDisk($real);

        $root = $this->root;
        $backup = $root.'.bak-'.($from !== '' ? $from : 'previous').'-'.date('Ymd-His');

        if (!@rename($root, $backup)) {
            return $fail('Could not set the current version aside, so the update was not applied. Your install is untouched.');
        }
        if (!@rename($real, $root)) {
            @rename($backup, $root);   // the only moment that matters
            return $fail('The new version could not be moved into place, so the previous one was restored. Nothing was lost.');
        }

        return [
            'ok' => true,
            'from' => $from,
            'to' => $version,
            'backup' => $backup,
            'message' => 'Updated to '.$version.'.'.($from !== '' ? ' The previous version ('.$from.') was kept.' : ''),
        ];
    }

    /**
     * Put the previous version back. The backups are whole directories, so
     * this is the same two renames in the other order.
     *
     * @return array{ok: bool, message: string}
     */
    public function rollback(string $backup): array
    {
        $root = $this->root;
        if ($backup === '' || !is_dir($backup) || !str_starts_with($backup, $root.'.bak-')) {
            return ['ok' => false, 'message' => 'That is not a Banimark backup.'];
        }
        $aside = $root.'.rollback-'.date('Ymd-His');
        if (!@rename($root, $aside)) {
            return ['ok' => false, 'message' => 'Could not move the current version aside. Nothing was changed.'];
        }
        if (!@rename($backup, $root)) {
            @rename($aside, $root);
            return ['ok' => false, 'message' => 'Could not restore that backup, so the current version was put back.'];
        }
        self::rmrf($aside);
        return ['ok' => true, 'message' => 'Rolled back to '.Paths::versionOnDisk($root).'.'];
    }

    /** Keep the most recent $keep backups; older ones are just disk. */
    /**
     * How many previous versions to keep after an update. One: enough to roll
     * back the update that was just made, and no more - every copy is the size
     * of the whole package, on the customer's disk. (It was two, set
     * separately in six places.)
     */
    public const KEEP_BACKUPS = 1;

    public function pruneBackups(int $keep = self::KEEP_BACKUPS): int
    {
        $removed = 0;
        foreach (array_slice($this->backups(), $keep) as $old) {
            self::rmrf($old);
            $removed++;
        }
        return $removed;
    }

    /* ------------------------------------------------------------------ */

    /** @return array{ok: bool, status: int, error: string} */
    private function curlDownload(string $url, string $to): array
    {
        $fh = @fopen($to, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'Could not write the download to disk.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            // the signature is the real defence, but there is no reason to let
            // anyone watch a licence key go past either
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false) {
            return ['ok' => false, 'status' => $status, 'error' => 'Could not reach the update server'.($err !== '' ? ' ('.$err.')' : '').'.'];
        }
        if ($status === 403) {
            return ['ok' => false, 'status' => 403, 'error' => 'Your licence does not cover this update. Renew to download it.'];
        }
        if ($status !== 200) {
            return ['ok' => false, 'status' => $status, 'error' => 'The update server answered '.$status.'.'];
        }
        return ['ok' => true, 'status' => 200, 'error' => ''];
    }

    /** Recursive delete, used only on directories this class created or named. */
    private static function rmrf(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
