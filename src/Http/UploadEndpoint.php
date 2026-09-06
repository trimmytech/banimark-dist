<?php

namespace Banimark\Http;

use Banimark\Files\FileStore;
use Banimark\Files\UploadPolicy;
use Banimark\Identity\VisitorToken;
use Banimark\Storage\Attachments;
use Banimark\Storage\PdoStore;

/**
 * A visitor sends a file. Framework-free, so both runtimes share the checks
 * that matter: the conversation must exist and belong to this identity, the
 * type must be on the allow-list, and the name it is stored under is ours.
 */
final class UploadEndpoint
{
    public function __construct(
        private PdoStore $store,
        private Attachments $attachments,
        private FileStore $files,
        private UploadPolicy $policy,
        private string $identitySecret = '',
        private bool $enabled = true,
    ) {
    }

    /**
     * @param array $input ['session_id', 'token', 'filename', 'bytes', 'size']
     * @return array{ok: bool, error?: string, attachment?: array}
     */
    public function handle(array $input): array
    {
        if (!$this->enabled) {
            return ['ok' => false, 'error' => 'File sharing is switched off for this desk.'];
        }
        $sessionId = (string) ($input['session_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return ['ok' => false, 'error' => 'Start the conversation before sending a file.'];
        }
        $claims = [];
        $token = (string) ($input['token'] ?? '');
        if ($token !== '' && $this->identitySecret !== '') {
            $claims = VisitorToken::verify($token, $this->identitySecret) ?? [];
        }
        $identityHash = $claims === [] ? 'anon' : 'u:'.hash('sha256', json_encode($claims));
        $stored = $this->store->load($sessionId);
        // the same rule the chat and poll endpoints use: this thread, this visitor
        if ($stored === null || !hash_equals($stored['identity_hash'], $identityHash)) {
            return ['ok' => false, 'error' => 'This conversation could not be verified.'];
        }

        $bytes = (string) ($input['bytes'] ?? '');
        $check = $this->policy->check((string) ($input['filename'] ?? ''), (int) ($input['size'] ?? strlen($bytes)), $bytes);
        if (!$check['ok']) {
            return ['ok' => false, 'error' => $check['error']];
        }

        $key = UploadPolicy::key($check['ext']);
        if (!$this->files->put($key, $bytes, $check['mime'])) {
            // the real reason goes to the log/panel, never to the visitor
            return ['ok' => false, 'error' => 'We could not save that file. Please try again.', 'detail' => $this->files->lastError()];
        }
        $row = $this->attachments->create($sessionId, $this->files->name(), $key, $check['name'], $check['mime'], strlen($bytes), 'visitor');

        return ['ok' => true, 'attachment' => [
            'id' => (int) $row['id'],
            'token' => (string) $row['token'],
            'name' => (string) $row['name'],
            'mime' => (string) $row['mime'],
            'size' => (int) $row['size'],
            'is_image' => UploadPolicy::isImage((string) $row['mime']),
        ]];
    }
}
