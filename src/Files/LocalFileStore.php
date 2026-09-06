<?php

namespace Banimark\Files;

/**
 * Files on the customer's own disk.
 *
 * The directory is deliberately NOT assumed to be web-reachable: everything is
 * streamed back through the app, so an unguessable URL is the only way in and
 * a misconfigured web server cannot start listing other people's uploads.
 */
final class LocalFileStore implements FileStore
{
    private string $error = '';

    public function __construct(private string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    public function name(): string
    {
        return 'local';
    }

    public function lastError(): string
    {
        return $this->error;
    }

    public function put(string $key, string $bytes, string $mime): bool
    {
        $path = $this->pathFor($key);
        if ($path === null) {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error = 'Could not create the folder '.$dir.'. Check permissions.';
            return false;
        }
        // a stray web server should find nothing worth serving here
        if (!is_file($this->baseDir.'/.htaccess')) {
            @file_put_contents($this->baseDir.'/.htaccess', "Deny from all\nRequire all denied\n");
        }
        if (@file_put_contents($path, $bytes) === false) {
            $this->error = 'Could not write to '.$dir.'. Check permissions.';
            return false;
        }
        @chmod($path, 0664);
        return true;
    }

    public function read(string $key): ?string
    {
        $path = $this->pathFor($key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);
        return $path !== null && (!is_file($path) || @unlink($path));
    }

    public function temporaryUrl(string $key, int $ttlSeconds = 900): ?string
    {
        return null; // local files are streamed through the app, never linked directly
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /** Resolve a key under the base dir, refusing anything that climbs out of it. */
    private function pathFor(string $key): ?string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0")) {
            $this->error = 'Invalid file path.';
            return null;
        }
        return $this->baseDir.'/'.$key;
    }
}
