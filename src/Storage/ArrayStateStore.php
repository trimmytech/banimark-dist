<?php

namespace Banimark\Storage;

use Banimark\Contracts\StateStore;
use Banimark\Engine\ConversationState;

/** In-memory store - unit tests and single-request demos. */
class ArrayStateStore implements StateStore
{
    private array $rows = [];

    public function load(string $sessionId): ?array
    {
        if (!isset($this->rows[$sessionId])) {
            return null;
        }
        return [
            'state' => ConversationState::fromArray($this->rows[$sessionId]['state']),
            'identity_hash' => $this->rows[$sessionId]['identity_hash'],
        ];
    }

    public function save(string $sessionId, ConversationState $state, string $identityHash): void
    {
        $this->rows[$sessionId] = ['state' => $state->toArray(), 'identity_hash' => $identityHash];
    }
}
