<?php

namespace Banimark\Http;

use Banimark\Identity\VisitorToken;
use Banimark\Storage\PdoStore;

/**
 * The visitor deletes their own conversation (the bin in the widget, the
 * chat link and the Flutter app). Bound to the same identity as
 * HistoryEndpoint: only a session this identity opened can be deleted, so a
 * guessed id deletes nothing.
 *
 * SOFT: the conversation disappears for the visitor at once (every visitor
 * path loads through PdoStore::load, which treats it as gone), staff still
 * see it, and Retention erases it - messages and files - after
 * visitor_delete_days (30), unless staff press "Keep".
 */
class DeleteEndpoint
{
    public function __construct(private PdoStore $store, private string $identitySecret = '')
    {
    }

    /** @param array $input ['session_id', 'token' => ?string] */
    public function handle(array $input, ?int $now = null): array
    {
        $sessionId = (string) ($input['session_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return ['ok' => false, 'error' => 'There is no conversation to delete.'];
        }
        $claims = [];
        $token = (string) ($input['token'] ?? '');
        if ($token !== '' && $this->identitySecret !== '') {
            $claims = VisitorToken::verify($token, $this->identitySecret) ?? [];
        }
        $identityHash = $claims === [] ? 'anon' : 'u:'.hash('sha256', json_encode($claims));
        $stored = $this->store->load($sessionId);
        if ($stored === null || !hash_equals($stored['identity_hash'], $identityHash)) {
            // already deleted, never existed, or not theirs: the same answer, so
            // the endpoint cannot be used to test which ids exist
            return ['ok' => true, 'deleted' => false];
        }
        try {
            $this->store->visitorDelete($sessionId, $now);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'The conversation could not be deleted just now. Please try again.'];
        }
        return ['ok' => true, 'deleted' => true];
    }
}
