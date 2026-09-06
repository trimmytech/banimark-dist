<?php

namespace Banimark\Http;

use Banimark\Identity\VisitorToken;
use Banimark\Storage\PdoStore;

/**
 * Widget polling: "any agent replies after message id X?" Same identity
 * binding as ChatEndpoint - you can only poll a session your identity opened.
 *
 * The poll doubles as the PRESENCE heartbeat: a visitor whose widget is still
 * polling is still in the chat, and one who has stopped is a candidate for an
 * email follow-up. That keeps presence free - no extra endpoint, no extra
 * request - and means presence can never disagree with "is the widget open".
 */
class PollEndpoint
{
    public function __construct(
        private PdoStore $store,
        private string $identitySecret = '',
        private ?\Banimark\Storage\Attachments $attachments = null,
    ) {
    }

    /** @param array $input ['session_id', 'after' => int, 'token' => ?string] */
    public function handle(array $input, ?int $now = null): array
    {
        $sessionId = (string) ($input['session_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return ['ok' => false, 'messages' => [], 'mode' => 'ai'];
        }
        $claims = [];
        $token = (string) ($input['token'] ?? '');
        if ($token !== '' && $this->identitySecret !== '') {
            $claims = VisitorToken::verify($token, $this->identitySecret) ?? [];
        }
        $identityHash = $claims === [] ? 'anon' : 'u:'.hash('sha256', json_encode($claims));
        $stored = $this->store->load($sessionId);
        if ($stored === null || !hash_equals($stored['identity_hash'], $identityHash)) {
            return ['ok' => false, 'messages' => [], 'mode' => 'ai'];
        }

        $this->store->touch($sessionId, $now);
        if (!empty($input['typing'])) {
            $this->store->markTyping($sessionId, 'visitor', $now);
        }
        $rows = $this->store->agentMessagesSince($sessionId, max(0, (int) ($input['after'] ?? 0)));
        $presence = $this->store->presence($sessionId) ?? [];

        return [
            'ok' => true,
            'mode' => $this->store->mode($sessionId),
            'agent_typing' => !empty($presence['agent_typing']),
            'messages' => array_map(function ($r) {
                $parsed = \Banimark\Files\Markers::parse((string) $r['content']);
                $files = [];
                if ($parsed['tokens'] !== [] && $this->attachments !== null) {
                    $files = array_map(fn ($a) => [
                        'token' => (string) $a['token'], 'name' => (string) $a['name'], 'mime' => (string) $a['mime'],
                        'size' => (int) $a['size'], 'is_image' => \Banimark\Files\UploadPolicy::isImage((string) $a['mime']),
                    ], $this->attachments->byTokens($parsed['tokens']));
                }
                return ['id' => (int) $r['id'], 'text' => $parsed['text'], 'files' => $files];
            }, $rows),
        ];
    }
}
