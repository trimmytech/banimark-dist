<?php

namespace Banimark\Http;

use Banimark\Ai\Message;
use Banimark\Contracts\StateStore;
use Banimark\Desk\EscalateTool;
use Banimark\Engine\ConversationState;
use Banimark\Engine\Engine;
use Banimark\Identity\VisitorToken;
use Banimark\Notify\EscalationNotifier;
use Banimark\Storage\PdoStore;

/**
 * The widget's server half, framework-agnostic: give it a parsed request
 * array, get a response array. The Laravel bridge is a thin controller
 * around this; any other framework (or a queue worker) can drive it too.
 *
 * Security posture:
 *  - identity: optional VisitorToken; invalid/expired quietly = anonymous
 *    (no oracle - the visitor cannot distinguish "bad signature" from
 *    "no token").
 *  - sessions are BOUND to the identity that opened them: presenting a
 *    different identity (or none) for an existing session id starts a fresh
 *    session instead of resuming someone else's conversation.
 *  - message length capped; history window capped so a chatty visitor
 *    cannot balloon the model context forever.
 */
class ChatEndpoint
{
    public function __construct(
        private Engine $engine,
        private StateStore $store,
        private string $identitySecret = '',
        private int $maxMessageChars = 2000,
        private int $historyWindow = 40,
        private ?EscalationNotifier $notifier = null,
    ) {
    }

    /**
     * Label the conversation, record presence, and give the follow-up email
     * somewhere to go.
     *
     * Guest details are CLAIMS BY THE BROWSER, so they only ever label a
     * conversation and address a follow-up email - they never scope a tool
     * query. A signed identity token wins wherever both exist.
     */
    private function recordVisitor(string $sessionId, array $claims, array $visitor): void
    {
        if (!$this->store instanceof PdoStore) {
            return;
        }
        // sending a message proves they are here - this is the first heartbeat.
        // It must run AFTER the conversation row exists, which is why this is
        // called at each persistence point rather than up front.
        $this->store->touch($sessionId);
        $name = (string) ($claims['name'] ?? ($visitor['name'] ?? ''));
        $email = (string) ($claims['email'] ?? ($visitor['email'] ?? ''));
        $name = mb_substr(trim(strip_tags($name)), 0, 190);
        $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : '';
        if ($name === '' && $email === '') {
            return;
        }
        $this->store->setVisitor($sessionId, $name, $email);
    }

    /**
     * @param array $input ['message' => string, 'session_id' => ?string,
     *                      'token' => ?string, 'visitor' => ?array{name, email}]
     * @return array ['ok' => bool, 'session_id' => string, 'reply' => string, 'error' => ?string]
     */
    public function handle(array $input): array
    {
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            return ['ok' => false, 'error' => 'Say something first.', 'session_id' => '', 'reply' => ''];
        }
        if (mb_strlen($message) > $this->maxMessageChars) {
            return ['ok' => false, 'error' => 'That message is too long - please shorten it.', 'session_id' => '', 'reply' => ''];
        }

        $claims = [];
        $token = (string) ($input['token'] ?? '');
        if ($token !== '' && $this->identitySecret !== '') {
            $claims = VisitorToken::verify($token, $this->identitySecret) ?? [];
        }
        $identityHash = $claims === [] ? 'anon' : 'u:'.hash('sha256', json_encode($claims));

        // resume only when the session belongs to this same identity
        $sessionId = (string) ($input['session_id'] ?? '');
        $state = null;
        if ($sessionId !== '' && preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            $stored = $this->store->load($sessionId);
            if ($stored !== null && hash_equals($stored['identity_hash'], $identityHash)) {
                $state = $stored['state'];
            }
        }
        // a signed-in visitor with no (valid) session id continues their open thread
        if ($state === null && $identityHash !== 'anon' && $this->store instanceof PdoStore
            && ($resume = $this->store->latestSessionFor($identityHash)) !== null) {
            $stored = $this->store->load($resume);
            if ($stored !== null) {
                $sessionId = $resume;
                $state = $stored['state'];
            }
        }
        if ($state === null) {
            $sessionId = bin2hex(random_bytes(16));
            $state = new ConversationState();
        }


        // HUMAN TAKEOVER: once an agent owns the conversation the AI stays
        // silent - the visitor's message goes straight to the inbox and the
        // widget switches to polling for the agent's replies.
        if ($this->store instanceof PdoStore && $this->store->mode($sessionId) === 'agent') {
            $this->store->appendVisitorMessage($sessionId, $message, $identityHash);
            $this->recordVisitor($sessionId, $claims, (array) ($input['visitor'] ?? []));
            return [
                'ok' => true,
                'session_id' => $sessionId,
                'reply' => '',
                'mode' => 'agent',
                'error' => null,
            ];
        }

        $state->push(Message::user($message));
        $state->truncateTo($this->historyWindow);

        $result = $this->engine->reply($state, $claims);
        if (!$result->ok) {
            // The assistant failed (bad key, provider down, quota...). The visitor
            // must not be left with an apology: hand them to a human right away,
            // and put the REAL error in the thread where only staff can see it.
            $this->store->save($sessionId, $state, $identityHash);
            $this->recordVisitor($sessionId, $claims, (array) ($input['visitor'] ?? []));
            $label = (string) ($claims['name'] ?? $claims['email'] ?? (isset($claims['user_id']) ? 'user #'.$claims['user_id'] : 'Anonymous'));
            if ($this->store instanceof PdoStore) {
                $this->store->appendSystemNote($sessionId, 'AI could not answer - escalated automatically. Provider error: '.($result->error !== '' ? $result->error : 'unknown'));
                $this->store->setMode($sessionId, 'agent', $label);
                if ($this->notifier) {
                    try { $this->notifier->escalated($sessionId, $label, 'The assistant could not answer (provider error) - a person is needed.'); } catch (\Throwable $e) { /* notify never breaks the reply */ }
                }
            }
            return [
                'ok' => true,
                'session_id' => $sessionId,
                'reply' => "I'm having trouble reaching our assistant right now, so I've passed you to a member of our team - they'll reply here shortly.",
                'mode' => 'agent',
                'error' => null,
            ];
        }

        $this->store->save($sessionId, $state, $identityHash);
        $this->recordVisitor($sessionId, $claims, (array) ($input['visitor'] ?? []));

        // the model called escalate_to_human -> flip to agent mode + notify
        $mode = 'ai';
        if (in_array(EscalateTool::NAME, $result->toolsUsed(), true) && $this->store instanceof PdoStore) {
            $label = (string) ($claims['name'] ?? $claims['email'] ?? (isset($claims['user_id']) ? 'user #'.$claims['user_id'] : 'Anonymous'));
            $this->store->setMode($sessionId, 'agent', $label);
            $mode = 'agent';
            if ($this->notifier) {
                $reason = '';
                foreach ($result->toolTrace as $t) {
                    if (($t['tool'] ?? '') === EscalateTool::NAME) {
                        $reason = (string) ($t['args']['reason'] ?? '');
                    }
                }
                try { $this->notifier->escalated($sessionId, $label, $reason); } catch (\Throwable $e) { /* notify never breaks the reply */ }
            }
        }

        return [
            'ok' => true,
            'session_id' => $sessionId,
            'reply' => $result->text,
            'mode' => $mode,
            'error' => null,
        ];
    }
}
