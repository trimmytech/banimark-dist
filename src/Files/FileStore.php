<?php

namespace Banimark\Files;

/**
 * Where chat attachments are kept. Two implementations ship (local disk and
 * S3-compatible object storage); this whole namespace stays READABLE in the
 * distributed build on purpose, so a customer can point it at their own
 * storage or write an adapter without waiting for us.
 */
interface FileStore
{
    /** @return bool false when the bytes could not be stored (never throws) */
    public function put(string $key, string $bytes, string $mime): bool;

    /** @return string|null the bytes, or null when the object is gone */
    public function read(string $key): ?string;

    public function delete(string $key): bool;

    /**
     * A URL the browser can fetch directly, if this store has one.
     * null = no direct URL; the panel/widget streams it through the app instead.
     */
    public function temporaryUrl(string $key, int $ttlSeconds = 900): ?string;

    /** 'local' | 's3' | whatever a custom adapter calls itself */
    public function name(): string;

    /** Human-readable reason the last call failed - shown on the Files page. */
    public function lastError(): string;
}
