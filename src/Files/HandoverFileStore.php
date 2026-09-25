<?php

namespace Banimark\Files;

/**
 * S3 configured, but the licence does not cover S3 (never did, or the plan
 * went down): the owner's choice for that case is READ-ONLY S3 - files already
 * in the bucket stay readable, new uploads land on this server. Decided where
 * the store is made (FileStoreFactory), from the signed licence, so switching
 * `files_driver` to 's3' in the database does not unlock it.
 */
final class HandoverFileStore implements FileStore
{
    public function __construct(private FileStore $local, private FileStore $s3)
    {
    }

    /** New files are recorded against the local disk. */
    public function name(): string
    {
        return $this->local->name();
    }

    public function put(string $key, string $bytes, string $mime): bool
    {
        return $this->local->put($key, $bytes, $mime);
    }

    public function read(string $key): ?string
    {
        return $this->local->read($key);
    }

    /** Delete wherever it is - retention and "delete conversation" must really delete. */
    public function delete(string $key): bool
    {
        $a = $this->local->delete($key);
        $b = $this->s3->delete($key);
        return $a || $b;
    }

    public function temporaryUrl(string $key, int $ttlSeconds = 900): ?string
    {
        return $this->local->temporaryUrl($key, $ttlSeconds);
    }

    public function lastError(): string
    {
        return $this->local->lastError() ?: $this->s3->lastError();
    }

    /** The store a file recorded against $disk lives on. */
    public function forDisk(string $disk): ?FileStore
    {
        return match ($disk) {
            $this->local->name() => $this->local,
            $this->s3->name() => $this->s3,
            default => null,
        };
    }

    /** Any store: which one holds a file recorded against $disk (null = none here). */
    public static function storeFor(FileStore $files, string $disk): ?FileStore
    {
        if ($files instanceof self) {
            return $files->forDisk($disk);
        }
        return $files->name() === $disk ? $files : null;
    }
}
