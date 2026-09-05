<?php

namespace Banimark\Standalone;

use Banimark\Auth\AgentAuth;
use Banimark\Auth\Agents;
use Banimark\Auth\Totp;
use Banimark\Desk\QuickReplies;
use Banimark\Storage\TranscriptView;
use Banimark\Licensing\Master;
use Banimark\Notify\FollowUp;
use Banimark\Notify\MailerFactory;
use Banimark\Storage\Analytics;
use Banimark\Ui\Chart;
use Banimark\Ui\Icons;
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
        // panel CSS/JS as same-origin FILES, before any auth: the login page needs
        // them, they carry no secrets, and a customer's Content-Security-Policy
        // ('self') allows them where it blocks inline blocks and onclick= attributes
        Layout::configure(['assets' => $this->url('/assets')]);
        if (str_starts_with($route, '/assets/')) {
            $name = substr($route, 8);
            if (!preg_match('#^[a-z]+\.(?:css|js)$#', $name) || !\Banimark\Ui\Assets::exists($name)) {
                http_response_code(404); // anything else under /assets/ is nothing, not the login page
                return;
            }
            $m = [null, $name];
            foreach (\Banimark\Ui\Assets::headers($m[1]) as $h => $v) {
                header($h.': '.$v);
            }
            echo \Banimark\Ui\Assets::content($m[1]);
            return;
        }

        // login / logout
        if ($route === '/login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $result = $this->auth->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            if ($result === '2fa') {
                header('Location: '.$this->url('/login/2fa'));
                return;
            }
            if ($result) {
                header('Location: '.$this->url());
                return;
            }
            echo $this->login('Wrong email or password.');
            return;
        }
        if ($route === '/login/2fa') {
            if (!$this->auth->pendingTotp()) {
                header('Location: '.$this->url());
                return;
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                if ($this->auth->verifyTotp((string) ($_POST['code'] ?? ''))) {
                    header('Location: '.$this->url());
                    return;
                }
                echo Html::totp($this->url('/login/2fa'), $this->url('/logout'), 'That code did not match. Codes change every 30 seconds - try the current one.');
                return;
            }
            echo Html::totp($this->url('/login/2fa'), $this->url('/logout'));
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

        // an existing standalone install never re-runs the installer, so this is
        // where a package update reaches the database: once per version, on a
        // staff visit. The widget/chat path never pays for it.
        \Banimark\Storage\Schema::ensureCurrent($this->pdo, Master::PACKAGE_VERSION);

        // CSRF on every mutation
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$this->auth->csrfOk($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            echo Html::page('Expired', '<div class="bm-card"><h2>Session expired</h2><p><a href="'.Html::e($this->url()).'">Back</a></p></div>');
            return;
        }

        // (the daily HQ re-check runs in App::maybePhoneHome(), before we get here)
        // license lock: no valid license = no admin, pages AND actions. The
        // verdict comes from AgentAuth->lockReason() (encoded Master), not a
        // local call, so it cannot be stripped here. Widget/chat is never gated.
        if (!in_array($route, ['/license', '/changelog', '/logout'], true) && $this->auth->lockReason() !== null) {
            header('Location: '.$this->url('/license'));
            return;
        }
        // owner policy "everyone uses 2FA": an un-enrolled account can only reach the page where it enrols
        if (!in_array($route, ['/security', '/security/begin', '/security/confirm', '/license', '/changelog', '/logout'], true)
            && $this->settings->get('require_2fa', '0') === '1' && !$this->agents->totpEnabled((int) $this->auth->id())) {
            header('Location: '.$this->url('/security'));
            return;
        }
        Layout::configure(['events' => $this->url('/events'), 'conversation' => $this->url('/conversation/__SID__')]);

        $flash = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $flash = $this->handlePost($route);
            if ($flash === null) {
                return; // redirected
            }
        }
        $flash = \Banimark\Licensing\HqNotice::html($this->settings->all(), (string) ($_SERVER['HTTP_HOST'] ?? '')).$flash;

        if ($route === '/events') {
            header('Content-Type: application/json');
            echo json_encode($this->store->staffEvents((int) ($_GET['since'] ?? 0)));
            return;
        }
        if (preg_match('#^/conversation/([a-f0-9]{32})/messages$#', $route, $m)) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'mode' => $this->store->mode($m[1]),
                'messages' => TranscriptView::rows($this->store->messagesSince($m[1], (int) ($_GET['after'] ?? 0))),
                'presence' => $this->store->presence($m[1]),
            ]);
            return;
        }
        if ($route === '/tools/schema') {
            header('Content-Type: application/json');
            try {
                $tables = (new \Banimark\Tools\SchemaInspector($this->pdo))->all();
            } catch (\Throwable $e) {
                $tables = [];
            }
            echo json_encode(['tables' => $tables]);
            return;
        }

        echo match (true) {
            $route === '/' || $route === '' => $this->dashboard($flash),
            $route === '/inbox' => $this->inbox($flash),
            str_starts_with($route, '/conversation/') => $this->conversation(substr($route, 14), $flash),
            $route === '/tools' => $this->tools($flash),
            // POST handlers that answer with a flash (a validation problem) re-render their page
            str_starts_with($route, '/rules') => $this->rules($flash),
            str_starts_with($route, '/security') => $this->securityPage($flash),
            $route === '/quick-replies' => $this->escalationPage($flash),
            $route === '/providers' => $this->providers($flash),
            $route === '/agents' => $this->agentsPage($flash),
            $route === '/escalation', $route === '/escalation/test' => $this->escalationPage($flash),
            $route === '/widget' => $this->widget($flash),
            $route === '/license' => $this->licensePage($flash),
            $route === '/changelog' => $this->changelogPage($flash),
            default => Html::page('Not found', '<div class="bm-card"><h2>Not found</h2></div>', $this->nav()),
        };
    }

    /** @return string|null flash html, or null when a redirect was sent */
    private function handlePost(string $route): ?string
    {
        $p = $_POST;
        if (preg_match('#^/conversation/([a-f0-9]{32})/reply$#', $route, $m)) {
            $text = trim((string) ($p['message'] ?? ''));
            $emailed = false;
            $row = null;
            if ($text !== '') {
                $this->store->appendAgentMessage($m[1], $text);
                $all = $this->store->transcript($m[1]);
                $row = $all === [] ? null : TranscriptView::row(end($all));
                // the visitor may have closed the tab - post the reply on to them
                try {
                    $emailed = (new FollowUp($this->store, MailerFactory::make($this->settings->all()), $this->settings->all()))
                        ->afterAgentReply($m[1], $text);
                } catch (\Throwable $e) { /* a mail problem must never lose the reply */ }
            }
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => $text !== '', 'message' => $row, 'emailed' => $emailed, 'mode' => $this->store->mode($m[1])]);
                return null;
            }
            header('Location: '.$this->url('/conversation/'.$m[1]));
            return null;
        }
        if ($route === '/quick-replies') {
            $this->settings->set('quick_replies', trim((string) ($p['quick_replies'] ?? '')));
            return '<div class="flash-ok">Quick replies saved.</div>';
        }
        if (str_starts_with($route, '/security/')) {
            return $this->handleSecurityPost($route, $p);
        }
        if ($route === '/agents/2fa-reset') {
            if ($this->auth->isOwner()) {
                $this->agents->resetTotp((int) ($p['id'] ?? 0));
            }
            header('Location: '.$this->url('/agents'));
            return null;
        }
        if ($route === '/agents/2fa-require') {
            if ($this->auth->isOwner()) {
                $this->settings->set('require_2fa', !empty($p['require_2fa']) ? '1' : '0');
            }
            header('Location: '.$this->url('/agents'));
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
        if (str_starts_with($route, '/rules')) {
            $flash = $this->handleRulesPost($route, $p);
            if ($flash !== '') {
                return $flash;
            }
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
            if (!$this->auth->isOwner()) {
                return '<div class="flash-err">Only an owner can manage the licence.</div>';
            }
            $key = trim((string) ($p['license_key'] ?? ''));
            // an ACTIVE key is read-only: swapping it is how a licence walks to another install
            if ($this->settings->get('license_status') === 'active' && $this->auth->lockReason() === null
                && $key !== '' && $key !== (string) $this->settings->get('license_key', '')) {
                return '<div class="flash-err">Your licence is active. To move to a different key, contact support.</div>';
            }
            $this->settings->set('license_key', $key);
            // hq_url is not a panel field - support overrides it directly if ever needed
            if ($key === '') {
                $this->settings->set('license_token', '');
                return '<div class="flash-ok">License settings saved.</div>';
            }
            // immediate check - through the same fail-open path as the daily one, so
            // pressing the button during an HQ outage can never lock an active licence
            $result = \Banimark\Licensing\PhoneHome::run(
                $this->settings->all(),
                Master::siteUrlFromServer($_SERVER),
                fn (string $k, string $v) => $this->settings->set($k, $v),
                fn (string $k) => $this->settings->set($k, ''),
                force: true,
            );
            if ($result === null || empty($result['ok'])) {
                return '<div class="flash-err">'.Html::e(\Banimark\Licensing\PhoneHome::unreachableMessage($this->settings->all())).'</div>';
            }
            if ($result['license'] === 'active') {
                header('Location: '.$this->url()); // activated: straight into the module dashboard
                return null;
            }
            return '<div class="flash-err">License checked - status: <b>'.Html::e($result['license']).'</b>'
                .($result['message'] !== '' ? ' · '.Html::e($result['message']) : '').'</div>';
        }
        return '';
    }

    private function saveTool(array $p): string
    {
        // rows arrive as positional arrays; a checkbox only posts when ticked,
        // so param_required[] carries the INDEXES of the required rows
        $names = array_values((array) ($p['param_name'] ?? []));
        $types = array_values((array) ($p['param_type'] ?? []));
        $descs = array_values((array) ($p['param_desc'] ?? []));
        $required = array_map('intval', (array) ($p['param_required'] ?? []));
        $params = [];
        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $params[$name] = [
                'type' => in_array($types[$i] ?? '', ['string', 'integer', 'number', 'boolean'], true) ? $types[$i] : 'string',
                'description' => trim((string) ($descs[$i] ?? '')),
                'required' => in_array($i, $required, true),
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
        $items[] = ['/security', 'shield', 'Security'];
        $items[] = ['/license', 'license', 'License'];
        if ($this->auth->isOwner()) {
            $items[] = ['/changelog', 'bolt', 'Changelog'];
        }

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
        $e = fn ($v) => Html::e((string) $v);
        $mode = $this->store->mode($sessionId);
        $rows = TranscriptView::rows($this->store->transcript($sessionId));
        $lastId = $rows === [] ? 0 : end($rows)['id'];
        $presence = $this->store->presence($sessionId) ?? [];
        $label = (string) ($presence['visitor_label'] ?? '') ?: 'Visitor';
        $online = (int) ($presence['last_seen_at'] ?? 0) > time() - 45;
        $msgs = '';
        foreach ($rows as $m) {
            if ($m['role'] === 'tool') {
                $msgs .= '<div class="msg tool" data-id="'.$m['id'].'">'.Icons::get('bolt', 12).' '.$e($m['text']).'</div>';
            } else {
                $msgs .= '<div class="msg '.$e($m['role']).'" data-id="'.$m['id'].'">'.$e($m['text'])
                    .'<div class="msg-meta">'.($m['role'] === 'agent' ? 'human agent · ' : ($m['role'] === 'assistant' ? 'AI · ' : '')).($m['at'] ? date('H:i', $m['at']) : '').'</div></div>';
            }
        }
        $quick = '';
        foreach (QuickReplies::fromSettings($this->settings->all()) as $q) {
            $quick .= '<button type="button" data-quick="'.$e($q).'">'.$e(mb_strimwidth($q, 0, 42, '…')).'</button>';
        }
        $modeForm = fn (string $to, string $label, string $cls, string $confirm = '') => '<form method="post" action="'.$e($this->url('/conversation/'.$sessionId.'/mode')).'" style="display:inline">'.$this->csrfField()
            .'<input type="hidden" name="mode" value="'.$to.'"><button class="'.$cls.' btn-sm"'.($confirm !== '' ? ' data-confirm="'.$e($confirm).'"' : '').'>'.$label.'</button></form>';
        $actions = '<a class="btn2 btn-sm" href="'.$e($this->url('/inbox')).'">'.Icons::get('back', 15).' Inbox</a>'
            .($mode === 'agent' ? $modeForm('ai', 'Hand back to AI', 'btn2') : $modeForm('agent', 'Take over', 'btn2'))
            .$modeForm('closed', 'Close', 'btn-danger', 'Close this conversation?');

        $body = $flash.'<div class="bm-card" data-live-chat data-session="'.$e($sessionId).'" data-mode="'.$e($mode).'" data-after="'.$lastId.'"'
            .' data-messages-url="'.$e($this->url('/conversation/'.$sessionId.'/messages')).'" data-reply-url="'.$e($this->url('/conversation/'.$sessionId.'/reply')).'"'
            .' data-csrf-name="_csrf" data-csrf="'.$e($this->auth->csrf()).'">'
            .'<div class="bm-sec-h"><div class="row"><span class="avatar">'.$e(strtoupper(mb_substr($label, 0, 1))).'</span><div>'
            .'<h2 style="margin:0">'.$e($label).' <span class="pill '.$e($mode).'" data-mode-pill>'.strtoupper($e($mode)).'</span></h2>'
            .'<span class="bm-presence '.($online ? 'on' : 'off').'" data-presence>'.$e($label).($online ? ' · online now' : (!empty($presence['last_seen_at']) ? ' · left the chat' : '')).'</span>'
            .(!empty($presence['visitor_email']) ? '<span class="muted" style="margin-left:8px">'.$e($presence['visitor_email']).'</span>' : '')
            .'</div></div></div>'
            .'<div class="msgs" data-thread data-autoscroll style="max-height:56vh;overflow-y:auto;padding-right:4px">'.$msgs.'</div>'
            .'<div class="bm-typing" data-typing hidden><i></i><i></i><i></i></div><div class="flash-ok" data-flash hidden></div>'
            .'<div class="bm-quick">'.$quick.'</div>'
            .'<form method="post" action="'.$e($this->url('/conversation/'.$sessionId.'/reply')).'" class="bm-compose" data-reply>'.$this->csrfField()
            .'<textarea name="message" rows="1" placeholder="Reply as a human agent… (Enter to send, Shift+Enter for a new line)" autofocus autocomplete="off"></textarea>'
            .'<button type="submit">'.Icons::get('send', 15).' Send</button></form></div>'
            .Layout::chatScript();
        return Html::page('Conversation', $body, $this->nav('/inbox'), 'Replying takes over - the AI stays silent until you hand it back', $actions);
    }

    private function quickRepliesCard(): string
    {
        return '<div class="bm-card"><h2>Quick replies</h2>'
            .'<div class="muted">One per line. Staff tap these in a live conversation to answer in one click.</div>'
            .'<form method="post" action="'.Html::e($this->url('/quick-replies')).'">'.$this->csrfField()
            .'<textarea name="quick_replies" rows="5" style="margin-top:10px">'.Html::e(implode("\n", QuickReplies::fromSettings($this->settings->all()))).'</textarea>'
            .'<div style="margin-top:10px"><button type="submit" class="btn2 btn-sm">Save quick replies</button></div></form></div>';
    }

    /* ---------------- security: my 2FA ---------------- */

    private function handleSecurityPost(string $route, array $p): ?string
    {
        $id = (int) $this->auth->id();
        switch ($route) {
            case '/security/begin':
                $this->agents->beginTotp($id);
                header('Location: '.$this->url('/security'));
                return null;
            case '/security/confirm':
                if ($this->agents->confirmTotp($id, (string) ($p['code'] ?? ''))) {
                    return '<div class="flash-ok">Two-factor authentication is on. You will be asked for a code at every sign-in.</div>';
                }
                return '<div class="flash-err">That code did not match - check the time on your phone and try the current code.</div>';
            case '/security/disable':
                $me = $this->agents->find($id) ?? [];
                if (!Totp::verify((string) ($me['totp_secret'] ?? ''), (string) ($p['code'] ?? ''))) {
                    return '<div class="flash-err">Enter a current code from your app to switch 2FA off.</div>';
                }
                $this->agents->resetTotp($id);
                return '<div class="flash-ok">Two-factor authentication is off for your account.</div>';
        }
        return '<div class="flash-err">Unknown action.</div>';
    }

    private function securityPage(string $flash): string
    {
        $e = fn ($v) => Html::e((string) $v);
        $me = $this->agents->find((int) $this->auth->id()) ?? [];
        $enabled = (int) ($me['totp_enabled'] ?? 0) === 1;
        $pending = !$enabled ? (string) ($me['totp_secret'] ?? '') : '';
        $required = $this->settings->get('require_2fa', '0') === '1';
        $csrf = $this->csrfField();

        $left = '<div class="bm-card"><div class="row" style="gap:10px"><span class="avatar">'.Icons::get('shield', 16).'</span><div>'
            .'<h2 style="margin:0">Two-factor authentication</h2><div class="muted">A code from your phone is needed alongside your password.</div></div>'
            .'<div class="spacer"></div><span class="pill '.($enabled ? 'good' : 'closed').'">'.($enabled ? 'ON' : 'OFF').'</span></div>'
            .($required && !$enabled ? '<div class="flash-err" style="margin-top:14px">'.Icons::get('escalation', 16).'<span>Your owner requires 2FA for everyone. Finish the setup on the right to keep using the panel.</span></div>' : '');
        if ($enabled) {
            $left .= '<p style="margin-top:14px">2FA is protecting this account. To switch it off, confirm with a current code.</p>'
                .'<form method="post" action="'.$e($this->url('/security/disable')).'" class="row" style="gap:8px">'.$csrf
                .'<input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="123 456" autocomplete="one-time-code" style="width:140px;text-align:center;letter-spacing:.2em">'
                .'<button type="submit" class="btn-danger btn-sm" data-confirm="Turn off two-factor authentication for your account?">Turn off 2FA</button></form>';
        } elseif ($pending === '') {
            $left .= '<p style="margin-top:14px">You will need an authenticator app: Google Authenticator, Authy, 1Password, Microsoft Authenticator - any of them works.</p>'
                .'<form method="post" action="'.$e($this->url('/security/begin')).'">'.$csrf.'<button type="submit">'.Icons::get('shield', 15).' Set up 2FA</button></form>';
        } else {
            $left .= '<p style="margin-top:14px">Setup started - finish it on the right. Nothing changes until you confirm a code.</p>';
        }
        $left .= '</div>';

        if (!$enabled && $pending !== '') {
            $right = '<div class="bm-card"><h2>Finish setup</h2><ol style="padding-left:18px;line-height:1.7">'
                .'<li>Open your authenticator app and choose <b>Add account</b> &rarr; <b>Enter a setup key</b>.</li>'
                .'<li>Type this key (account name: your email, type: time-based):</li></ol>'
                .'<div class="bm-secret">'.$e(trim(chunk_split($pending, 4, ' '))).'</div>'
                .'<div class="muted" style="margin:8px 0 14px">On this device? <a href="'.$e(Totp::uri($pending, (string) ($me['email'] ?? ''), 'Banimark')).'">Open in your authenticator app</a>.</div>'
                .'<form method="post" action="'.$e($this->url('/security/confirm')).'">'.$csrf
                .'<label>3. Enter the 6-digit code the app shows now</label><div class="row" style="gap:8px">'
                .'<input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="123 456" autocomplete="one-time-code" autofocus style="width:160px;text-align:center;font-size:20px;letter-spacing:.25em">'
                .'<button type="submit">'.Icons::get('check', 15).' Confirm &amp; turn on</button></div></form></div>';
        } else {
            $right = '<div class="bm-card"><h2>How it works</h2><ul style="padding-left:18px;line-height:1.7" class="muted">'
                .'<li>Codes are generated on your phone and change every 30 seconds - nothing is sent by SMS or email.</li>'
                .'<li>Owners can require 2FA for every staff member from <a href="'.$e($this->url('/agents')).'">Staff</a>.</li>'
                .'<li>Lost your phone? An owner can reset your 2FA; you then enrol again with a new key.</li></ul></div>';
        }
        return Html::page('Security', $flash.'<div class="bm-grid c2">'.$left.$right.'</div>', $this->nav('/security'), 'Two-factor authentication for your own account');
    }

    private function tools(string $flash): string
    {
        $e = fn ($v) => Html::e((string) $v);
        $rows = '';
        foreach ($this->query('SELECT * FROM banimark_tools ORDER BY id', []) as $r) {
            $rows .= '<tr><td><div class="row">'.Icons::get('tools', 15).'<b class="mono" style="background:none;padding:0">'.$e($r['name']).'</b></div></td>'
                .'<td class="muted">'.$e(mb_substr($r['description'], 0, 110)).'</td>'
                .'<td class="muted">'.($e(implode(', ', array_keys(json_decode($r['parameters'], true) ?: []))) ?: '&mdash;').'</td>'
                .'<td style="font-variant-numeric:tabular-nums">'.(int) $r['max_rows'].'</td>'
                .'<td><form method="post" action="'.$e($this->url('/tools/delete')).'">'.$this->csrfField().'<input type="hidden" name="name" value="'.$e($r['name']).'">'
                .'<button class="btn-ghost btn-icon" data-confirm="Delete this tool?" title="Delete">'.Icons::get('trash', 15).'</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">'.Chart::empty('No tools yet', 'The AI can chat, but it cannot look anything up until you build one.').'</td></tr>';
        }
        $html = $flash
            .'<div class="bm-card pad0"><div class="bm-sec-h" style="padding:18px 20px 0"><div><h2>Your tools</h2>'
            .'<div class="muted">Each tool is one question the AI can answer from your data, e.g. "find this customer\'s orders".</div></div></div>'
            .'<div class="t-wrap"><table><tr><th>Name</th><th>What it does</th><th>Asks the customer for</th><th>Rows</th><th></th></tr>'.$rows.'</table></div></div>'
            .'<div class="bm-card"><h2>Build a tool</h2>'
            .'<div class="muted">Three steps: name it, say what the AI needs to ask the customer, then point at your data. No SQL knowledge needed - the builder writes it for you.</div>'
            .'<form method="post" action="'.$e($this->url('/tools')).'">'.$this->csrfField()
            .'<h3 class="bm-step">1. What is this tool?</h3>'
            .'<div class="grid2"><div><label>Name <span class="muted">(letters, numbers, underscores)</span></label><input type="text" name="name" required placeholder="find_orders"></div>'
            .'<div><label>Most rows to return</label><input type="number" name="max_rows" value="10" min="1" max="50"></div></div>'
            .'<label>Describe it in plain words - the AI reads this to know when to use it</label>'
            .'<textarea name="description" required placeholder="Look up a customer\'s orders by order number or by what they bought."></textarea>'
            .'<h3 class="bm-step">2. What should the AI ask the customer for?</h3>'
            .'<div class="muted" style="margin-bottom:8px">Each item becomes a question the AI can ask (an order number, a date, a product name). Add as many as you need.</div>'
            .'<div data-params></div><button type="button" class="btn-ghost btn-sm" data-add-param>'.Icons::get('plus', 14).' Add another</button>'
            .'<h3 class="bm-step">3. Where is the data?</h3>'
            .'<div data-toolbuilder data-schema-url="'.$e($this->url('/tools/schema')).'" style="background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:14px 16px">'
            .'<div class="row" style="justify-content:space-between"><b>Visual builder</b><span class="muted" data-status>…</span></div>'
            .'<div class="grid2" style="margin-top:8px"><div><label>Table</label><select data-table><option value="">Loading…</option></select></div>'
            .'<div><label>Who is chatting is identified by <span class="muted">(identity keys, comma-separated)</span></label><input type="text" name="context" value="user_id" placeholder="user_id"></div></div>'
            .'<label>Columns the AI may show the customer</label><div data-columns><span class="muted">Pick a table first.</span></div>'
            .'<label style="margin-top:12px">Only show rows where…</label><div data-conditions></div>'
            .'<button type="button" class="btn-ghost btn-sm" data-add-condition>'.Icons::get('plus', 14).' Add a condition</button>'
            .'<div class="muted" style="margin:10px 0 4px">Tip: add a condition on the customer\'s own id using the <i>identity</i> option so every customer only ever sees their own rows.</div>'
            .'<pre class="mono" data-preview style="white-space:pre-wrap;padding:10px 12px;border-radius:8px;margin:8px 0">-- pick a table and at least one column</pre>'
            .'<button type="button" class="btn2 btn-sm" data-apply disabled>'.Icons::get('check', 14).' Use this query</button></div>'
            .'<details style="margin-top:14px"><summary class="muted" style="cursor:pointer">Advanced: the query the AI will run (editable)</summary>'
            .'<label>SQL - SELECT only. <code>:param</code> for values the AI asks for, <code>:_key</code> for identity values</label>'
            .'<textarea name="sql" required placeholder="SELECT reference, status, total FROM orders WHERE reference = :reference AND user_id = :_user_id"></textarea>'
            .'<label>Columns the AI may see</label><input type="text" name="columns" required placeholder="reference, status, total"></details>'
            .'<div style="margin-top:16px"><button type="submit">'.Icons::get('check', 15).' Validate &amp; save tool</button></div></form></div>'
            .Layout::toolBuilderScript();
        return Html::page('Tools', $html, $this->nav('/tools'));
    }

    private function rulesRepo(): \Banimark\Storage\Rules
    {
        return new \Banimark\Storage\Rules($this->pdo);
    }

    /** @return string flash html on a validation problem, '' when done (caller redirects) */
    private function handleRulesPost(string $route, array $p): string
    {
        $rules = $this->rulesRepo();
        $id = (int) ($p['id'] ?? 0);
        switch ($route) {
            case '/rules/folder':
                $title = trim((string) ($p['title'] ?? ''));
                if ($title === '') {
                    return '<div class="flash-err">A folder needs a name.</div>';
                }
                $id > 0
                    ? $rules->updateFolder($id, $title, (string) ($p['description'] ?? ''), !empty($p['enabled']))
                    : $rules->createFolder($title, (string) ($p['description'] ?? ''));
                return '';
            case '/rules/folder/delete': $rules->deleteFolder($id); return '';
            case '/rules/folder/move': $rules->moveFolder($id, (int) ($p['direction'] ?? 1)); return '';
            case '/rules/move': $rules->moveRule($id, (int) ($p['direction'] ?? 1)); return '';
            case '/rules/delete': $rules->deleteRule($id); return '';
            case '/rules':
                $content = trim((string) ($p['content'] ?? ''));
                if ($content === '') {
                    return '<div class="flash-err">A rule needs some content.</div>';
                }
                if ($id > 0) {
                    $rules->updateRule($id, (string) ($p['title'] ?? ''), $content, !empty($p['enabled']));
                    return '';
                }
                $folder = (int) ($p['folder_id'] ?? 0);
                if ($folder <= 0) {
                    return '<div class="flash-err">Pick a folder for the rule.</div>';
                }
                $rules->addRule($folder, (string) ($p['title'] ?? ''), $content);
                return '';
        }
        return '<div class="flash-err">Unknown action.</div>';
    }

    private function rules(string $flash): string
    {
        $repo = $this->rulesRepo();
        $repo->seedDefaults(); // desks installed before folders existed
        $folders = $repo->tree();
        $csrf = $this->csrfField();
        $e = fn ($v) => Html::e((string) $v);
        $post = fn (string $path, array $hidden, string $button) => '<form method="post" action="'.$e($this->url($path)).'">'.$csrf
            .implode('', array_map(fn ($k, $v) => '<input type="hidden" name="'.$k.'" value="'.$e($v).'">', array_keys($hidden), $hidden)).$button.'</form>';

        $html = $flash
            .'<div class="bm-card" id="new-folder" hidden><h2>New folder</h2>'
            .'<div class="muted">A folder groups related rules - Personality, Refund policy, Opening hours. Folders are applied top to bottom.</div>'
            .'<form method="post" action="'.$e($this->url('/rules/folder')).'">'.$csrf
            .'<div class="grid2"><div><label>Folder name</label><input type="text" name="title" required placeholder="Refund policy"></div>'
            .'<div><label>What goes in here <span class="muted">(optional)</span></label><input type="text" name="description" placeholder="What the assistant may and may not promise about refunds"></div></div>'
            .'<div class="row" style="margin-top:12px"><button type="submit">Create folder</button>'
            .'<button type="button" class="btn-ghost" data-dismiss=".bm-card">Cancel</button></div></form></div>'
            .'<div class="row" style="margin:0 0 14px"><div class="spacer"></div>'
            .'<button type="button" class="btn-ghost btn-sm" data-collapse-all="open" title="Expand every folder">Expand all</button>'
            .'<button type="button" class="btn-ghost btn-sm" data-collapse-all="close" title="Collapse every folder">Collapse all</button>'
            .'<button type="button" class="btn-sm" data-reveal="#new-folder">'.Icons::get('plus', 15).' New folder</button></div>';

        if ($folders === []) {
            $html .= '<div class="bm-card">'.Chart::empty('No folders yet', 'Create a folder, then add rules to it.').'</div>';
        }
        $nF = count($folders);
        foreach ($folders as $fi => $f) {
            $fid = (int) $f['id'];
            $nR = count($f['rules']);
            // the header is the toggle; its buttons still work without toggling
            $html .= '<div class="bm-card pad0" data-collapsible'.($f['enabled'] ? '' : ' style="opacity:.6"').'>'
                .'<div class="bm-sec-h bm-fold" data-collapse="folder-'.$fid.'" style="padding:16px 20px 12px;align-items:center;border-bottom:1px solid var(--border)" title="Click to open or close this folder">'
                .'<div class="row" style="gap:10px"><span class="avatar">'.($fi + 1).'</span><div><h2 style="margin:0">'.$e($f['title'])
                .' <span class="muted" style="font-weight:500;font-size:13px">· '.$nR.($nR === 1 ? ' rule' : ' rules').'</span>'.($f['enabled'] ? '' : ' <span class="pill closed">OFF</span>').'</h2>'
                .($f['description'] !== '' ? '<div class="muted">'.$e($f['description']).'</div>' : '').'</div></div><div class="spacer"></div><span class="bm-chevron" aria-hidden="true"></span>'
                .'<div class="row" style="gap:4px">'
                .$post('/rules/folder/move', ['id' => $fid, 'direction' => -1], '<button class="btn-ghost btn-icon" title="Move up"'.($fi === 0 ? ' disabled' : '').'>&uarr;</button>')
                .$post('/rules/folder/move', ['id' => $fid, 'direction' => 1], '<button class="btn-ghost btn-icon" title="Move down"'.($fi === $nF - 1 ? ' disabled' : '').'>&darr;</button>')
                .'<button type="button" class="btn-ghost btn-sm" data-toggle="#edit-folder-'.$fid.'">Edit</button>'
                .$post('/rules/folder/delete', ['id' => $fid], '<button class="btn-ghost btn-icon" title="Delete folder and its rules" data-confirm="Delete this folder and every rule in it?">'.Icons::get('trash', 15).'</button>')
                .'</div></div>'
                .'<form method="post" action="'.$e($this->url('/rules/folder')).'" id="edit-folder-'.$fid.'" hidden style="padding:12px 20px;border-bottom:1px solid var(--border);background:var(--surface-2)">'.$csrf
                .'<input type="hidden" name="id" value="'.$fid.'"><div class="grid2"><div><label>Folder name</label><input type="text" name="title" value="'.$e($f['title']).'" required></div>'
                .'<div><label>Description</label><input type="text" name="description" value="'.$e($f['description']).'"></div></div>'
                .'<div class="row" style="margin-top:10px;gap:14px"><label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="enabled" value="1"'.($f['enabled'] ? ' checked' : '').'> Folder is active</label>'
                .'<button type="submit" class="btn-sm">Save folder</button></div></form>'
                .'<div data-collapse-body hidden style="padding:6px 20px 4px">';
            if ($nR === 0) {
                $html .= '<div class="muted" style="padding:10px 0">No rules in this folder yet.</div>';
            }
            foreach ($f['rules'] as $ri => $r) {
                $rid = (int) $r['id'];
                $html .= '<div style="display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--border);'.($r['enabled'] ? '' : 'opacity:.55').'">'
                    .'<div style="flex:1;min-width:0"><div class="row" style="gap:8px">'.($r['title'] !== '' ? '<b>'.$e($r['title']).'</b>' : '').($r['enabled'] ? '' : '<span class="pill closed">OFF</span>').'</div>'
                    .'<div class="muted" style="white-space:pre-wrap;margin-top:3px">'.$e($r['content']).'</div>'
                    .'<form method="post" action="'.$e($this->url('/rules')).'" id="edit-rule-'.$rid.'" hidden style="margin-top:8px">'.$csrf
                    .'<input type="hidden" name="id" value="'.$rid.'"><input type="text" name="title" value="'.$e($r['title']).'" placeholder="Short title (optional)">'
                    .'<textarea name="content" required style="margin-top:6px">'.$e($r['content']).'</textarea>'
                    .'<div class="row" style="margin-top:8px;gap:14px"><label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="enabled" value="1"'.($r['enabled'] ? ' checked' : '').'> Active</label>'
                    .'<button type="submit" class="btn-sm">Save rule</button></div></form></div>'
                    .'<div class="row" style="gap:2px;flex:none">'
                    .$post('/rules/move', ['id' => $rid, 'direction' => -1], '<button class="btn-ghost btn-icon" title="Up"'.($ri === 0 ? ' disabled' : '').'>&uarr;</button>')
                    .$post('/rules/move', ['id' => $rid, 'direction' => 1], '<button class="btn-ghost btn-icon" title="Down"'.($ri === $nR - 1 ? ' disabled' : '').'>&darr;</button>')
                    .'<button type="button" class="btn-ghost btn-sm" data-toggle="#edit-rule-'.$rid.'">Edit</button>'
                    .$post('/rules/delete', ['id' => $rid], '<button class="btn-ghost btn-icon" title="Delete" data-confirm="Delete this rule?">'.Icons::get('trash', 15).'</button>')
                    .'</div></div>';
            }
            $html .= '<form method="post" action="'.$e($this->url('/rules')).'" style="padding:12px 0 14px">'.$csrf
                .'<input type="hidden" name="folder_id" value="'.$fid.'"><div class="row" style="align-items:flex-start">'
                .'<input type="text" name="title" placeholder="Short title (optional)" style="flex:1">'
                .'<input type="text" name="content" required placeholder="Add a rule to '.$e($f['title']).'…" style="flex:3">'
                .'<button type="submit" class="btn2 btn-sm">'.Icons::get('plus', 14).' Add</button></div></form></div></div>';
        }
        return Html::page('Rules', $html, $this->nav('/rules'));
    }

    private function providers(string $flash): string
    {
        $rows = '';
        foreach ($this->query('SELECT * FROM banimark_providers ORDER BY id', []) as $r) {
            $rows .= '<tr><td><b>'.Html::e($r['slug']).'</b> '.($r['is_default'] ? '<span class="pill ai">DEFAULT</span>' : '').'</td>'
                .'<td>'.Html::e($r['driver']).'</td><td>'.Html::e($r['model']).'</td>'
                .'<td class="muted">'.Html::e($r['base_url'] ?: '-').'</td><td>'.Html::e($r['temperature']).'</td>'
                .'<td>'.($r['enabled'] ? 'enabled' : 'disabled').'</td>'
                .'<td><form method="post" action="'.Html::e($this->url('/providers/delete')).'">'.$this->csrfField().'<input type="hidden" name="slug" value="'.Html::e($r['slug']).'"><button class="btn-danger" data-confirm="Remove?">×</button></form></td></tr>';
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
            .'</div>'.$this->flutterCard($s->get('title', 'Support')), $this->nav('/widget'));
    }

    /** "Mobile apps": the Flutter SDK as advertised by HQ (version + link), with a ready snippet. */
    private function flutterCard(string $title): string
    {
        $e = fn ($v) => Html::e((string) $v);
        $sdk = $this->updates()['sdks']['flutter'] ?? null;
        $support = (string) $this->settings->get('support_email', '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $entry = $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'yourapp.com').$this->base;
        $html = '<div class="bm-card"><div class="bm-sec-h"><div><h2>Mobile apps (Flutter)</h2>'
            .'<div class="muted">The same chat, native in your iOS and Android app - themeable bubbles, human handover with live replies, resumes where the visitor left off, guest mode.</div></div>'
            .($sdk ? '<span class="pill active">banimark_flutter '.$e($sdk['version'] ?? '').'</span>' : '').'</div>';
        if ($sdk) {
            $html .= '<div class="row" style="gap:10px;margin:10px 0 4px">'
                .(!empty($sdk['url']) ? '<a class="btn2 btn-sm" href="'.$e($sdk['url']).'" target="_blank" rel="noopener">'.Icons::get('widget', 14).' Get the SDK</a>' : '')
                .(!empty($sdk['notes']) ? '<span class="muted">'.$e($sdk['notes']).'</span>' : '').'</div>';
        } else {
            $html .= '<div class="hint">Your vendor publishes the SDK\'s version and download link here.'.($support !== '' ? ' Ask '.$e($support).' for access.' : '').'</div>';
        }
        return $html.'<div class="divider"></div><div class="muted">Drop it in a route, a bottom sheet or a tab. Point it at this install:</div>'
            .'<textarea readonly rows="5" data-select-all>BanimarkChat(
  config: BanimarkConfig.standalone(\''.$e($entry).'\', token: userToken), // token: mint it server-side like the widget\'s data-token; null = guest
  theme: BanimarkTheme.fromScheme(Theme.of(context).colorScheme)
      .copyWith(title: \''.$e(str_replace("'", "\\'", $title)).'\'),
)</textarea>'
            .'<div class="hint">Everything is themeable - colours, radii, avatars, every string. The SDK\'s README covers it.</div></div>';
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
                .'<td>'.(!empty($a['totp_enabled'])
                    ? '<div class="row" style="gap:6px"><span class="pill good">ON</span><form method="post" action="'.Html::e($this->url('/agents/2fa-reset')).'">'.$this->csrfField().'<input type="hidden" name="id" value="'.(int) $a['id'].'"><button class="btn-ghost btn-sm" data-confirm="Reset 2FA for this account? They sign in with just their password until they enrol again.">Reset</button></form></div>'
                    : '<span class="pill closed">OFF</span>').'</td>'
                .'<td><form method="post" action="'.Html::e($this->url('/agents/delete')).'">'.$this->csrfField().'<input type="hidden" name="id" value="'.(int) $a['id'].'"><button class="btn-danger" data-confirm="Remove this staff account?">×</button></form></td></tr>';
        }
        return Html::page('Staff', $flash.'<div class="bm-card"><h2>Staff</h2>'
            .'<div class="muted">Staff can attend to escalated conversations from the inbox. Owners can also manage staff and settings.</div>'
            .'<table><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>2FA</th><th></th></tr>'.$rows.'</table></div>'
            .'<div class="bm-card"><h2>Two-factor policy</h2>'
            .'<div class="muted">When on, every staff member (owners included) must set up an authenticator app before they can use the panel. Anyone locked out can be reset above.</div>'
            .'<form method="post" action="'.Html::e($this->url('/agents/2fa-require')).'" class="row" style="margin-top:12px;gap:14px">'.$this->csrfField()
            .'<label style="display:flex;align-items:center;gap:10px;margin:0"><span class="switch"><input type="checkbox" name="require_2fa" value="1"'.($this->settings->get('require_2fa', '0') === '1' ? ' checked' : '').'><span class="sl"></span></span> Require 2FA for all staff</label>'
            .'<button type="submit" class="btn2 btn-sm">Save policy</button>'
            .'<a class="btn-ghost btn-sm" href="'.Html::e($this->url('/security')).'">'.Icons::get('shield', 14).' My own 2FA</a></form></div>'
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

        $body = $flash.$this->quickRepliesCard().'<div class="bm-grid c2"><div><div class="bm-card">'
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

    /**
     * Version + release notes from HQ, cached in settings. Never licence-gated
     * and always fail-open: the owner must be able to see that a newer release
     * exists even while the panel is locked.
     */
    private function updates(): array
    {
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
        $cache['outdated'] = \Banimark\Update\UpdateCheck::isNewer($cache['latest'] ?? null);
        return $cache;
    }

    /** Owner-only: one update advisory, then the release notes. */
    private function changelogPage(string $flash): string
    {
        if (!$this->auth->isOwner()) {
            return Html::page('Changelog', '<div class="bm-card"><div class="empty"><b>Owners only</b>'
                .'<div>Only an owner can see release information.</div></div></div>', $this->nav('/changelog'));
        }
        $u = $this->updates();

        $advice = $u['outdated']
            ? '<div class="bm-card" style="border-color:color-mix(in srgb, var(--warn) 40%, transparent)">'
                .'<h2>Update available - '.Html::e((string) $u['latest']).'</h2>'
                .'<div class="muted">You are running '.Html::e(Master::PACKAGE_VERSION)
                .'. Run this in your project, then reload the installer once:</div>'
                .'<textarea readonly rows="1" data-select-all>'.Html::e((string) $u['update_command']).'</textarea></div>'
            : '<div class="bm-card"><b>You are up to date</b><div class="muted">Running '
                .Html::e(Master::PACKAGE_VERSION)
                .(!$u['ok'] ? ' - could not reach Banimark to check for newer releases' : '').'</div></div>';

        $notes = '';
        foreach ((array) $u['releases'] as $r) {
            $notes .= '<div style="border-top:1px solid var(--border);padding:14px 0 2px">'
                .'<div class="row" style="gap:8px"><b>'.Html::e((string) $r['version']).'</b>'
                .((string) $r['version'] === Master::PACKAGE_VERSION ? '<span class="pill active">INSTALLED</span>' : '')
                .'<span class="muted">'.Html::e((string) $r['released_at']).'</span></div>'
                .'<div class="muted" style="white-space:pre-wrap;margin-top:5px">'.Html::e((string) $r['notes']).'</div></div>';
        }

        return Html::page('Changelog', $flash.$advice
            .'<div class="bm-card"><h2>Release notes</h2>'
            .($notes ?: '<div class="muted" style="margin-top:8px">No release notes available right now.</div>')
            .'</div>', $this->nav('/changelog'), 'What is new in Banimark');
    }

    private function licensePage(string $flash): string
    {
        $support = (string) $this->settings->get('support_email', '');
        if (!$this->auth->isOwner()) {
            // staff never touch licensing; while locked they can only sign out
            $lock = $this->auth->lockReason();
            return Html::page('Licence', '<div class="bm-card"><div class="empty"><b>'
                .($lock ? 'This desk is locked' : 'Owners only').'</b><div>'
                .($lock ? 'Ask the account owner to check the licence.' : 'Only an owner can manage the licence.')
                .($support !== '' ? '<br>Need help? <a href="mailto:'.Html::e($support).'">'.Html::e($support).'</a>' : '')
                .'</div></div></div>', $this->nav('/license'));
        }
        $status = (string) $this->settings->get('license_status', '');
        $last = (int) $this->settings->get('license_last_ping', '0');
        $pill = ['active' => 'ai', 'expired' => 'agent'][$status] ?? 'closed';
        $lock = $this->auth->lockReason();
        $banner = $lock !== null ? '<div class="flash-err"><b>Admin locked.</b> '.Html::e($lock['message'])
            .($support !== '' ? ' Need help? <a href="mailto:'.Html::e($support).'">'.Html::e($support).'</a>' : '').'</div>' : '';
        // an ACTIVE key is read-only: swapping it is how a licence walks to another install
        $keyLocked = $status === 'active' && $lock === null;

        return Html::page('License', $flash.$banner.'<div class="bm-card"><h2>License</h2>'
            .'<div class="muted">Your Banimark license key from the purchase email. Checked at most once a day, from this panel only - the check sends the key, this site\'s URL and version numbers, nothing else. An expired or unreachable license never affects the widget or this panel; it only pauses updates.</div>'
            .($status !== '' ? '<p>Status: <span class="pill '.$pill.'">'.strtoupper(Html::e($status)).'</span>'
                .($last > 0 ? ' <span class="muted">checked '.date('d M H:i', $last).'</span>' : '').'</p>' : '')
            .'<form method="post" action="'.Html::e($this->url('/license')).'">'.$this->csrfField()
            .'<label>License key</label><input type="text" name="license_key" value="'.Html::e($this->settings->get('license_key', '')).'" placeholder="BM-XXXX-XXXX-XXXX-XXXX"'.($keyLocked ? ' readonly style="opacity:.7"' : '').'>'
            .($keyLocked ? '<div class="hint">Your licence is active, so the key is locked. It becomes editable if the licence expires or is revoked.</div>' : '')
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
