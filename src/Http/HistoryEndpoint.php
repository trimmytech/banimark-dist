<?php

namespace Banimark\Http;

use Banimark\Identity\VisitorToken;
use Banimark\Storage\PdoStore;

/**
 * Chat continuation: when a visitor comes back, the widget replays the
 * conversation instead of starting cold. Bound to the same identity as
 * ChatEndpoint - you can only replay a session your identity opened, so a
 * guessed session id returns nothing rather than someone else's chat.
 *
 * Only visitor-visible rows come back (their messages and the replies they
 * already saw); tool calls and their payloads never leave the server.
 */
class HistoryEndpoint
{
    public function __construct(
        private PdoStore $store,
        private string $identitySecret = '',
        /** messages per page - the widget asks for the page before its oldest one on demand */
        private int $limit = 15,
        private ?\Banimark\Storage\Attachments $attachments = null,
    ) {
    }

    /** @param array $input ['session_id', 'token' => ?string] */
    public function handle(array $input, ?int $now = null): array
    {
        $sessionId = (string) ($input['session_id'] ?? '');
        $claims = [];
        $token = (string) ($input['token'] ?? '');
        if ($token !== '' && $this->identitySecret !== '') {
            $claims = VisitorToken::verify($token, $this->identitySecret) ?? [];
        }
        $identityHash = $claims === [] ? 'anon' : 'u:'.hash('sha256', json_encode($claims));
        // no session on this device, but we know who it is: their open thread
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId) && $identityHash !== 'anon' && $this->store instanceof \Banimark\Storage\PdoStore) {
            $sessionId = (string) ($this->store->latestSessionFor($identityHash) ?? '');
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return ['ok' => false, 'messages' => [], 'mode' => 'ai'];
        }

        $stored = $this->store->load($sessionId);
        if ($stored === null || !hash_equals($stored['identity_hash'], $identityHash)) {
            return ['ok' => false, 'messages' => [], 'mode' => 'ai'];
        }

        // returning to the chat counts as being present
        $this->store->touch($sessionId, $now);

        $before = max(0, (int) ($input['before'] ?? 0));
        $limit = (int) ($input['limit'] ?? 0);
        $limit = $limit <= 0 ? $this->limit : max(5, min(50, $limit)); // absent or 0 = the default page
        $page = $this->store->visitorPage($sessionId, $limit, $before);
        $messages = [];
        foreach ($page['rows'] as $r) {
            $parsed = \Banimark\Files\Markers::parse((string) $r['content']);
            $messages[] = [
                'id' => (int) $r['id'],
                'role' => $r['role'] === 'user' ? 'user' : 'bot',
                'text' => $parsed['text'],
                'files' => $this->files($parsed['tokens']),
            ];
        }
        return [
            'ok' => true, 'session_id' => $sessionId, 'mode' => $this->store->mode($sessionId), 'messages' => $messages,
            'has_more' => $page['has_more'],
            'oldest_id' => $messages === [] ? 0 : $messages[0]['id'],
        ];
    }

    /** @param string[] $tokens @return array<int, array> what the widget needs to draw a file */
    private function files(array $tokens): array
    {
        if ($tokens === [] || $this->attachments === null) {
            return [];
        }
        return array_map(fn ($a) => [
            'token' => (string) $a['token'],
            'name' => (string) $a['name'],
            'mime' => (string) $a['mime'],
            'size' => (int) $a['size'],
            'is_image' => \Banimark\Files\UploadPolicy::isImage((string) $a['mime']),
        ], $this->attachments->byTokens($tokens));
    }
}
