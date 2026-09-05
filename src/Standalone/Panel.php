<?php

namespace Banimark\Standalone;

use Banimark\Auth\AgentAuth;
use Banimark\Auth\Agents;
use Banimark\Licensing\Master;
use Banimark\Notify\FollowUp;
use Banimark\Notify\MailerFactory;
use Banimark\Storage\Analytics;
use Banimark\Ui\Chart;
use Banimark\Ui\Layout;
use Banimark\Storage\PdoStore;
use Banimark\Tools\SqlTool;

/**
 * The standalone admin panel - same features as the Laravel one (inbox +
 * human takeover, Tool Builder with save-time validation, rules, providers,
 * widget settings) rendered without any framework. Session login with the
 * installer's password; every mutating POST is CSRF-checked.
 */
class Panel
{
    private PdoStore $store;

    private Agents $agents;

    public function __construct(
        private \PDO $pdo,
        private Settings $settings,
        private AgentAuth $auth,
        private string $base,
    ) {
        $this->store = new PdoStore($pdo);
        $this->agents = new Agents($pdo);
    }

    private function url(string $path = ''): string
    {
        return $this->base.'/admin'.$path;
    }

    public function dispatch(string $route): void
    {
        // login / logout
        if ($route === '/login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if ($this->auth->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                header('Location: '.$this->url());
                return;
            }
            echo $this->login('Wrong email or password.');
            return;
        }
        if (!$this->auth->sessionValid()) {
            echo $this->login();
            return;
        }
        if ($route === '/logout') {
            $this->auth->logout();
            header('Location: '.$this->url());
            return;
        }

        // CSRF on every mutation
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$this->auth->csrfOk($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            echo Html::page('Expired', '<div class="bm-card"><h2>Session expired</h2><p><a href="'.Html::e($this->url()).'">Back</a></p></div>');
            return;
        }

        // license lock: no valid license = no admin, pages AND actions. The
        // verdict comes from AgentAuth->lockReason() (encoded Master), not a
        // local call, so it cannot be stripped here. Widget/chat is never gated.
        if (!in_array($route, ['/license', '/logout'], true) && $this->auth->lockReason() !== null) {
            header('Location: '.$this->url('/license'));
            return;
        }

        $flash = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $flash = $this->handlePost($route);
            if ($flash === null) {
                return; // redirected
            }
        }

        echo match (true) {
            $route === '/' || $route === '' => $this->dashboard($flash),
            $route === '/inbox' => $this->inbox($flash),
            str_starts_with($route, '/conversation/') => $this->conversation(substr($route, 14), $flash),
            $route === '/tools' => $this->tools($flash),
            $route === '/rules' => $this->rules($flash),
            $route === '/providers' => $this->providers($flash),
            $route === '/agents' => $this->agentsPage($flash),
            $route === '/escalation', $route === '/escalation/test' => $this->escalationPage($flash),
            $route === '/widget' => $this->widget($flash),
            $route === '/license' => $this->licensePage($flash),
            default => Html::page('Not found', '<div class="bm-card"><h2>Not found</h2></div>', $this->nav()),
        };
    }

    /** @return string|null flash html, or null when a redirect was sent */
    private function handlePost(string $route): ?string
    {
        $p = $_POST;
        if (preg_match('#^/conversation/([a-f0-9]{32})/reply$#', $route, $m)) {
            $text = trim((string) ($p['message'] ?? ''));
            if ($text !== '') {
                $this->store->appendAgentMessage($m[1], $text);
                // the visitor may have closed the tab - post the reply on to them
                try {
                    (new FollowUp($this->store, MailerFactory::make($this->settings->all()), $this->settings->all()))
                        ->afterAgentReply($m[1], $text);
                } catch (\Throwable $e) { /* a mail problem must never lose the reply */ }
            }
            header('Location: '.$this->url('/conversation/'.$m[1]));
            return null;
        }
        if (preg_match('#^/conversation/([a-f0-9]{32})/mode$#', $route, $m)) {
            $mode = in_array($p['mode'] ?? '', ['ai', 'agent', 'closed'], true) ? $p['mode'] : 'ai';
            $this->store->setMode($m[1], $mode);
            header('Location: '.$this->url('/conversation/'.$m[1]));
            return null;
        }
        if ($route === '/tools') {
            return $this->saveTool($p);
        }
        if ($route === '/tools/delete') {
            $this->exec('DELETE FROM banimark_tools WHERE name = ?', [(string) ($p['name'] ?? '')]);
            header('Location: '.$this->url('/tools'));
            return null;
        }
        if ($route === '/rules') {
            $title = trim((string) ($p['title'] ?? ''));
            $content = trim((string) ($p['content'] ?? ''));
            if ($title === '' || $content === '') {
                return '<div class="flash-err">Title and content are required.</div>';
            }
            $this->exec('INSERT INTO banimark_rules (title, content, sort, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$title, $content, (int) ($p['sort'] ?? 0), !empty($p['enabled']) ? 1 : 0, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            return '<div class="flash-ok">Rule saved.</div>';
        }
        if ($route === '/rules/delete') {
            $this->exec('DELETE FROM banimark_rules WHERE id = ?', [(int) ($p['id'] ?? 0)]);
            header('Location: '.$this->url('/rules'));
            return null;
        }
        if ($route === '/providers') {
            return $this->saveProvider($p);
        }
        if ($route === '/providers/delete') {
            $this->exec('DELETE FROM banimark_providers WHERE slug = ?', [(string) ($p['slug'] ?? '')]);
            header('Location: '.$this->url('/providers'));
            return null;
        }
        if ($route === '/agents') {
            if (!$this->auth->isOwner()) {
                return '<div class="flash-err">Only an owner can manage staff.</div>';
            }
            $ok = $this->agents->create((string) ($p['name'] ?? ''), (string) ($p['email'] ?? ''), (string) ($p['password'] ?? ''), ($p['role'] ?? '') === 'owner' ? 'owner' : 'agent');
            if ($ok === false) {
                return '<div class="flash-err">That email is already a staff account, or the details are invalid.</div>';
            }
            return '<div class="flash-ok">Staff account added.</div>';
        }
        if ($route === '/agents/delete') {
            if ($this->auth->isOwner()) {
                $this->agents->delete((int) ($p['id'] ?? 0));
            }
            header('Location: '.$this->url('/agents'));
            return null;
        }
        if ($route === '/escalation') {
            $this->settings->set('escalation_mode', ($p['escalation_mode'] ?? '') === 'email' ? 'email' : 'staff');
            $this->settings->set('escalation_email', trim((string) ($p['escalation_email'] ?? '')));
            $this->settings->set('smtp_enabled', !empty($p['smtp_enabled']) ? '1' : '0');
            $this->settings->set('smtp_host', trim((string) ($p['smtp_host'] ?? '')));
            $this->settings->set('smtp_port', (string) max(1, min(65535, (int) ($p['smtp_port'] ?? 587))));
            $this->settings->set('smtp_user', trim((string) ($p['smtp_user'] ?? '')));
            $this->settings->set('smtp_encryption', in_array($p['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $p['smtp_encryption'] : 'tls');
            $this->settings->set('smtp_from_email', trim((string) ($p['smtp_from_email'] ?? '')));
            $this->settings->set('smtp_from_name', trim((string) ($p['smtp_from_name'] ?? '')) ?: 'Support');
            // blank keeps the stored password, like the AI provider keys
            if (trim((string) ($p['smtp_pass'] ?? '')) !== '') {
                $this->settings->set('smtp_pass', (string) $p['smtp_pass']);
            }
            $this->settings->set('visitor_followup', !empty($p['visitor_followup']) ? '1' : '0');
            $this->settings->set('visitor_followup_after', (string) max(30, (int) ($p['visitor_followup_after'] ?? 120)));
            return '<div class="flash-ok">Notification settings saved.</div>';
        }
        if ($route === '/escalation/test') {
            $to = trim((string) ($p['test_email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return '<div class="flash-err">Enter a valid address to send the test to.</div>';
            }
            $mailer = MailerFactory::make($this->settings->all());
            $ok = $mailer->send([$to], 'Banimark test email',
                "This is a test from your Banimark support desk.\n\nIf you are reading it, escalation alerts and visitor follow-ups will send correctly.");
            return $ok
                ? '<div class="flash-ok">Test email sent to '.Html::e($to).'.</div>'
                : '<div class="flash-err">Could not send: '.Html::e($mailer->lastError() ?: 'unknown error').'</div>';
        }
        if ($route === '/widget') {
            $this->settings->set('color', preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($p['color'] ?? '')) ? $p['color'] : '#6F04D9');
            $this->settings->set('position', ($p['position'] ?? '') === 'left' ? 'left' : 'right');
            $this->settings->set('title', mb_substr(trim((string) ($p['title'] ?? '')), 0, 60) ?: 'Support');
            $this->settings->set('greeting', mb_substr(trim((string) ($p['greeting'] ?? '')), 0, 300));
            $this->settings->set('poll_seconds', (string) max(3, min(600, (int) ($p['poll_seconds'] ?? 10) ?: 10)));
            $this->settings->set('guest_mode', in_array($p['guest_mode'] ?? '', ['off', 'optional', 'required'], true) ? $p['guest_mode'] : 'off');
            $this->settings->set('offline_note', mb_substr(trim((string) ($p['offline_note'] ?? '')), 0, 200));
            return '<div class="flash-ok">Widget saved.</div>';
        }
        if ($route === '/license') {
            $key = trim((string) ($p['license_key'] ?? ''));
            $this->settings->set('license_key', $key);
            // hq_url is not a panel field - support overrides it directly if ever needed
            if ($key === '') {
                $this->settings->set('license_token', '');
                return '<div class="flash-ok">License settings saved.</div>';
            }
            // immediate check so the result shows right away
            $result = (new Master(
                (string) ($this->settings->get('hq_url', '') ?: Master::DEFAULT_ENDPOINT),
                $key,
                Master::siteUrlFromServer($_SERVER),
            ))->ping();
            $this->settings->set('license_last_ping', (string) time());
            $this->settings->set('license_status', $result['license']);
            $this->settings->set('license_token', (string) ($result['token'] ?? ''));
            return '<div class="flash-ok">License saved - status: <b>'.Html::e($result['license']).'</b>'
                .($result['message'] !== '' ? ' · '.Html::e($result['message']) : '').'</div>';
        }
        return '';
    }

    private function saveTool(array $p): string
    {
        $params = [];
        foreach ((array) ($p['param_name'] ?? []) as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $params[$name] = [
                'type' => in_array($p['param_type'][$i] ?? '', ['string', 'integer', 'number', 'boolean'], true) ? $p['param_type'][$i] : 'string',
                'description' => trim((string) ($p['param_desc'][$i] ?? '')),
                'required' => !empty($p['param_required'][$i]),
            ];
        }
        $definition = [
            'name' => strtolower(trim((string) ($p['name'] ?? ''))),
            'description' => trim((string) ($p['description'] ?? '')),
            'parameters' => $params,
            'sql' => trim((string) ($p['sql'] ?? '')),
            'columns' => array_values(array_filter(array_map('trim', explode(',', (string) ($p['columns'] ?? ''))))),
            'context' => array_values(array_filter(array_map('trim', explode(',', (string) ($p['context'] ?? ''))))),
            'max_rows' => max(1, min(50, (int) ($p['max_rows'] ?? 10))),
        ];
        try {
            SqlTool::fromDefinition($definition, fn () => []);
        } catch (\Throwable $e) {
            return '<div class="flash-err">'.Html::e($e->getMessage()).'</div>';
        }
        $this->exec('DELETE FROM banimark_tools WHERE name = ?', [$definition['name']]);
        $this->exec('INSERT INTO banimark_tools (name, description, parameters, `sql`, columns, context, max_rows, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $definition['name'], $definition['description'], json_encode($definition['parameters']),
            $definition['sql'], json_encode($definition['columns']), json_encode($definition['context']),
            $definition['max_rows'], 1, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);
        return '<div class="flash-ok">Tool "'.Html::e($definition['name']).'" saved and validated.</div>';
    }

    private function saveProvider(array $p): string
    {
        $slug = strtolower(trim((string) ($p['slug'] ?? '')));
        $model = trim((string) ($p['model'] ?? ''));
        if (!preg_match('/^[a-z0-9\-]+$/', $slug) || $model === '') {
            return '<div class="flash-err">Slug (lowercase) and model are required.</div>';
        }
        $driver = in_array($p['driver'] ?? '', ['gemini', 'openai-compat', 'anthropic'], true) ? $p['driver'] : 'openai-compat';
        $existing = $this->query('SELECT api_key FROM banimark_providers WHERE slug = ?', [$slug])[0] ?? null;
        $key = trim((string) ($p['api_key'] ?? ''));
        if ($key === '' && $existing === null) {
            return '<div class="flash-err">An API key is required for a new provider.</div>';
        }
        $this->exec('DELETE FROM banimark_providers WHERE slug = ?', [$slug]);
        $this->exec('INSERT INTO banimark_providers (slug, driver, api_key, model, base_url, temperature, enabled, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)', [
            $slug, $driver, $key !== '' ? $key : $existing['api_key'], $model,
            trim((string) ($p['base_url'] ?? '')) ?: null,
            max(0, min(2, (float) ($p['temperature'] ?? 0.4))),
            !empty($p['enabled']) ? 1 : 0,
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);
        if (!empty($p['is_default'])) {
            $this->exec('UPDATE banimark_providers SET is_default = 0', []);
            $this->exec('UPDATE banimark_providers SET is_default = 1 WHERE slug = ?', [$slug]);
        }
        return '<div class="flash-ok">Provider saved.</div>';
    }

    /* ---------------- pages ---------------- */

    /** Sidebar links, with the current section highlighted. */
    private function nav(string $current = ''): string
    {
        // grouped by MODULE - Support Desk is the first of several
        $items = [
            ['Support Desk'],
            ['/', 'dashboard', 'Dashboard'],
            ['/inbox', 'inbox', 'Inbox'],
            ['/tools', 'tools', 'Tools'],
            ['/rules', 'rules', 'Rules'],
            ['/providers', 'providers', 'AI providers'],
            ['/widget', 'widget', 'Widget'],
            ['/escalation', 'escalation', 'Notifications'],
            ['Account'],
        ];
        if ($this->auth->isOwner()) {
            $items[] = ['/agents', 'staff', 'Staff'];
        }
        $items[] = ['/license', 'license', 'License'];

        $out = '';
        foreach ($items as $it) {
            if (count($it) === 1) {
                $out .= '<span class="lbl">'.Html::e($it[0]).'</span>';
                continue;
            }
            [$path, $icon, $label] = $it;
            $out .= Layout::navLink([
                'href' => $this->url($path === '/' ? '' : $path),
                'icon' => $icon, 'label' => $label, 'on' => $current === $path,
            ]);
        }
        return $out.'<span class="lbl">Session</span>'
            .Layout::navLink(['href' => $this->url('/logout'), 'icon' => 'logout', 'label' => 'Sign out']);
    }

    private function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="'.Html::e($this->auth->csrf()).'">';
    }

    /** The dashboard: the numbers a desk owner opens the panel for. */
    private function dashboard(string $flash): string
    {
        $a = (new Analytics($this->pdo))->overview();
        $tiles = Layout::stat('Conversations', number_format($a['conversations']), 'chat', $a['week_delta'],
                $a['week_delta'] === null ? 'all time' : 'vs last week',
                Chart::spark(array_column($a['series'], 'conversations')))
            .Layout::stat('Today', number_format($a['conversations_today']), 'clock', null, 'new conversations')
            .Layout::stat('Avg messages', (string) $a['avg_messages'], 'inbox', null, 'per conversation')
            .Layout::stat('Escalation rate', $a['escalation_rate'].'%', 'escalation', null, 'handed to a human');

        $rows = '';
        foreach ($this->store->listConversations(6) as $r) {
            $rows .= '<tr><td><div class="row"><span class="avatar">'.Html::e(strtoupper(substr($r['visitor_label'] ?: 'A', 0, 1))).'</span>'
                .'<span><b>'.Html::e($r['visitor_label'] ?: 'Anonymous').'</b>'
                .'<div class="muted mono" style="background:none;padding:0">'.Html::e(substr($r['session_id'], 0, 8)).'</div></span></div></td>'
                .'<td><span class="pill '.Html::e($r['mode']).'">'.strtoupper(Html::e($r['mode'])).'</span></td>'
                .'<td style="font-variant-numeric:tabular-nums">'.(int) $r['message_count'].'</td>'
                .'<td class="muted">'.Html::e(mb_substr((string) $r['last_message'], 0, 58)).'</td>'
                .'<td class="muted">'.($r['last_message_at'] ? date('d M H:i', (int) $r['last_message_at']) : '-').'</td>'
                .'<td><a class="btn2 btn-sm" href="'.Html::e($this->url('/conversation/'.$r['session_id'])).'">Open</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">'.Chart::empty('No conversations yet', 'Embed the widget on your site and say hello.').'</td></tr>';
        }

        $body = $flash
            .'<div class="bm-grid c4" style="margin-bottom:16px">'.$tiles.'</div>'
            .'<div class="bm-grid main">'
            .'<div class="bm-card"><div class="bm-sec-h"><div><h2>Activity</h2>'
            .'<div class="muted">Conversations started and messages exchanged, last 14 days</div></div></div>'
            .Chart::area(array_column($a['series'], 'label'), [
                ['name' => 'Conversations', 'color' => 'var(--s1)', 'values' => array_column($a['series'], 'conversations')],
                ['name' => 'Messages', 'color' => 'var(--s2)', 'values' => array_column($a['series'], 'messages')],
            ]).'</div><div>'
            .'<div class="bm-card"><h2>Who is handling chats</h2><div class="muted">Current state of every conversation</div>'
            .Chart::stack([
                ['name' => 'AI', 'value' => $a['modes']['ai'], 'color' => 'var(--s1)'],
                ['name' => 'Human', 'value' => $a['modes']['agent'], 'color' => 'var(--s2)'],
                ['name' => 'Closed', 'value' => $a['modes']['closed'], 'color' => 'var(--surface-3)'],
            ]).'</div>'
            .'<div class="bm-card"><h2>Tool usage</h2><div class="muted">'.number_format($a['tool_calls']).' lookups run against your data</div>'
            .'<div style="margin-top:14px">'.Chart::hbars($a['tools'], 'var(--s3)', 'No tools called yet').'</div></div>'
            .'</div></div>'
            .'<div class="bm-card pad0"><div class="bm-sec-h" style="padding:18px 20px 0"><div><h2>Latest conversations</h2>'
            .'<div class="muted">Newest first</div></div><div class="spacer"></div>'
            .'<a class="btn2 btn-sm" href="'.Html::e($this->url('/inbox')).'">View all</a></div>'
            .'<div class="t-wrap"><table><tr><th>Visitor</th><th>State</th><th>Messages</th><th>Last message</th><th>Activity</th><th></th></tr>'
            .$rows.'</table></div></div>';

        return Html::page('Dashboard', $body, $this->nav('/'), 'How your AI desk is performing');
    }

    private function login(string $error = ''): string
    {
        return Html::auth($this->url('/login'), $error);
    }

    private function inbox(string $flash): string
    {
        $mode = in_array($_GET['mode'] ?? '', ['ai', 'agent', 'closed'], true) ? $_GET['mode'] : null;
        $rows = '';
        foreach ($this->store->listConversations(100, $mode) as $r) {
            $rows .= '<tr><td>'.Html::e($r['visitor_label'] ?: 'Anonymous').'<br><span class="muted">'.Html::e(substr($r['session_id'], 0, 8)).'…</span></td>'
                .'<td><span class="pill '.Html::e($r['mode']).'">'.strtoupper(Html::e($r['mode'])).'</span></td>'
                .'<td>'.(int) $r['message_count'].'</td>'
                .'<td class="muted">'.Html::e(mb_substr((string) $r['last_message'], 0, 70)).'</td>'
                .'<td class="muted">'.($r['last_message_at'] ? date('d M H:i', (int) $r['last_message_at']) : '-').'</td>'
                .'<td><a class="btn btn2" href="'.Html::e($this->url('/conversation/'.$r['session_id'])).'">Open</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="muted">No conversations yet. Embed the widget and say hello.</td></tr>';
        }
        return Html::page('Inbox', $flash.'<div class="bm-card"><h2>Conversations</h2>'
            .'<div class="muted"><a href="'.Html::e($this->url()).'">All</a> · <a href="'.Html::e($this->url().'?mode=agent').'">Needs a human</a> · <a href="'.Html::e($this->url().'?mode=ai').'">AI handled</a> · <a href="'.Html::e($this->url().'?mode=closed').'">Closed</a></div>'
            .'<table><tr><th>Visitor</th><th>Mode</th><th>Msgs</th><th>Last message</th><th>Activity</th><th></th></tr>'.$rows.'</table></div>', $this->nav('/inbox'));
    }

    private function conversation(string $sessionId, string $flash): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return Html::page('Not found', '<div class="bm-card"><h2>Not found</h2></div>', $this->nav('/inbox'));
        }
        $mode = $this->store->mode($sessionId);
        $msgs = '';
        foreach ($this->store->transcript($sessionId) as $m) {
            $payload = $m['payload'] ? (json_decode($m['payload'], true) ?: []) : [];
            if ($m['role'] === 'tool') {
                $msgs .= '<div class="msg tool">tool '.Html::e($payload['for_call']['name'] ?? '').' → '.Html::e(mb_substr(json_encode($payload['tool_result'] ?? []), 0, 220)).'</div>';
            } elseif ($m['role'] === 'assistant' && !empty($payload['tool_calls'])) {
                $msgs .= '<div class="msg tool">AI calls: '.Html::e(implode(', ', array_column($payload['tool_calls'], 'name'))).'</div>';
            } else {
                $msgs .= '<div class="msg '.Html::e($m['role']).'">'.Html::e($m['content'])
                    .($m['role'] === 'agent' ? '<div class="muted" style="font-size:10px;">agent</div>' : '').'</div>';
            }
        }
        $modeBtn = $mode !== 'agent'
            ? '<form method="post" action="'.Html::e($this->url('/conversation/'.$sessionId.'/mode')).'" style="display:inline;">'.$this->csrfField().'<input type="hidden" name="mode" value="agent"><button class="btn2">Take over</button></form>'
            : '<form method="post" action="'.Html::e($this->url('/conversation/'.$sessionId.'/mode')).'" style="display:inline;">'.$this->csrfField().'<input type="hidden" name="mode" value="ai"><button class="btn2">Hand back to AI</button></form>';
        return Html::page('Conversation', $flash.'<div class="bm-card">'
            .'<h2 style="display:flex;align-items:center;gap:10px;">Conversation <span class="pill '.Html::e($mode).'">'.strtoupper(Html::e($mode)).'</span>'
            .'<span style="margin-left:auto;display:flex;gap:8px;">'.$modeBtn
            .'<form method="post" action="'.Html::e($this->url('/conversation/'.$sessionId.'/mode')).'" style="display:inline;">'.$this->csrfField().'<input type="hidden" name="mode" value="closed"><button class="btn-danger">Close</button></form></span></h2>'
            .'<div class="muted">Replying takes the conversation over - the AI stays silent until you hand it back.</div>'
            .'<div class="msgs" style="margin-top:14px;">'.$msgs.'</div>'
            .'<form method="post" action="'.Html::e($this->url('/conversation/'.$sessionId.'/reply')).'" style="display:flex;gap:8px;margin-top:14px;">'.$this->csrfField()
            .'<input type="text" name="message" placeholder="Reply as a human agent..." style="flex:1;" autofocus>'
            .'<button type="submit">Send</button></form></div>', $this->nav('/inbox'));
    }

    private function tools(string $flash): string
    {
        $rows = '';
        foreach ($this->query('SELECT * FROM banimark_tools ORDER BY id', []) as $r) {
            $rows .= '<tr><td><b>'.Html::e($r['name']).'</b></td><td class="muted">'.Html::e(mb_substr($r['description'], 0, 120)).'</td>'
                .'<td class="muted">'.Html::e(implode(', ', array_keys(json_decode($r['parameters'], true) ?: []))).'</td>'
                .'<td>'.(int) $r['max_rows'].'</td>'
                .'<td><form method="post" action="'.Html::e($this->url('/tools/delete')).'">'.$this->csrfField().'<input type="hidden" name="name" value="'.Html::e($r['name']).'"><button class="btn-danger" onclick="return confirm(\'Delete tool?\')">×</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted">No tools yet - the AI can chat but cannot look anything up.</td></tr>';
        }
        $paramRows = '';
        for ($i = 0; $i < 4; $i++) {
            $paramRows .= '<div style="display:flex;gap:8px;margin-bottom:6px;">'
                .'<input type="text" name="param_name['.$i.']" placeholder="name e.g. reference" style="flex:2;">'
                .'<select name="param_type['.$i.']" style="flex:1;"><option>string</option><option>integer</option><option>number</option><option>boolean</option></select>'
                .'<input type="text" name="param_desc['.$i.']" placeholder="description for the AI" style="flex:3;">'
                .'<label style="margin:8px 0 0;"><input type="checkbox" name="param_required['.$i.']" value="1"> req</label></div>';
        }
        return Html::page('Tools', $flash.'<div class="bm-card"><h2>Tools</h2>'
            .'<div class="muted">Read-only lookups against YOUR database. Values are always bound; <code>:_key</code> placeholders come from the signed visitor identity and can never be set by the AI.</div>'
            .'<table><tr><th>Name</th><th>Description</th><th>Params</th><th>Rows</th><th></th></tr>'.$rows.'</table></div>'
            .'<div class="bm-card"><h2>Build a tool</h2>'
            .'<form method="post" action="'.Html::e($this->url('/tools')).'">'.$this->csrfField()
            .'<div class="grid2"><div><label>Name (a-z, 0-9, _)</label><input type="text" name="name" required placeholder="search_order"></div>'
            .'<div><label>Max rows returned</label><input type="number" name="max_rows" value="10"></div></div>'
            .'<label>Description - what the AI reads to decide when to call it</label><textarea name="description" required></textarea>'
            .'<label>Parameters</label>'.$paramRows
            .'<label>SQL - SELECT only, :param for AI values, :_key for identity values</label>'
            .'<textarea name="sql" required placeholder="SELECT reference, status, total FROM orders WHERE reference = :reference AND user_id = :_user_id"></textarea>'
            .'<div class="grid2"><div><label>Columns the AI may see (comma separated)</label><input type="text" name="columns" required placeholder="reference, status, total"></div>'
            .'<div><label>Identity context keys used (comma separated)</label><input type="text" name="context" value="user_id"></div></div>'
            .'<div style="margin-top:12px;"><button type="submit">Validate &amp; save tool</button></div></form></div>', $this->nav('/tools'));
    }

    private function rules(string $flash): string
    {
        $rows = '';
        foreach ($this->query('SELECT * FROM banimark_rules ORDER BY sort, id', []) as $r) {
            $rows .= '<tr><td>'.(int) $r['sort'].'</td><td><b>'.Html::e($r['title']).'</b></td>'
                .'<td class="muted" style="white-space:pre-wrap;">'.Html::e(mb_substr($r['content'], 0, 220)).'</td>'
                .'<td>'.($r['enabled'] ? 'on' : 'off').'</td>'
                .'<td><form method="post" action="'.Html::e($this->url('/rules/delete')).'">'.$this->csrfField().'<input type="hidden" name="id" value="'.(int) $r['id'].'"><button class="btn-danger" onclick="return confirm(\'Delete rule?\')">×</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted">No rules yet - the AI runs on the built-in base instruction only.</td></tr>';
        }
        return Html::page('Rules', $flash.'<div class="bm-card"><h2>Rules</h2>'
            .'<div class="muted">Every enabled rule joins the AI\'s system instruction, in sort order.</div>'
            .'<table><tr><th>#</th><th>Title</th><th>Content</th><th>Status</th><th></th></tr>'.$rows.'</table></div>'
            .'<div class="bm-card"><h2>Add a rule</h2>'
            .'<form method="post" action="'.Html::e($this->url('/rules')).'">'.$this->csrfField()
            .'<div class="grid2"><div><label>Title</label><input type="text" name="title" required></div>'
            .'<div><label>Sort</label><input type="number" name="sort" value="0"></div></div>'
            .'<label>Content</label><textarea name="content" required></textarea>'
            .'<label><input type="checkbox" name="enabled" value="1" checked> Enabled</label>'
            .'<div style="margin-top:12px;"><button type="submit">Save rule</button></div></form></div>', $this->nav('/rules'));
    }

    private function providers(string $flash): string
    {
        $rows = '';
        foreach ($this->query('SELECT * FROM banimark_providers ORDER BY id', []) as $r) {
            $rows .= '<tr><td><b>'.Html::e($r['slug']).'</b> '.($r['is_default'] ? '<span class="pill ai">DEFAULT</span>' : '').'</td>'
                .'<td>'.Html::e($r['driver']).'</td><td>'.Html::e($r['model']).'</td>'
                .'<td class="muted">'.Html::e($r['base_url'] ?: '-').'</td><td>'.Html::e($r['temperature']).'</td>'
                .'<td>'.($r['enabled'] ? 'enabled' : 'disabled').'</td>'
                .'<td><form method="post" action="'.Html::e($this->url('/providers/delete')).'">'.$this->csrfField().'<input type="hidden" name="slug" value="'.Html::e($r['slug']).'"><button class="btn-danger" onclick="return confirm(\'Remove?\')">×</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="muted">No providers yet - the chat cannot answer until you add one.</td></tr>';
        }
        return Html::page('AI Providers', $flash.'<div class="bm-card"><h2>AI Providers</h2>'
            .'<div class="muted">The default enabled provider answers the widget. Keys are stored server-side and never shown again.</div>'
            .'<table><tr><th>Slug</th><th>Driver</th><th>Model</th><th>Base URL</th><th>Temp</th><th>Status</th><th></th></tr>'.$rows.'</table></div>'
            .'<div class="bm-card"><h2>Add / update a provider</h2>'
            .'<form method="post" action="'.Html::e($this->url('/providers')).'">'.$this->csrfField()
            .'<div class="grid2"><div><label>Slug</label><input type="text" name="slug" required placeholder="gemini"></div>'
            .'<div><label>Driver</label><select name="driver"><option value="gemini">gemini</option><option value="openai-compat">openai-compat (OpenAI / DeepSeek / SiliconFlow / local)</option><option value="anthropic">anthropic</option></select></div>'
            .'<div><label>Model</label><input type="text" name="model" required placeholder="gemini-2.5-flash"></div>'
            .'<div><label>Base URL (openai-compat only)</label><input type="text" name="base_url" placeholder="https://api.deepseek.com"></div>'
            .'<div><label>API key (blank keeps existing)</label><input type="password" name="api_key" autocomplete="new-password"></div>'
            .'<div><label>Temperature</label><input type="number" name="temperature" step="0.05" value="0.4"></div></div>'
            .'<label><input type="checkbox" name="enabled" value="1" checked> Enabled</label>'
            .'<label><input type="checkbox" name="is_default" value="1"> Make default</label>'
            .'<div style="margin-top:12px;"><button type="submit">Save provider</button></div></form></div>', $this->nav('/providers'));
    }

    private function widget(string $flash): string
    {
        $s = $this->settings;
        $widgetUrl = Html::e($this->base.'/widget.js');
        return Html::page('Widget', $flash.'<div class="bm-card"><h2>Chat widget</h2>'
            .'<form method="post" action="'.Html::e($this->url('/widget')).'">'.$this->csrfField()
            .'<div class="grid2"><div><label>Accent color</label><input type="text" name="color" value="'.Html::e($s->get('color', '#6F04D9')).'"></div>'
            .'<div><label>Position</label><select name="position"><option value="right"'.($s->get('position') === 'right' ? ' selected' : '').'>right</option><option value="left"'.($s->get('position') === 'left' ? ' selected' : '').'>left</option></select></div>'
            .'<div><label>Header title</label><input type="text" name="title" value="'.Html::e($s->get('title', 'Support')).'"></div>'
            .'<div><label>Greeting bubble</label><input type="text" name="greeting" value="'.Html::e($s->get('greeting', '')).'"></div></div>'
            .'<div class="divider"></div><div class="grid2">'
            .'<div><label>Check for replies every</label><div class="row">'
            .'<input type="number" name="poll_seconds" min="3" max="600" value="'.Html::e($s->get('poll_seconds', '10')).'" style="max-width:120px">'
            .'<span class="muted">seconds</span></div>'
            .'<div class="hint">Only while the chat is open. This is also the visitor\'s heartbeat.</div></div>'
            .'<div><label>Ask guests who they are</label><select name="guest_mode">'
            .'<option value="off"'.($s->get('guest_mode', 'off') === 'off' ? ' selected' : '').'>Off - chat straight away</option>'
            .'<option value="optional"'.($s->get('guest_mode') === 'optional' ? ' selected' : '').'>Optional - offer, allow skip</option>'
            .'<option value="required"'.($s->get('guest_mode') === 'required' ? ' selected' : '').'>Required - name &amp; email first</option>'
            .'</select><div class="hint">An email address is what lets us follow up when they leave.</div></div></div>'
            .'<label>Note shown when nobody is around <span class="muted">(optional)</span></label>'
            .'<input type="text" name="offline_note" value="'.Html::e($s->get('offline_note', '')).'" placeholder="We usually reply within a few hours.">'
            .'<div style="margin-top:12px;"><button type="submit">Save widget</button></div></form></div>'
            .'<div class="bm-card"><h2>Embed</h2>'
            .'<div class="muted">Anonymous visitors:</div>'
            .'<textarea readonly rows="2">&lt;script src="'.$widgetUrl.'" defer&gt;&lt;/script&gt;</textarea>'
            .'<div class="muted" style="margin-top:10px;">Logged-in users - mint a token server-side with your identity secret (settings key identity_secret) via \\Banimark\\Identity\\VisitorToken::mint([\'user_id\' =&gt; $userId], $secret) and pass it as data-token on the script tag.</div>'
            .'</div>', $this->nav('/widget'));
    }

    private function agentsPage(string $flash): string
    {
        if (!$this->auth->isOwner()) {
            return Html::page('Staff', '<div class="bm-card"><h2>Staff</h2><p class="muted">Only an owner can manage staff.</p></div>', $this->nav('/agents'));
        }
        $rows = '';
        foreach ($this->agents->all() as $a) {
            $rows .= '<tr><td><b>'.Html::e($a['name']).'</b></td><td>'.Html::e($a['email']).'</td>'
                .'<td><span class="pill '.($a['role'] === 'owner' ? 'ai' : 'agent').'">'.strtoupper(Html::e($a['role'])).'</span></td>'
                .'<td>'.($a['enabled'] ? 'active' : 'disabled').'</td>'
                .'<td><form method="post" action="'.Html::e($this->url('/agents/delete')).'">'.$this->csrfField().'<input type="hidden" name="id" value="'.(int) $a['id'].'"><button class="btn-danger" onclick="return confirm(\'Remove this staff account?\')">×</button></form></td></tr>';
        }
        return Html::page('Staff', $flash.'<div class="bm-card"><h2>Staff</h2>'
            .'<div class="muted">Staff can attend to escalated conversations from the inbox. Owners can also manage staff and settings.</div>'
            .'<table><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr>'.$rows.'</table></div>'
            .'<div class="bm-card"><h2>Add staff</h2>'
            .'<form method="post" action="'.Html::e($this->url('/agents')).'">'.$this->csrfField()
            .'<div class="grid2"><div><label>Name</label><input type="text" name="name" required></div>'
            .'<div><label>Email (their login)</label><input type="text" name="email" required></div>'
            .'<div><label>Password (min 8)</label><input type="password" name="password" required></div>'
            .'<div><label>Role</label><select name="role"><option value="agent">Agent (handles chats)</option><option value="owner">Owner (full control)</option></select></div></div>'
            .'<div style="margin-top:12px;"><button type="submit">Add staff</button></div></form></div>', $this->nav('/agents'));
    }

    private function escalationPage(string $flash): string
    {
        $g = fn (string $k, string $d = '') => Html::e($this->settings->get($k, $d));
        $mode = $this->settings->get('escalation_mode', 'staff');
        $enc = $this->settings->get('smtp_encryption', 'tls');
        $sel = fn (string $v) => $enc === $v ? ' selected' : '';
        $hasPass = trim((string) $this->settings->get('smtp_pass', '')) !== '';

        $body = $flash.'<div class="bm-grid c2"><div><div class="bm-card">'
            .'<h2>When the AI hands over</h2>'
            .'<div class="muted">What should happen the moment a conversation needs a human.</div>'
            .'<form method="post" id="notify-form" action="'.Html::e($this->url('/escalation')).'">'.$this->csrfField()
            .'<label style="display:flex;gap:10px;align-items:flex-start;padding:13px;border:1px solid var(--border-2);border-radius:var(--r);margin:14px 0 10px;cursor:pointer">'
            .'<input type="radio" name="escalation_mode" value="staff"'.($mode !== 'email' ? ' checked' : '').' style="margin-top:2px">'
            .'<span><b style="color:var(--text)">Staff inbox</b><div class="muted">It appears in the inbox for any staff member to pick up. (Default)</div></span></label>'
            .'<label style="display:flex;gap:10px;align-items:flex-start;padding:13px;border:1px solid var(--border-2);border-radius:var(--r);cursor:pointer">'
            .'<input type="radio" name="escalation_mode" value="email"'.($mode === 'email' ? ' checked' : '').' style="margin-top:2px">'
            .'<span><b style="color:var(--text)">Email alert</b><div class="muted">Email your team as well. Needs the SMTP settings alongside.</div></span></label>'
            .'<label>Alert these addresses <span class="muted">(comma separated - blank means all staff)</span></label>'
            .'<input type="text" name="escalation_email" value="'.$g('escalation_email').'" placeholder="support@yourco.com">'
            .'<div class="divider"></div><h2>Visitor follow-up</h2>'
            .'<div class="muted">The widget reports whether the visitor is still watching. If they have closed the tab when an agent replies, we can email the reply to them - provided we have their address.</div>'
            .'<label style="display:flex;align-items:center;gap:9px;margin-top:14px">'
            .'<span class="switch"><input type="checkbox" name="visitor_followup" value="1"'.($this->settings->get('visitor_followup', '1') === '1' ? ' checked' : '').'><span class="sl"></span></span>'
            .'<span>Email the visitor when they have left the chat</span></label>'
            .'<label>Consider them gone after</label><div class="row">'
            .'<input type="number" name="visitor_followup_after" min="30" step="30" value="'.$g('visitor_followup_after', '120').'" style="max-width:140px">'
            .'<span class="muted">seconds without a heartbeat</span></div>'
            .'<div class="hint">One email per absence - they will not be mailed again until they come back.</div>'
            .'<div style="margin-top:18px"><button type="submit">Save settings</button></div>'
            .'</form></div></div>'

            .'<div><div class="bm-card"><h2>Outgoing email (SMTP)</h2>'
            .'<div class="muted">Banimark sends with its own settings, so it never depends on the host app.</div>'
            .'<label style="display:flex;align-items:center;gap:9px;margin-top:10px">'
            .'<span class="switch"><input type="checkbox" name="smtp_enabled" form="notify-form" value="1"'.($this->settings->get('smtp_enabled', '') === '1' ? ' checked' : '').'><span class="sl"></span></span>'
            .'<span>Use SMTP <span class="muted">(otherwise PHP mail(), which most cloud hosts drop)</span></span></label>'
            .'<div class="grid2">'
            .'<div><label>Host</label><input type="text" name="smtp_host" form="notify-form" value="'.$g('smtp_host').'" placeholder="smtp.mailgun.org"></div>'
            .'<div><label>Port</label><input type="number" name="smtp_port" form="notify-form" value="'.$g('smtp_port', '587').'"></div>'
            .'<div><label>Username</label><input type="text" name="smtp_user" form="notify-form" value="'.$g('smtp_user').'" autocomplete="off"></div>'
            .'<div><label>Password</label><input type="password" name="smtp_pass" form="notify-form" autocomplete="new-password" placeholder="'.($hasPass ? '&bull;&bull;&bull;&bull;&bull;&bull; (unchanged)' : '').'"></div>'
            .'<div><label>Encryption</label><select name="smtp_encryption" form="notify-form">'
            .'<option value="tls"'.$sel('tls').'>STARTTLS (587)</option>'
            .'<option value="ssl"'.$sel('ssl').'>SSL/TLS (465)</option>'
            .'<option value="none"'.$sel('none').'>None (25)</option></select></div>'
            .'<div><label>From name</label><input type="text" name="smtp_from_name" form="notify-form" value="'.$g('smtp_from_name', 'Support').'"></div></div>'
            .'<label>From address</label><input type="text" name="smtp_from_email" form="notify-form" value="'.$g('smtp_from_email').'" placeholder="support@yourco.com">'
            .'<div class="hint">Leave the password blank to keep the stored one.</div></div>'

            .'<div class="bm-card"><h2>Send a test</h2>'
            .'<div class="muted">Confirm the details work before an escalation depends on them.</div>'
            .'<form method="post" action="'.Html::e($this->url('/escalation/test')).'">'.$this->csrfField()
            .'<div class="row" style="margin-top:12px"><input type="text" name="test_email" placeholder="you@yourco.com" style="flex:1">'
            .'<button type="submit" class="btn2">Send test</button></div></form>'
            .'<div class="hint">Save your settings first - the test uses what is stored.</div></div></div></div>';

        return Html::page('Notifications', $body, $this->nav('/escalation'), 'Escalation alerts, outgoing email, and visitor follow-ups');
    }

    private function licensePage(string $flash): string
    {
        $status = (string) $this->settings->get('license_status', '');
        $last = (int) $this->settings->get('license_last_ping', '0');
        $pill = ['active' => 'ai', 'expired' => 'agent'][$status] ?? 'closed';
        $lock = $this->auth->lockReason();

        // version + changelog from HQ. Never licence-gated, always fail-open:
        // a lapsed customer must still see what is new and how to get it.
        $cache = json_decode((string) $this->settings->get('updates_cache', ''), true);
        $cache = is_array($cache) ? $cache : null;
        if (\Banimark\Update\UpdateCheck::due($this->settings->get('updates_checked_at', '0'))) {
            try {
                $fresh = (new \Banimark\Update\UpdateCheck(\Banimark\Update\UpdateCheck::endpointFrom(
                    (string) ($this->settings->get('hq_url', '') ?: Master::DEFAULT_ENDPOINT)
                )))->fetch();
                $this->settings->set('updates_checked_at', (string) time());
                if ($fresh['ok']) {
                    $this->settings->set('updates_cache', (string) json_encode($fresh));
                    $cache = $fresh;
                }
            } catch (\Throwable $e) {
                // a version check must never break the panel
            }
        }
        $cache = $cache ?: ['ok' => false, 'latest' => null, 'releases' => [], 'update_command' => 'composer update banimark/banimark'];
        $outdated = \Banimark\Update\UpdateCheck::isNewer($cache['latest'] ?? null);

        $versionPill = $outdated
            ? '<span class="pill expired">UPDATE AVAILABLE - '.Html::e((string) $cache['latest']).'</span>'
            : ($cache['ok'] ? '<span class="pill good">UP TO DATE</span>' : '<span class="pill unknown">COULD NOT CHECK</span>');
        $notes = '';
        foreach ((array) $cache['releases'] as $r) {
            $notes .= '<div style="border-top:1px solid var(--border);padding:12px 0 2px">'
                .'<div class="row" style="gap:8px"><b>'.Html::e((string) $r['version']).'</b>'
                .((string) $r['version'] === Master::PACKAGE_VERSION ? '<span class="pill active">INSTALLED</span>' : '')
                .'<span class="muted">'.Html::e((string) $r['released_at']).'</span></div>'
                .'<div class="muted" style="white-space:pre-wrap;margin-top:4px">'.Html::e((string) $r['notes']).'</div></div>';
        }
        $versionCard = '<div class="bm-card"><div class="bm-sec-h"><div><h2>Version</h2>'
            .'<div class="muted">You are running <b>'.Html::e(Master::PACKAGE_VERSION).'</b></div></div>'
            .'<div class="spacer"></div>'.$versionPill.'</div>'
            .($outdated ? '<div class="muted">To update, run this in your project:</div>'
                .'<textarea readonly rows="1" onclick="this.select()">'.Html::e((string) $cache['update_command']).'</textarea>' : '')
            .($notes ?: '<div class="muted" style="margin-top:8px">No release notes available right now.</div>')
            .'</div>';
        $banner = $lock !== null ? '<div class="flash-err"><b>Admin locked.</b> '.Html::e($lock['message']).'</div>' : '';
        return Html::page('License', $flash.$banner.$versionCard.'<div class="bm-card"><h2>License</h2>'
            .'<div class="muted">Your Banimark license key from the purchase email. Checked at most once a day, from this panel only - the check sends the key, this site\'s URL and version numbers, nothing else. An expired or unreachable license never affects the widget or this panel; it only pauses updates.</div>'
            .($status !== '' ? '<p>Status: <span class="pill '.$pill.'">'.strtoupper(Html::e($status)).'</span>'
                .($last > 0 ? ' <span class="muted">checked '.date('d M H:i', $last).'</span>' : '').'</p>' : '')
            .'<form method="post" action="'.Html::e($this->url('/license')).'">'.$this->csrfField()
            .'<label>License key</label><input type="text" name="license_key" value="'.Html::e($this->settings->get('license_key', '')).'" placeholder="BM-XXXX-XXXX-XXXX-XXXX">'
            .'<div style="margin-top:12px;"><button type="submit">Save &amp; check now</button></div></form></div>', $this->nav('/license'));
    }

    /* ---------------- plumbing ---------------- */

    private function query(string $sql, array $args): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function exec(string $sql, array $args): void
    {
        $this->pdo->prepare($sql)->execute($args);
    }
}
