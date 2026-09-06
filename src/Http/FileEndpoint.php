<?php

namespace Banimark\Http;

use Banimark\Files\FileStore;
use Banimark\Files\UploadPolicy;
use Banimark\Storage\Attachments;

/**
 * Hands a stored file back. The URL's token IS the permission - unguessable,
 * per file - so an <img> in the widget works with no session, while nobody can
 * walk the list. Bytes are never served from a public directory.
 */
final class FileEndpoint
{
    public function __construct(private Attachments $attachments, private FileStore $files)
    {
    }

    /**
     * @return array{ok: bool, redirect?: string, bytes?: string, mime?: string, name?: string, download?: bool, error?: string}
     */
    public function handle(string $token, bool $download = false): array
    {
        $row = $this->attachments->findByToken($token);
        if (!$row) {
            return ['ok' => false, 'error' => 'That file is no longer available.'];
        }
        // object storage can hand the browser a short-lived URL of its own
        $url = $this->files->name() === (string) $row['disk'] ? $this->files->temporaryUrl((string) $row['path'], 600) : null;
        if ($url !== null) {
            return ['ok' => true, 'redirect' => $url];
        }
        $bytes = $this->files->name() === (string) $row['disk'] ? $this->files->read((string) $row['path']) : null;
        if ($bytes === null) {
            return ['ok' => false, 'error' => 'That file is no longer available.'];
        }
        return [
            'ok' => true,
            'bytes' => $bytes,
            'mime' => (string) $row['mime'],
            'name' => (string) $row['name'],
            // images render inline; everything else downloads, so nothing runs in the browser
            'download' => $download || !UploadPolicy::isImage((string) $row['mime']),
        ];
    }
}
