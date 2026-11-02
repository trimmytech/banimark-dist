<?php

namespace Banimark\Connector;

/**
 * The connector downloads, generated for ONE desk: its secret and its name
 * are written into the files, so the owner installs and pastes an address
 * back - nothing to type, nothing to configure. The templates live in
 * resources/connector/<platform>/ and are the reference implementations the
 * conformance checker (build/check-connector.php) proves.
 */
final class Bundle
{
    public const SECRET_PLACEHOLDER = '__BANIMARK_SECRET__';
    public const DESK_PLACEHOLDER = '__BANIMARK_DESK__';

    /** @return array<string, array{label: string, file: string, how: string, zip: bool}> */
    public static function platforms(): array
    {
        return [
            'wordpress' => ['label' => 'WordPress / WooCommerce', 'file' => 'banimark-connector.zip', 'zip' => true,
                'how' => 'Plugins → Add New → Upload Plugin → choose the zip → Activate. Then Settings → Banimark connector shows the address.'],
            'php' => ['label' => 'Plain PHP website', 'file' => 'banimark-connector.php', 'zip' => false,
                'how' => 'Upload the file next to your site\'s index.php, then open it in your browser: it shows the address. Fill in the four database lines at the top if it asks.'],
            'laravel' => ['label' => 'Laravel', 'file' => 'banimark-connector-laravel.zip', 'zip' => true,
                'how' => 'Copy the controller into app/Http/Controllers, the route file into routes/, and add one line to routes/api.php (README inside). The address is /api/banimark-connector.'],
            'node' => ['label' => 'Node.js', 'file' => 'banimark-connector.js', 'zip' => false,
                'how' => 'One file, no package. Run it with node, or mount it in your Express app (comments inside). Needs your database driver (mysql2, pg) or Node\'s built-in SQLite.'],
        ];
    }

    /**
     * The download: filename, bytes, content type.
     *
     * @throws \InvalidArgumentException for an unknown platform, \RuntimeException when it cannot be built
     * @return array{name: string, bytes: string, mime: string}
     */
    public static function build(string $platform, string $secret, string $desk): array
    {
        $p = self::platforms()[$platform] ?? null;
        if ($p === null) {
            throw new \InvalidArgumentException('No connector for "'.$platform.'".');
        }
        if (!preg_match('/^[a-f0-9]{48}$/', $secret)) {
            throw new \RuntimeException('The connector secret has not been made yet.');
        }
        $root = \Banimark\Ui\Assets::root().'/resources/connector/'.$platform;
        if (!is_dir($root)) {
            throw new \RuntimeException('The '.$p['label'].' connector is not part of this build.');
        }
        $fill = fn (string $text) => str_replace([self::SECRET_PLACEHOLDER, self::DESK_PLACEHOLDER], [$secret, self::deskName($desk)], $text);
        if (!$p['zip']) {
            $file = $root.'/'.$p['file'];
            if (!is_file($file)) {
                throw new \RuntimeException('The '.$p['label'].' connector is not part of this build.');
            }
            return ['name' => $p['file'], 'bytes' => $fill((string) file_get_contents($file)), 'mime' => 'application/octet-stream'];
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('This server cannot make zip files (PHP\'s zip extension is missing). Ask your host to enable it, or download the plain PHP connector instead.');
        }
        $files = self::files($root);
        if ($files === []) {
            throw new \RuntimeException('The '.$p['label'].' connector is not part of this build.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bmc');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not build the zip.');
        }
        foreach ($files as $path) {
            $zip->addFromString(substr($path, strlen($root) + 1), $fill((string) file_get_contents($path)));
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return ['name' => $p['file'], 'bytes' => $bytes, 'mime' => 'application/zip'];
    }

    /** Are this platform's files in the tree? (A download of a missing one answers 404 with the reason.) */
    public static function available(string $platform): bool
    {
        $root = \Banimark\Ui\Assets::root().'/resources/connector/'.$platform;
        return is_dir($root) && self::files($root) !== [];
    }

    /** The desk name as it may appear inside a generated source file: no quotes, no line breaks, short. */
    public static function deskName(string $desk): string
    {
        $clean = trim((string) preg_replace('/[\'"\\\\`$\r\n\t<>]+/', '', $desk));
        return $clean !== '' ? mb_substr($clean, 0, 60) : 'your Banimark desk';
    }

    /** @return string[] every file under $dir, deterministic order */
    private static function files(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = (string) $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }
}
