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
            if ($result === 'pending') {
                echo $this->login('Your account is not activated yet. Use the link in your invitation email, or ask an owner to resend it.');
                return;
            }
            if ($result) {
                header('Location: '.$this->url());
                return;
            }
            echo $this->login('Wrong email or password.');
            return;
        }
        if (preg_match('#^/activate/([a-f0-9]{48})$#', $route, $m)) {
            $agent = $this->agents->findByInviteToken($m[1]);
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $agent) {
                $pw = (string) ($_POST['password'] ?? '');
                if (strlen($pw) < 8 || $pw !== (string) ($_POST['password_confirmation'] ?? '')) {
                    echo Html::activate($this->url('/activate/'.$m[1]), $this->url('/login'), $agent, 'Use at least 8 characters, and type the same password twice.');
                    return;
                }
                $this->agents->activate((int) $agent['id'], (string) ($_POST['name'] ?? $agent['name']), $pw);
                echo $this->login('', 'Your account is active - sign in with your new password.');
                return;
            }
            echo Html::activate($this->url('/activate/'.$m[1]), $this->url('/login'), $agent);
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
        $this->auth->touchActivity(); // "last seen" for the team page; throttled inside

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
        if (!in_array($route, ['/license', '/changelog', '/logout'], true) && !str_starts_with($route, '/license/') && $this->auth->lockReason() !== null) {
            header('Location: '.$this->url('/license'));
            return;
        }
        // owner policy "everyone uses 2FA": an un-enrolled account can only reach the page where it enrols
        if (!in_array($route, ['/security', '/security/begin', '/security/confirm', '/license', '/changelog', '/logout'], true)
            && $this->settings->get('require_2fa', '0') === '1' && !$this->agents->totpEnabled((int) $this->auth->id())) {
            header('Location: '.$this->url('/security'));
            return;
        }
        Layout::configure(['events' => $this->auth->can('inbox.view') ? $this->url('/events') : '', 'conversation' => $this->url('/conversation/__SID__')]);

        // per-staff permissions from ONE map (Permissions::forPath); the licence and
        // changelog pages decide inside (a locked-out staffer still sees "ask your owner")
        if (!in_array($route, ['/license', '/changelog'], true) && !str_starts_with($route, '/license/')) {
            $requirement = \Banimark\Auth\Permissions::forPath($route);
            if (!$this->auth->allowed($requirement)) {
                http_response_code(403);
                echo Html::page('No access', '<div class="bm-card" style="max-width:560px"><div class="row" style="gap:10px"><span class="avatar">'.Icons::get('shield', 16).'</span><div><h2 style="margin:0">You don\'t have access to this</h2>'
                    .'<div class="muted">'.($requirement === 'owner' ? 'Only an owner can open this page.' : 'An owner can grant it under Staff → Access.').'</div></div></div>'
                    .'<div class="row" style="margin-top:16px;gap:8px"><a class="btn2 btn-sm" href="'.Html::e($this->url('/inbox')).'">'.Icons::get('inbox', 14).' Inbox</a><a class="btn-ghost btn-sm" href="'.Html::e($this->url()).'">Dashboard</a></div></div>', $this->nav($route), 'This page is not part of your permissions');
                return;
            }
        }

        $flash = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $flash = $this->handlePost($route);
            if ($flash === null) {
                return; // redirected
            }
        }
        if (isset($_GET['bm_ok']) && trim((string) $_GET['bm_ok']) !== '') {
            $flash .= '<div class="flash-ok">'.Html::e(mb_substr((string) $_GET['bm_ok'], 0, 200)).'</div>';
        }
        $flash = \Banimark\Licensing\HqNotice::html($this->settings->all(), (string) ($_SERVER['HTTP_HOST'] ?? '')).$flash;

        if ($route === '/events') {
            header('Content-Type: application/json');
            echo json_encode($this->store->staffEvents((int) ($_GET['since'] ?? 0)));
            return;
        }
        if (preg_match('#^/conversation/([a-f0-9]{32})/messages$#', $route, $m)) {
            header('Content-Type: application/json');
            if (!empty($_GET['typing'])) {
                $this->store->markTyping($m[1], 'agent'); // the visitor's widget shows the dots
            }
            $this->store->markStaffSeen($m[1]);
            echo json_encode([
                'ok' => true,
                'mode' => $this->store->mode($m[1]),
                'messages' => TranscriptView::rows($this->store->messagesSince($m[1], (int) ($_GET['after'] ?? 0)), $this->attachments()),
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
            str_starts_with($route, '/files') => $this->filesPage($flash),
            $route === '/ai' => Html::page('AI settings', $flash.\Banimark\Ui\Pages::aiSettings($this->settings->all(), $this->url('/ai'), $this->csrfField()), $this->nav('/ai'), 'How the assistant behaves, how much it remembers, what it may cost'),
            str_starts_with($route, '/data') => $this->dataPage($flash),
            $route === '/team' => $this->teamPage($flash),
            // POST handlers that answer with a flash (a validation problem) re-render their page
            str_starts_with($route, '/rules') => $this->rules($flash),
            str_starts_with($route, '/security') => $this->securityPage($flash),
            $route === '/quick-replies' => $this->escalationPage($flash),
            $route === '/providers', $route === '/providers/activate' => $this->providers($flash),
            str_starts_with($route, '/agents') => $this->agentsPage($flash),
            $route === '/escalation', $route === '/escalation/test' => $this->escalationPage($flash),
            $route === '/widget' => $this->widget($flash),
            str_starts_with($route, '/license') => $this->licensePage($flash),
            $route === '/changelog' => $this->changelogPage($flash),
            default => Html::page('Not found', '<div class="bm-card"><h2>Not found</h2></div>', $this->nav()),
        };
    }

    /** @return string|null flash html, or null when a redirect was sent */
    private function handlePost(string $route): ?string
    {
        $p = $_POST;
        if (preg_match('#^/conversation/([a-f0-9]{32})/upload$#', $route, $m)) {
            header('Content-Type: application/json');
            $settings = $this->settings->all();
            $up = $_FILES['file'] ?? null;
            if (!$this->auth->can('inbox.reply')) {
                http_response_code(403); echo json_encode(['ok' => false, 'error' => 'You do not have permission to reply here.']); return null;
            }
            if (!\Banimark\Files\FileStoreFactory::enabled($settings)) {
                http_response_code(422); echo json_encode(['ok' => false, 'error' => 'File sharing is switched off on the Files page.']); return null;
            }
            if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) {
                http_response_code(422); echo json_encode(['ok' => false, 'error' => 'That upload did not arrive in one piece.']); return null;
            }
            $bytes = (string) @file_get_contents($up['tmp_name']);
            $check = \Banimark\Files\UploadPolicy::fromSettings($settings)->check((string) $up['name'], (int) $up['size'], $bytes);
            if (!$check['ok']) {
                http_response_code(422); echo json_encode(['ok' => false, 'error' => $check['error']]); return null;
            }
            $files = \Banimark\Files\FileStoreFactory::make($settings, dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__).'/banimark-files');
            $key = \Banimark\Files\UploadPolicy::key($check['ext']);
            if (!$files->put($key, $bytes, $check['mime'])) {
                http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Could not store the file: '.$files->lastError()]); return null;
            }
            $row = $this->attachments()->create($m[1], $files->name(), $key, $check['name'], $check['mime'], strlen($bytes), 'agent');
            echo json_encode(['ok' => true, 'attachment' => [
                'id' => (int) $row['id'], 'token' => (string) $row['token'], 'name' => (string) $row['name'],
                'mime' => (string) $row['mime'], 'size' => (int) $row['size'],
                'is_image' => \Banimark\Files\UploadPolicy::isImage((string) $row['mime']),
            ]]);
            return null;
        }
        if (preg_match('#^/conversation/([a-f0-9]{32})/reply$#', $route, $m)) {
            $text = trim((string) ($p['message'] ?? ''));
            $files = $this->attachments()->pending((array) ($p['attachments'] ?? []), $m[1]);
            if ($files !== []) {
                // files ride in the text as markers - the same shape the visitor's do
                $text = \Banimark\Files\Markers::append($text, $files);
                $this->attachments()->markSent(array_column($files, 'token'), $m[1]);
            }
            $emailed = false;
            $row = null;
            if ($text !== '') {
                $this->store->appendAgentMessage($m[1], $text, (int) $this->auth->id());
                $all = $this->store->transcript($m[1]);
                $row = $all === [] ? null : TranscriptView::row(end($all), $this->attachments());
                // the visitor may have closed the tab - post the reply on to them
                try {
                    $emailed = (new FollowUp($this->store, MailerFactory::make($this->settings->all()), $this->settings->all()))
                        ->afterAgentReply($m[1], \Banimark\Files\Markers::parse($text)['text'] ?: 'Please see the attached file.');
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
        if ($route === '/ai') {
            \Banimark\Ui\Pages::saveAiSettings($p, fn (string $k, string $v) => $this->settings->set($k, $v));
            return '<div class="flash-ok">AI settings saved. They apply from the next message.</div>';
        }
        if ($route === '/data') {
            \Banimark\Ui\Pages::saveDataSettings($p, fn (string $k, string $v) => $this->settings->set($k, $v));
            return '<div class="flash-ok">Saved.</div>';
        }
        if ($route === '/data/delete-all') {
            if (trim((string) ($p['confirm'] ?? '')) !== 'DELETE') {
                return '<div class="flash-err">Type DELETE in the box to confirm.</div>';
            }
            $n = $this->retention()->deleteAll();
            return '<div class="flash-ok">Deleted '.$n.' conversation(s) and everything in them.</div>';
        }
        if (preg_match('#^/conversation/([a-f0-9]{32})/delete$#', $route, $m)) {
            $this->retention()->deleteConversation($m[1]);
            header('Location: '.$this->url('/inbox').'?bm_ok='.rawurlencode('Conversation deleted.'));
            return null;
        }
        if (preg_match('#^/conversation/([a-f0-9]{32})/forget$#', $route, $m)) {
            $identity = $this->store->identityOf($m[1]);
            if ($identity === '' || $identity === 'anon') {
                $this->retention()->deleteConversation($m[1]);
                $notice = 'Conversation deleted (the visitor was anonymous, so there was nothing else of theirs to find).';
            } else {
                $notice = 'Deleted '.$this->retention()->deleteVisitor($identity).' conversation(s) from that visitor.';
            }
            header('Location: '.$this->url('/inbox').'?bm_ok='.rawurlencode($notice));
            return null;
        }
        if ($route === '/files') {
            $set = fn (string $k, string $v) => $this->settings->set($k, $v);
            $set('files_enabled', !empty($p['files_enabled']) ? '1' : '0');
            $set('files_max_mb', (string) max(1, min(100, (int) ($p['files_max_mb'] ?? 10))));
            $set('files_types', trim((string) ($p['files_types'] ?? '')));
            $set('files_driver', ($p['files_driver'] ?? '') === 's3' ? 's3' : 'local');
            $set('files_local_path', trim((string) ($p['files_local_path'] ?? '')));
            foreach (['files_s3_bucket', 'files_s3_region', 'files_s3_endpoint', 'files_s3_prefix', 'files_s3_key'] as $k) {
                $set($k, trim((string) ($p[$k] ?? '')));
            }
            $set('files_s3_path_style', !empty($p['files_s3_path_style']) ? '1' : '0');
            $secret = (string) ($p['files_s3_secret'] ?? ''); // blank keeps the stored one
            if ($secret !== '') {
                $set('files_s3_secret', $secret);
            }
            return '<div class="flash-ok">File settings saved.</div>';
        }
        if ($route === '/files/test') {
            $settings = $this->settings->all();
            $problem = \Banimark\Files\FileStoreFactory::misconfigured($settings);
            if ($problem !== '') {
                return '<div class="flash-err">'.Html::e($problem).'</div>';
            }
            $r = \Banimark\Files\SelfTest::run(\Banimark\Files\FileStoreFactory::make($settings, dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__).'/banimark-files'));
            return '<div class="'.($r['ok'] ? 'flash-ok' : 'flash-err').'">'.Html::e($r['message']).'</div>';
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
        if ($route === '/tools/try') {
            header('Content-Type: application/json');
            $context = array_filter((array) ($p['context_values'] ?? []), fn ($v) => $v !== '' && $v !== null);
            echo json_encode(\Banimark\Tools\ToolTester::run(self::toolDefinitionFromForm($p), (array) ($p['args'] ?? []), $context,
                function (string $sql, array $bindings) {
                    $st = $this->pdo->prepare($sql);
                    foreach ($bindings as $k => $v) {
                        $st->bindValue(':'.$k, $v);
                    }
                    $st->execute();
                    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                }));
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
        if ($route === '/providers/activate') {
            // exactly one provider answers the widget
            $slug = (string) ($p['slug'] ?? '');
            if ($this->query('SELECT 1 FROM banimark_providers WHERE slug = ?', [$slug]) === []) {
                return '<div class="flash-err">Unknown provider.</div>';
            }
            $this->exec('UPDATE banimark_providers SET enabled = 0, is_default = 0', []);
            $this->exec('UPDATE banimark_providers SET enabled = 1, is_default = 1 WHERE slug = ?', [$slug]);
            return '<div class="flash-ok">"'.Html::e($slug).'" is now the provider answering your chat.</div>';
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
            $perms = ($p['preset'] ?? 'agent') === 'custom' ? (array) ($p['perms'] ?? []) : \Banimark\Auth\Permissions::preset((string) ($p['preset'] ?? 'agent'));
            $inv = $this->agents->invite((string) ($p['name'] ?? ''), (string) ($p['email'] ?? ''), ($p['role'] ?? '') === 'owner' ? 'owner' : 'agent', $perms);
            if ($inv === false) {
                return '<div class="flash-err">That email is already a staff account, or the address is invalid.</div>';
            }
            return $this->sendInvite($this->agents->find($inv['id']), $inv['token']);
        }
        if ($route === '/agents/reinvite') {
            if (!$this->auth->isOwner()) {
                return '<div class="flash-err">Only an owner can resend invitations.</div>';
            }
            $token = $this->agents->reinvite((int) ($p['id'] ?? 0));
            return $token === null ? '<div class="flash-err">That account is not pending.</div>' : $this->sendInvite($this->agents->find((int) $p['id']), $token);
        }
        if ($route === '/agents/permissions') {
            if (!$this->auth->isOwner()) {
                return '<div class="flash-err">Only an owner can change permissions.</div>';
            }
            $id = (int) ($p['id'] ?? 0);
            $target = $this->agents->find($id);
            if (!$target) {
                return '<div class="flash-err">Unknown staff member.</div>';
            }
            $this->agents->setRole($id, ($p['role'] ?? '') === 'owner' ? 'owner' : 'agent');
            $perms = ($p['preset'] ?? 'custom') === 'custom' ? (array) ($p['perms'] ?? []) : \Banimark\Auth\Permissions::preset((string) $p['preset']);
            $this->agents->setPermissions($id, $perms);
            return '<div class="flash-ok">Access for '.Html::e($target['name']).' updated - it applies on their next click.</div>';
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
            $this->settings->set('theme', in_array($p['theme'] ?? '', ['auto', 'light', 'dark'], true) ? $p['theme'] : 'auto');
            return '<div class="flash-ok">Widget saved.</div>';
        }
        if ($route === '/license/trial' || $route === '/license/recheck') {
            if (!$this->auth->isOwner()) {
                return '<div class="flash-err">Only an owner can manage the licence.</div>';
            }
            $set = fn (string $k, string $v) => $this->settings->set($k, $v);
            $forget = fn (string $k) => $this->settings->set($k, '');
            if ($route === '/license/trial') {
                $r = \Banimark\Licensing\PhoneHome::startTrial($this->settings->all(), Master::siteUrlFromServer($_SERVER), $set, $forget);
                if (!empty($r['ok'])) {
                    header('Location: '.$this->url()); // straight into the desk
                    return null;
                }
                return '<div class="flash-err">'.Html::e(($r['message'] ?? '') !== '' ? $r['message'] : 'Could not reach Banimark HQ to start the trial. Try again in a moment, or enter a purchased key.').'</div>';
            }
            $r = \Banimark\Licensing\PhoneHome::run($this->settings->all(), Master::siteUrlFromServer($_SERVER), $set, $forget, force: true);
            return ($r === null || empty($r['ok']))
                ? '<div class="flash-err">'.Html::e(\Banimark\Licensing\PhoneHome::unreachableMessage($this->settings->all())).'</div>'
                : '<div class="flash-ok">Checked with HQ just now - status: <b>'.Html::e($r['license']).'</b>.</div>';
        }
        if ($route === '/license') {
            if (!$this->auth->isOwner()) {
                return '<div class="flash-err">Only an owner can manage the licence.</div>';
            }
            $key = trim((string) ($p['license_key'] ?? ''));
            $details = json_decode((string) $this->settings->get('license_details', ''), true) ?: [];
            // an ACTIVE paid key is read-only (swapping it is how a licence walks to
            // another install); a TRIAL key may be replaced by a purchased one
            if ($this->settings->get('license_status') === 'active' && $this->auth->lockReason() === null
                && ($details['plan'] ?? '') !== 'trial'
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

    /** The tool definition as typed into the form - shared by save and "Try it". */
    private static function toolDefinitionFromForm(array $p): array
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
        return [
            'name' => strtolower(trim((string) ($p['name'] ?? ''))),
            'description' => trim((string) ($p['description'] ?? '')),
            'parameters' => $params,
            'sql' => trim((string) ($p['sql'] ?? '')),
            'columns' => array_values(array_filter(array_map('trim', explode(',', (string) ($p['columns'] ?? ''))))),
            'context' => array_values(array_filter(array_map('trim', explode(',', (string) ($p['context'] ?? ''))))),
            'max_rows' => max(1, min(50, (int) ($p['max_rows'] ?? 10))),
        ];
    }

    private function saveTool(array $p): ?string
    {
        $definition = self::toolDefinitionFromForm($p);
        try {
            SqlTool::fromDefinition($definition, fn () => []);
        } catch (\Throwable $e) {
            return '<div class="flash-err">'.Html::e($e->getMessage()).'</div>';
        }
        // editing: original_name is the row to replace, name may be a rename
        $original = trim((string) ($p['original_name'] ?? ''));
        $target = $original !== '' && $this->query('SELECT 1 FROM banimark_tools WHERE name = ?', [$original]) !== [] ? $original : $definition['name'];
        if ($target !== $definition['name'] && $this->query('SELECT 1 FROM banimark_tools WHERE name = ?', [$definition['name']]) !== []) {
            return '<div class="flash-err">A tool called "'.Html::e($definition['name']).'" already exists.</div>';
        }
        $this->exec('DELETE FROM banimark_tools WHERE name = ?', [$target]);
        $this->exec('INSERT INTO banimark_tools (name, description, parameters, `sql`, columns, context, max_rows, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $definition['name'], $definition['description'], json_encode($definition['parameters']),
            $definition['sql'], json_encode($definition['columns']), json_encode($definition['context']),
            $definition['max_rows'], !empty($p['enabled']) ? 1 : 0, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);
        header('Location: '.$this->url('/tools'));
        return null;
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
        $enabled = !empty($p['enabled']) ? 1 : 0;
        if ($enabled) {
            // ONE provider at a time: enabling this one disables every other
            $this->exec('UPDATE banimark_providers SET enabled = 0, is_default = 0', []);
        }
        $this->exec('DELETE FROM banimark_providers WHERE slug = ?', [$slug]);
        $this->exec('INSERT INTO banimark_providers (slug, driver, api_key, model, base_url, temperature, enabled, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $slug, $driver, $key !== '' ? $key : $existing['api_key'], $model,
            ($p['driver'] ?? '') === 'openai-compat' ? (trim((string) ($p['base_url'] ?? '')) ?: null) : null,
            max(0, min(2, (float) ($p['temperature'] ?? 0.4))),
            $enabled, $enabled,
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);
        if ($this->query('SELECT 1 FROM banimark_providers WHERE enabled = 1', []) === []) {
            $first = $this->query('SELECT slug FROM banimark_providers ORDER BY id LIMIT 1', [])[0]['slug'] ?? null;
            if ($first) { $this->exec('UPDATE banimark_providers SET enabled = 1, is_default = 1 WHERE slug = ?', [$first]); }
        }
        return '<div class="flash-ok">Provider saved.'.($enabled ? ' It is now the one answering your chat.' : '').'</div>';
    }

    /* ---------------- pages ---------------- */

    /** Sidebar links, with the current section highlighted. */
    private function nav(string $current = ''): string
    {
        // grouped by MODULE - Support Desk is the first of several; each link
        // shows only when this staff member may open it
        $can = fn (string $p) => $this->auth->can($p);
        $items = [['Support Desk']];
        foreach ([
            ['/', 'dashboard', 'Dashboard', 'dashboard.view'],
            ['/inbox', 'inbox', 'Inbox', 'inbox.view'],
            ['/tools', 'tools', 'Tools', 'tools.manage'],
            ['/rules', 'rules', 'Rules', 'rules.manage'],
            ['/providers', 'providers', 'AI providers', 'providers.manage'],
            ['/widget', 'widget', 'Widget', 'widget.manage'],
            ['/files', 'files', 'Files', 'files.manage'],
            ['/ai', 'providers', 'AI settings', 'ai.manage'],
            ['/team', 'staff', 'Team', 'team.view'],
            ['/data', 'shield', 'Data & protection', 'data.manage'],
            ['/escalation', 'escalation', 'Notifications', 'notifications.manage'],
        ] as [$path, $icon, $label, $perm]) {
            if ($can($perm)) {
                $items[] = [$path, $icon, $label];
            }
        }
        $items[] = ['Account'];
        if ($this->auth->isOwner()) {
            $items[] = ['/agents', 'staff', 'Staff'];
        }
        $items[] = ['/security', 'shield', 'Security'];
        if ($this->auth->isOwner()) {
            $items[] = ['/license', 'license', 'License'];
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
                'icon' => $icon, 'label' => $label, 'on' => $current === $path || ($path !== '/' && str_starts_with($current, $path)),
            ]);
        }
        return $out.'<span class="lbl">Session</span>'
            .Layout::navLink(['href' => $this->url('/logout'), 'icon' => 'logout', 'label' => 'Sign out']);
    }

    private ?\Banimark\Storage\Attachments $attachmentsRepo = null;

    private function attachments(): \Banimark\Storage\Attachments
    {
        return $this->attachmentsRepo ??= new \Banimark\Storage\Attachments($this->pdo);
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

    private function login(string $error = '', string $notice = ''): string
    {
        return Html::auth($this->url('/login'), $error, $notice);
    }

    private function inbox(string $flash): string
    {
        $filters = [
            'mode' => in_array($_GET['mode'] ?? '', ['ai', 'agent', 'closed'], true) ? $_GET['mode'] : null,
            'q' => trim((string) ($_GET['q'] ?? '')),
            'unread' => empty($_GET['unread']) ? 0 : 1,
            'waiting' => empty($_GET['waiting']) ? 0 : 1,
            'files' => empty($_GET['files']) ? 0 : 1,
            'known' => empty($_GET['known']) ? 0 : 1,
            'sort' => ($_GET['sort'] ?? '') === 'waiting' ? 'waiting' : '',
        ];
        $counts = $this->store->inboxCounts();
        return Html::page('Inbox', $flash.\Banimark\Ui\Pages::inbox(
            $this->store->listConversations(100, $filters['mode'], $filters['q'], $filters),
            $counts, $filters, $this->url('/inbox'),
            fn (string $sid) => $this->url('/conversation/'.$sid),
            $this->auth->name(),
        ), $this->nav('/inbox'), \Banimark\Ui\Pages::inboxSubtitle($counts));
    }

    private function conversation(string $sessionId, string $flash): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return Html::page('Not found', '<div class="bm-card"><h2>Not found</h2></div>', $this->nav('/inbox'));
        }
        $e = fn ($v) => Html::e((string) $v);
        $mode = $this->store->mode($sessionId);
        $rows = TranscriptView::rows($this->store->transcript($sessionId), $this->attachments());
        $this->store->markStaffSeen($sessionId); // opening it clears the unread dot in the inbox
        $filesOn = \Banimark\Files\FileStoreFactory::enabled($this->settings->all());
        $lastId = $rows === [] ? 0 : end($rows)['id'];
        $presence = $this->store->presence($sessionId) ?? [];
        $label = (string) ($presence['visitor_label'] ?? '') ?: 'Visitor';
        $online = (int) ($presence['last_seen_at'] ?? 0) > time() - 45;
        $msgs = '';
        foreach ($rows as $m) {
            if ($m['role'] === 'tool') {
                $msgs .= '<div class="msg tool" data-id="'.$m['id'].'">'.Icons::get('bolt', 12).' '.$e($m['text']).'</div>';
            } else {
                $atts = '';
                foreach ($m['files'] ?? [] as $f) {
                    $url = $e($this->base.'/file/'.$f['token']);
                    $atts .= $f['is_image']
                        ? '<a class="msg-att" href="'.$url.'" target="_blank" rel="noopener"><img src="'.$url.'" alt="'.$e($f['name']).'" loading="lazy"></a>'
                        : '<a class="msg-att file" href="'.$url.'?download=1" target="_blank" rel="noopener">📎 <b>'.$e($f['name']).'</b> <span>'
                            .($f['size'] > 1048576 ? round($f['size'] / 1048576, 1).' MB' : round($f['size'] / 1024).' KB').'</span></a>';
                }
                $msgs .= '<div class="msg '.$e($m['role']).'" data-id="'.$m['id'].'">'.\Banimark\Ui\Markdown::toHtml($m['text']).$atts
                    .'<div class="msg-meta">'.($m['role'] === 'agent' ? $e(($m['by'] ?? '') !== '' ? $m['by'] : 'human agent').' · ' : ($m['role'] === 'assistant' ? 'AI · ' : '')).($m['at'] ? date('H:i', $m['at']) : '').'</div></div>';
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
            .($this->auth->can('inbox.delete')
                ? '<form method="post" action="'.$e($this->url('/conversation/'.$sessionId.'/delete')).'" style="display:inline">'.$this->csrfField()
                    .'<button class="btn-ghost btn-sm" data-confirm="Delete this conversation and its files? This cannot be undone.">'.Icons::get('trash', 14).' Delete</button></form>'
                  .'<form method="post" action="'.$e($this->url('/conversation/'.$sessionId.'/forget')).'" style="display:inline">'.$this->csrfField()
                    .'<button class="btn-ghost btn-sm" data-confirm="Delete EVERY conversation from this visitor? This cannot be undone." title="Every thread this visitor ever had">Forget this visitor</button></form>'
                : '')
            .$modeForm('closed', 'Close', 'btn-danger', 'Close this conversation?');

        $body = $flash.'<div class="bm-card" data-live-chat data-session="'.$e($sessionId).'" data-mode="'.$e($mode).'" data-after="'.$lastId.'"'
            .' data-messages-url="'.$e($this->url('/conversation/'.$sessionId.'/messages')).'" data-reply-url="'.$e($this->url('/conversation/'.$sessionId.'/reply')).'"'
            .' data-csrf-name="_csrf" data-csrf="'.$e($this->auth->csrf()).'"'
            .' data-upload-url="'.$e($this->url('/conversation/'.$sessionId.'/upload')).'" data-file-url="'.$e($this->base.'/file/').'" style="position:relative">'
            .'<div class="bm-sec-h"><div class="row"><span class="avatar">'.$e(strtoupper(mb_substr($label, 0, 1))).'</span><div>'
            .'<h2 style="margin:0">'.$e($label).' <span class="pill '.$e($mode).'" data-mode-pill>'.strtoupper($e($mode)).'</span></h2>'
            .'<span class="bm-presence '.($online ? 'on' : 'off').'" data-presence>'.$e($label).($online ? ' · online now' : (!empty($presence['last_seen_at']) ? ' · left the chat' : '')).'</span>'
            .(!empty($presence['visitor_email']) ? '<span class="muted" style="margin-left:8px">'.$e($presence['visitor_email']).'</span>' : '')
            .'</div></div></div>'
            .'<div class="msgs" data-thread data-autoscroll style="max-height:56vh;overflow-y:auto;padding-right:4px">'.$msgs.'</div>'
            .'<div class="bm-typing" data-typing hidden><i></i><i></i><i></i></div><div class="flash-ok" data-flash hidden></div>'
            .'<div class="bm-quick">'.$quick.'</div>'
            .'<div data-pending hidden style="padding:6px 0 0"></div>'
            .'<form method="post" action="'.$e($this->url('/conversation/'.$sessionId.'/reply')).'" class="bm-compose" data-reply>'.$this->csrfField()
            .'<button type="button" class="btn-ghost btn-icon" data-emoji title="Emoji">🙂</button>'
            .($filesOn ? '<button type="button" class="btn-ghost btn-icon" data-attach title="Attach a file">📎</button><input type="file" data-file hidden>' : '')
            .'<textarea name="message" rows="1" placeholder="Reply as a human agent… (Enter to send, Shift+Enter for a new line)" autofocus autocomplete="off"></textarea>'
            .'<button type="submit">'.Icons::get('send', 15).' Send</button></form></div>'
            .Layout::chatScript();
        return Html::page('Conversation', $body, $this->nav('/inbox'), 'Replying takes over - the AI stays silent until you hand it back', $actions);
    }

    private function filesPage(string $flash): string
    {
        $e = fn ($v) => Html::e((string) $v);
        $s = $this->settings;
        $all = $s->all();
        $defaultDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__).'/banimark-files';
        $problem = \Banimark\Files\FileStoreFactory::misconfigured($all);
        $row = $this->query('SELECT COUNT(*) AS c, COALESCE(SUM(size), 0) AS s FROM banimark_attachments', [])[0] ?? ['c' => 0, 's' => 0];
        $driver = $s->get('files_driver', 'local');
        $hasSecret = trim((string) $s->get('files_s3_secret', '')) !== '';
        $csrf = $this->csrfField();
        $types = \Banimark\Files\UploadPolicy::TYPES;

        return Html::page('Files', $flash
            .($problem !== '' ? '<div class="flash-err">'.Icons::get('escalation', 16).'<span>'.$e($problem).'</span></div>' : '')
            .'<form method="post" action="'.$e($this->url('/files')).'">'.$csrf
            .'<div class="bm-card"><div class="bm-sec-h"><div><h2>File sharing</h2>'
            .'<div class="muted">Visitors and staff can attach files to a message. Turn it off and the paperclip disappears everywhere.</div></div>'
            .'<div class="spacer"></div><label style="display:flex;align-items:center;gap:10px;margin:0"><span class="switch">'
            .'<input type="checkbox" name="files_enabled" value="1"'.($s->get('files_enabled', '1') === '1' ? ' checked' : '').'><span class="sl"></span></span> Allow files</label></div>'
            .'<div class="grid2" style="margin-top:14px"><div><label>Largest file (MB)</label>'
            .'<input type="number" name="files_max_mb" min="1" max="100" value="'.$e($s->get('files_max_mb', (string) \Banimark\Files\UploadPolicy::DEFAULT_MAX_MB)).'"></div>'
            .'<div><label>Accepted types <span class="muted">(comma-separated, blank = the list below)</span></label>'
            .'<input type="text" name="files_types" value="'.$e($s->get('files_types', '')).'" placeholder="png, jpg, pdf, docx"></div></div>'
            .'<div class="hint">Default: '.$e(implode(', ', array_keys($types))).'. Programs and scripts are never accepted, whatever you type here.</div></div>'

            .'<div class="bm-card"><h2>Where they are stored</h2><div class="row" style="gap:10px;margin:12px 0">'
            .'<label style="display:flex;gap:9px;align-items:flex-start;padding:12px;border:1px solid var(--border-2);border-radius:var(--r);flex:1;cursor:pointer">'
            .'<input type="radio" name="files_driver" value="local"'.($driver !== 's3' ? ' checked' : '').' style="margin-top:2px">'
            .'<span><b>This server</b><div class="muted">Simplest. Files sit in a folder only Banimark reads - never in a public directory.</div></span></label>'
            .'<label style="display:flex;gap:9px;align-items:flex-start;padding:12px;border:1px solid var(--border-2);border-radius:var(--r);flex:1;cursor:pointer">'
            .'<input type="radio" name="files_driver" value="s3"'.($driver === 's3' ? ' checked' : '').' style="margin-top:2px">'
            .'<span><b>S3-compatible storage</b><div class="muted">AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, MinIO.</div></span></label></div>'
            .'<label>Folder on this server <span class="muted">(blank = '.$e($defaultDir).')</span></label>'
            .'<input type="text" name="files_local_path" value="'.$e($s->get('files_local_path', '')).'" placeholder="'.$e($defaultDir).'">'
            .'<div class="divider"></div><div class="grid2">'
            .'<div><label>Bucket</label><input type="text" name="files_s3_bucket" value="'.$e($s->get('files_s3_bucket', '')).'" placeholder="my-support-files"></div>'
            .'<div><label>Region</label><input type="text" name="files_s3_region" value="'.$e($s->get('files_s3_region', 'us-east-1')).'" placeholder="eu-west-1"></div>'
            .'<div><label>Access key ID</label><input type="text" name="files_s3_key" value="'.$e($s->get('files_s3_key', '')).'" autocomplete="off"></div>'
            .'<div><label>Secret access key '.($hasSecret ? '<span class="muted">(stored - blank keeps it)</span>' : '').'</label>'
            .'<input type="password" name="files_s3_secret" value="" autocomplete="new-password" placeholder="'.($hasSecret ? '•••••••• (unchanged)' : '').'"></div>'
            .'<div><label>Endpoint <span class="muted">(only for R2 / Spaces / MinIO)</span></label>'
            .'<input type="text" name="files_s3_endpoint" value="'.$e($s->get('files_s3_endpoint', '')).'" placeholder="https://&lt;account&gt;.r2.cloudflarestorage.com"></div>'
            .'<div><label>Key prefix <span class="muted">(optional)</span></label><input type="text" name="files_s3_prefix" value="'.$e($s->get('files_s3_prefix', '')).'" placeholder="support"></div></div>'
            .'<label style="display:flex;align-items:center;gap:8px;margin-top:10px"><input type="checkbox" name="files_s3_path_style" value="1"'
            .($s->get('files_s3_path_style', '0') === '1' ? ' checked' : '').'> Put the bucket in the path, not the hostname <span class="muted">(MinIO and some proxies need this)</span></label>'
            .'<div class="hint">Your keys never leave this server; files are fetched back through short-lived signed links.</div>'
            .'<div style="margin-top:16px"><button type="submit">'.Icons::get('check', 15).' Save</button></div></div></form>'

            .'<div class="bm-card"><h2>Check it works</h2>'
            .'<div class="muted">Writes a small test file with your saved settings, reads it back and deletes it. Nothing is added to any conversation.</div>'
            .'<form method="post" action="'.$e($this->url('/files/test')).'" style="margin-top:12px">'.$csrf
            .'<button type="submit" class="btn2">Send a test file</button></form></div>'

            .'<div class="bm-card"><h2>What is stored now</h2><div class="row" style="gap:26px;margin-top:8px">'
            .'<div><div class="muted">Files</div><b style="font-size:20px">'.(int) $row['c'].'</b></div>'
            .'<div><div class="muted">Total size</div><b style="font-size:20px">'.($row['s'] > 1048576 ? round($row['s'] / 1048576, 1).' MB' : round($row['s'] / 1024).' KB').'</b></div>'
            .'<div><div class="muted">Current store</div><b style="font-size:20px">'.($driver === 's3' ? 'S3' : 'This server').'</b></div></div>'
            .'<div class="hint">Changing store does not move existing files: anything already uploaded is still served from where it was written.</div></div>',
            $this->nav('/files'), 'Where files shared in a chat are kept');
    }

    private function retention(): \Banimark\Storage\Retention
    {
        $files = \Banimark\Files\FileStoreFactory::make($this->settings->all(), dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__).'/banimark-files');
        return new \Banimark\Storage\Retention($this->pdo, $files);
    }

    private function dataPage(string $flash): string
    {
        $one = fn (string $sql) => (int) ($this->query($sql, [])[0]['n'] ?? 0);
        $stats = [
            'conversations' => $one('SELECT COUNT(*) AS n FROM banimark_conversations'),
            'messages' => $one('SELECT COUNT(*) AS n FROM banimark_messages'),
            'files' => $one('SELECT COUNT(*) AS n FROM banimark_attachments'),
            'oldest' => $one('SELECT COALESCE(MIN(last_message_at), 0) AS n FROM banimark_conversations WHERE last_message_at > 0'),
        ];
        return Html::page('Data & protection', $flash.\Banimark\Ui\Pages::dataPage($this->settings->all(), $stats,
            ['save' => $this->url('/data'), 'delete_all' => $this->url('/data/delete-all')], $this->csrfField()),
            $this->nav('/data'), 'Retention, deletion, and limits that keep bots out');
    }

    private function teamPage(string $flash): string
    {
        $days = in_array((int) ($_GET['days'] ?? 7), [7, 30, 90], true) ? (int) ($_GET['days'] ?? 7) : 7;
        $stats = new \Banimark\Storage\TeamStats($this->pdo);
        $since = time() - $days * 86400;
        return Html::page('Team', $flash.\Banimark\Ui\Pages::team($stats->summary($since), $stats->recent(25), $stats->overview($since), $days,
            $this->url('/team'), fn (string $sid) => $this->url('/conversation/'.$sid)), $this->nav('/team'), 'Who is answering, and how fast');
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
        $ed = null; // editing an existing tool?
        if (($name = (string) ($_GET['edit'] ?? '')) !== '') {
            $row = $this->query('SELECT * FROM banimark_tools WHERE name = ?', [$name])[0] ?? null;
            if ($row) {
                $params = [];
                foreach ((array) (json_decode($row['parameters'], true) ?: []) as $pn => $spec) {
                    $params[] = ['name' => $pn, 'type' => $spec['type'] ?? 'string', 'desc' => $spec['description'] ?? '', 'required' => !empty($spec['required'])];
                }
                $ed = $row + ['params' => $params, 'columns_csv' => implode(', ', json_decode($row['columns'], true) ?: []), 'context_csv' => implode(', ', json_decode((string) $row['context'], true) ?: [])];
            }
        }
        $rows = '';
        foreach ($this->query('SELECT * FROM banimark_tools ORDER BY id', []) as $r) {
            $rows .= '<tr'.($r['enabled'] ? '' : ' style="opacity:.55"').'><td><div class="row">'.Icons::get('tools', 15).'<b class="mono" style="background:none;padding:0">'.$e($r['name']).'</b></div></td>'
                .'<td class="muted">'.$e(mb_substr($r['description'], 0, 110)).'</td>'
                .'<td class="muted">'.($e(implode(', ', array_keys(json_decode($r['parameters'], true) ?: []))) ?: '&mdash;')
                    .(($needs = \Banimark\Tools\ToolTester::identityKeys((string) $r['sql'])) !== [] ? '<div class="hint" style="margin:3px 0 0" title="The widget must be embedded with a signed token carrying these values">Needs a signed-in visitor: '.$e(implode(', ', $needs)).'</div>' : '').'</td>'
                .'<td style="font-variant-numeric:tabular-nums">'.(int) $r['max_rows'].'</td>'
                .'<td><span class="pill '.($r['enabled'] ? 'good' : 'closed').'">'.($r['enabled'] ? 'ON' : 'OFF').'</span></td>'
                .'<td class="row" style="gap:4px"><a class="btn-ghost btn-sm" href="'.$e($this->url('/tools').'?edit='.rawurlencode($r['name'])).'#build">Edit</a>'
                .'<form method="post" action="'.$e($this->url('/tools/delete')).'">'.$this->csrfField().'<input type="hidden" name="name" value="'.$e($r['name']).'">'
                .'<button class="btn-ghost btn-icon" data-confirm="Delete this tool?" title="Delete">'.Icons::get('trash', 15).'</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">'.Chart::empty('No tools yet', 'The AI can chat, but it cannot look anything up until you build one.').'</td></tr>';
        }
        $html = $flash
            .'<div class="bm-card pad0"><div class="bm-sec-h" style="padding:18px 20px 0"><div><h2>Your tools</h2>'
            .'<div class="muted">Each tool is one question the AI can answer from your data, e.g. "find this customer\'s orders".</div></div></div>'
            .'<div class="t-wrap"><table><tr><th>Name</th><th>What it does</th><th>Asks the customer for</th><th>Rows</th><th>Status</th><th></th></tr>'.$rows.'</table></div></div>'
            .'<div class="bm-card" id="build"><div class="bm-sec-h"><div><h2>'.($ed ? 'Edit tool: '.$e($ed['name']) : 'Build a tool').'</h2>'
            .'<div class="muted">'.($ed ? 'Change anything below and save - the AI uses the new version straight away.' : 'Three steps: name it, say what the AI needs to ask the customer, then point at your data. No SQL knowledge needed - the builder writes it for you.').'</div></div>'
            .($ed ? '<a class="btn-ghost btn-sm" href="'.$e($this->url('/tools')).'">Cancel - build a new one instead</a>' : '').'</div>'
            .'<form method="post" action="'.$e($this->url('/tools')).'">'.$this->csrfField()
            .($ed ? '<input type="hidden" name="original_name" value="'.$e($ed['name']).'">' : '')
            .'<h3 class="bm-step">1. What is this tool?</h3>'
            .'<div class="grid2"><div><label>Name <span class="muted">(letters, numbers, underscores)</span></label><input type="text" name="name" required placeholder="find_orders" value="'.$e($ed['name'] ?? '').'"></div>'
            .'<div><label>Most rows to return</label><input type="number" name="max_rows" value="'.(int) ($ed['max_rows'] ?? 10).'" min="1" max="50"></div></div>'
            .'<label>Describe it in plain words - the AI reads this to know when to use it</label>'
            .'<textarea name="description" required placeholder="Look up a customer\'s orders by order number or by what they bought.">'.$e($ed['description'] ?? '').'</textarea>'
            .'<label style="display:flex;align-items:center;gap:8px;margin-top:10px"><input type="checkbox" name="enabled" value="1"'.(($ed ? !empty($ed['enabled']) : true) ? ' checked' : '').'> Tool is on (the AI may use it)</label>'
            .'<h3 class="bm-step">2. What should the AI ask the customer for?</h3>'
            .'<div class="muted" style="margin-bottom:8px">Each item becomes a question the AI can ask (an order number, a date, a product name). Add as many as you need.</div>'
            .'<div data-params data-prefill="'.$e(json_encode($ed['params'] ?? [])).'"></div><button type="button" class="btn-ghost btn-sm" data-add-param>'.Icons::get('plus', 14).' Add another</button>'
            .'<h3 class="bm-step">3. Where is the data?</h3>'
            .'<div data-toolbuilder data-schema-url="'.$e($this->url('/tools/schema')).'" style="background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:14px 16px">'
            .'<div class="row" style="justify-content:space-between"><b>Visual builder</b><span class="muted" data-status>…</span></div>'
            .'<div class="grid2" style="margin-top:8px"><div><label>Table</label><select data-table><option value="">Loading…</option></select></div>'
            .'<div><label>Who is chatting is identified by <span class="muted">(identity keys, comma-separated)</span></label><input type="text" name="context" value="'.$e($ed['context_csv'] ?? 'user_id').'" placeholder="user_id"></div></div>'
            .'<label>Columns the AI may show the customer</label><div data-columns><span class="muted">Pick a table first.</span></div>'
            .'<label style="margin-top:12px">Only show rows where…</label><div data-conditions></div>'
            .'<button type="button" class="btn-ghost btn-sm" data-add-condition>'.Icons::get('plus', 14).' Add a condition</button>'
            .'<div class="muted" style="margin:10px 0 4px">Tip: add a condition on the customer\'s own id using the <i>identity</i> option so every customer only ever sees their own rows.</div>'
            .'<pre class="mono" data-preview style="white-space:pre-wrap;padding:10px 12px;border-radius:8px;margin:8px 0">-- pick a table and at least one column</pre>'
            .'<button type="button" class="btn2 btn-sm" data-apply disabled>'.Icons::get('check', 14).' Use this query</button></div>'
            .Layout::tryItCard($this->url('/tools/try'))
            .'<details style="margin-top:14px"'.($ed ? ' open' : '').'><summary class="muted" style="cursor:pointer">Advanced: the query the AI will run (editable)</summary>'
            .'<label>SQL - SELECT only. <code>:param</code> for values the AI asks for, <code>:_key</code> for identity values</label>'
            .'<textarea name="sql" required placeholder="SELECT reference, status, total FROM orders WHERE reference = :reference AND user_id = :_user_id">'.$e($ed['sql'] ?? '').'</textarea>'
            .'<label>Columns the AI may see</label><input type="text" name="columns" required placeholder="reference, status, total" value="'.$e($ed['columns_csv'] ?? '').'"></details>'
            .'<div style="margin-top:16px"><button type="submit">'.Icons::get('check', 15).' '.($ed ? 'Validate &amp; save changes' : 'Validate &amp; save tool').'</button></div></form></div>'
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
        $e = fn ($v) => Html::e((string) $v);
        $editing = null;
        if (($slug = (string) ($_GET['edit'] ?? '')) !== '') {
            $row = $this->query('SELECT * FROM banimark_providers WHERE slug = ?', [$slug])[0] ?? null;
            $editing = $row ? $row + ['has_key' => trim((string) $row['api_key']) !== ''] : null; // the key never reaches the form
        }
        $rows = '';
        foreach ($this->query('SELECT * FROM banimark_providers ORDER BY enabled DESC, id', []) as $r) {
            $rows .= '<tr'.($r['enabled'] ? '' : ' style="opacity:.6"').'><td><div class="row">'.Icons::get('providers', 15).'<b>'.$e($r['slug']).'</b>'
                .(trim((string) $r['api_key']) === '' ? '<span class="pill closed" title="No API key yet">NO KEY</span>' : '').'</div></td>'
                .'<td class="muted">'.$e($r['driver']).'</td><td><code>'.$e($r['model']).'</code></td>'
                .'<td class="muted">'.$e($r['base_url'] ?: '—').'</td><td style="font-variant-numeric:tabular-nums">'.$e($r['temperature']).'</td>'
                .'<td>'.($r['enabled'] ? '<span class="pill good">ANSWERING</span>'
                    : '<form method="post" action="'.$e($this->url('/providers/activate')).'">'.$this->csrfField().'<input type="hidden" name="slug" value="'.$e($r['slug']).'"><button class="btn2 btn-sm">Use this</button></form>').'</td>'
                .'<td class="row" style="gap:4px"><a class="btn-ghost btn-sm" href="'.$e($this->url('/providers').'?edit='.rawurlencode($r['slug'])).'#edit">Edit</a>'
                .'<form method="post" action="'.$e($this->url('/providers/delete')).'">'.$this->csrfField().'<input type="hidden" name="slug" value="'.$e($r['slug']).'"><button class="btn-ghost btn-icon" data-confirm="Remove this provider?" title="Remove">'.Icons::get('trash', 15).'</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">'.Chart::empty('No providers yet', 'The chat cannot answer until you add one.').'</td></tr>';
        }
        $sel = fn (string $d) => ($editing['driver'] ?? 'gemini') === $d ? ' selected' : '';
        return Html::page('AI providers', $flash.'<div class="bm-card pad0"><div class="t-wrap"><table><tr><th>Provider</th><th>Driver</th><th>Model</th><th>Base URL</th><th>Temp</th><th>Status</th><th></th></tr>'.$rows.'</table></div></div>'
            .'<div class="bm-card" id="edit"><div class="bm-sec-h"><div><h2>'.($editing ? 'Edit provider: '.$e($editing['slug']) : 'Add a provider').'</h2>'
            .'<div class="muted">Keys are stored server-side and never shown again. '.($editing ? 'Leave the key blank to keep the stored one.' : 'Only one provider answers the chat at a time - turning this one on turns the others off.').'</div></div>'
            .($editing ? '<a class="btn-ghost btn-sm" href="'.$e($this->url('/providers')).'">Cancel - add a new one instead</a>' : '').'</div>'
            .'<form method="post" action="'.$e($this->url('/providers')).'" data-provider-form>'.$this->csrfField()
            .'<div class="grid2"><div><label>Slug <span class="muted">(its name in this list)</span></label><input type="text" name="slug" required placeholder="gemini" value="'.$e($editing['slug'] ?? '').'"'.($editing ? ' readonly' : '').'></div>'
            .'<div><label>Driver</label><select name="driver"><option value="gemini"'.$sel('gemini').'>Google Gemini</option><option value="anthropic"'.$sel('anthropic').'>Anthropic Claude</option><option value="openai-compat"'.$sel('openai-compat').'>OpenAI, DeepSeek, Groq, Mistral, OpenRouter, local… (OpenAI-compatible)</option></select></div>'
            .'<div><label>Model</label><input type="text" name="model" required placeholder="gemini-2.5-flash" value="'.$e($editing['model'] ?? '').'"></div>'
            .Layout::providerServiceBlock((string) ($editing['driver'] ?? 'gemini'), $editing['base_url'] ?? '', $editing['model'] ?? '')
            .'<div><label>API key'.($editing ? ' <span class="muted">('.($editing['has_key'] ? 'a key is stored - blank keeps it' : 'none stored yet').')</span>' : '')
                .' <a class="muted" data-key-link href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener" style="text-decoration:underline;margin-left:6px">Where do I get one?</a></label><input type="password" name="api_key" autocomplete="new-password" placeholder="'.($editing && $editing['has_key'] ? '•••••••• (unchanged)' : 'paste your API key').'"></div>'
            .'<div><label>Temperature</label><input type="number" name="temperature" step="0.05" value="'.$e($editing['temperature'] ?? '0.4').'"></div></div>'
            .'<div class="row" style="margin-top:14px;gap:20px"><label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="enabled" value="1"'.(($editing ? !empty($editing['enabled']) : true) ? ' checked' : '').'> This provider answers the chat <span class="muted">(switches the others off)</span></label></div>'
            .'<div style="margin-top:16px"><button type="submit">'.($editing ? 'Save changes' : 'Save provider').'</button></div></form></div>', $this->nav('/providers'), 'Bring your own key - it never leaves your server');
    }

    private function widget(string $flash): string
    {
        $s = $this->settings;
        $widgetUrl = Html::e($this->base.'/widget.js');
        return Html::page('Widget', $flash.'<div class="bm-card"><h2>Chat widget</h2>'
            .'<form method="post" action="'.Html::e($this->url('/widget')).'">'.$this->csrfField()
            .'<div class="grid2"><div><label>Accent color</label><input type="text" name="color" value="'.Html::e($s->get('color', '#6F04D9')).'"></div>'
            .'<div><label>Position</label><select name="position"><option value="right"'.($s->get('position') === 'right' ? ' selected' : '').'>right</option><option value="left"'.($s->get('position') === 'left' ? ' selected' : '').'>left</option></select></div>'
            .'<div><label>Theme</label><select name="theme"><option value="auto"'.($s->get('theme', 'auto') === 'auto' ? ' selected' : '').'>Auto - follow the visitor\'s device</option><option value="light"'.($s->get('theme') === 'light' ? ' selected' : '').'>Always light</option><option value="dark"'.($s->get('theme') === 'dark' ? ' selected' : '').'>Always dark</option></select><div class="hint">Applies to the website widget, the shareable chat link and the Flutter SDK.</div></div>'
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
            .'</div>'
            .'<div class="bm-card"><h2>Share as a link</h2><div class="muted">The same chat as a full page - for email signatures, QR codes, SMS, or anywhere the widget cannot be embedded. Guests are asked who they are according to your <i>Ask guests</i> setting above.</div>'
            .'<textarea readonly rows="1" data-select-all style="margin-top:10px">'.Html::e(Master::siteUrlFromServer($_SERVER).$this->base.'/chat-page').'</textarea>'
            .'<div class="hint">Signed-in users: append <code>?t=</code> and a <code>VisitorToken</code> minted server-side (24 h) so their lookups are scoped. Never put a long-lived token in an email.</div></div>'
            .$this->flutterCard($s->get('title', 'Support')), $this->nav('/widget'));
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
        $e = fn ($v) => Html::e((string) $v);
        $csrf = $this->csrfField();
        $P = \Banimark\Auth\Permissions::class;
        $permBoxes = function (array $have) use ($e, $P): string {
            $out = '<div class="bm-perms">';
            foreach ($P::ALL as $key => $label) {
                $out .= '<label><input type="checkbox" name="perms[]" value="'.$e($key).'"'.(in_array($key, $have, true) ? ' checked' : '').'> <b>'.$e($key).'</b> <span class="muted">'.$e($label).'</span></label>';
            }
            return $out.'</div>';
        };
        $presetSelect = function (string $current, string $target) use ($e, $P): string {
            $out = '<select name="preset" data-preset-for="'.$e($target).'">';
            foreach ($P::PRESETS as $k => $preset) {
                $out .= '<option value="'.$k.'"'.($current === $k ? ' selected' : '').'>'.$e($preset['label']).'</option>';
            }
            return $out.'<option value="custom"'.($current === 'custom' ? ' selected' : '').'>Custom - tick below</option></select>';
        };
        $meId = (int) $this->auth->id();
        $rows = '';
        foreach ($this->agents->all() as $a) {
            $perms = $P::of($a); $preset = $P::presetOf($perms); $pending = ($a['status'] ?? 'active') === 'pending'; $id = (int) $a['id'];
            $rows .= '<tr><td><div class="row"><span class="avatar">'.$e(strtoupper(substr($a['name'], 0, 1))).'</span><b>'.$e($a['name']).'</b>'.($id === $meId ? '<span class="muted">(you)</span>' : '').'</div></td>'
                .'<td class="muted">'.$e($a['email']).'</td>'
                .'<td><span class="pill '.($a['role'] === 'owner' ? 'ai' : 'agent').'">'.strtoupper($e($a['role'])).'</span></td>'
                .'<td>'.($a['role'] === 'owner' ? '<span class="muted">everything</span>' : '<span class="pill closed">'.$e(strtoupper($P::PRESETS[$preset]['label'] ?? 'custom')).'</span> <button type="button" class="btn-ghost btn-sm" data-toggle="#access-'.$id.'">Edit</button>').'</td>'
                .'<td>'.($pending ? '<span class="pill expired" title="Invited '.$e($a['invited_at']).'">PENDING</span>' : '<span class="pill '.($a['enabled'] ? 'good' : 'closed').'">'.($a['enabled'] ? 'ACTIVE' : 'DISABLED').'</span>').'</td>'
                .'<td>'.(!empty($a['totp_enabled'])
                    ? '<div class="row" style="gap:6px"><span class="pill good">ON</span><form method="post" action="'.$e($this->url('/agents/2fa-reset')).'">'.$csrf.'<input type="hidden" name="id" value="'.$id.'"><button class="btn-ghost btn-sm" data-confirm="Reset 2FA for this account? They sign in with just their password until they enrol again.">Reset</button></form></div>'
                    : '<span class="pill closed">OFF</span>').'</td>'
                .'<td class="row" style="gap:4px">'
                .($pending ? '<form method="post" action="'.$e($this->url('/agents/reinvite')).'">'.$csrf.'<input type="hidden" name="id" value="'.$id.'"><button class="btn2 btn-sm" title="Send a fresh activation link">Resend invite</button></form>' : '')
                .'<form method="post" action="'.$e($this->url('/agents/delete')).'">'.$csrf.'<input type="hidden" name="id" value="'.$id.'"><button class="btn-ghost btn-icon" data-confirm="Remove this staff account?" title="Remove">'.Icons::get('trash', 15).'</button></form></td></tr>';
            if ($a['role'] !== 'owner') {
                $rows .= '<tr id="access-'.$id.'" hidden><td colspan="7" style="background:var(--surface-2)">'
                    .'<form method="post" action="'.$e($this->url('/agents/permissions')).'" class="row" style="align-items:flex-start;gap:24px;flex-wrap:wrap">'.$csrf.'<input type="hidden" name="id" value="'.$id.'">'
                    .'<div style="min-width:220px"><label>Role</label><select name="role"><option value="agent" selected>Staff</option><option value="owner">Owner - full control</option></select>'
                    .'<label style="margin-top:10px">Preset</label>'.$presetSelect($preset, '#access-'.$id).'</div>'
                    .'<div style="flex:1;min-width:260px"><label>What '.$e($a['name']).' can do</label>'.$permBoxes($perms).'</div>'
                    .'<div style="align-self:flex-end"><button type="submit" class="btn-sm">Save access</button></div></form></td></tr>';
            }
        }
        return Html::page('Staff', $flash.'<div class="bm-card pad0"><div class="t-wrap"><table><tr><th>Name</th><th>Email</th><th>Role</th><th>Access</th><th>Status</th><th>2FA</th><th></th></tr>'.$rows.'</table></div></div>'
            .'<div class="bm-card"><h2>Two-factor policy</h2>'
            .'<div class="muted">When on, every staff member (owners included) must set up an authenticator app before they can use the panel. Anyone locked out can be reset above.</div>'
            .'<form method="post" action="'.$e($this->url('/agents/2fa-require')).'" class="row" style="margin-top:12px;gap:14px">'.$csrf
            .'<label style="display:flex;align-items:center;gap:10px;margin:0"><span class="switch"><input type="checkbox" name="require_2fa" value="1"'.($this->settings->get('require_2fa', '0') === '1' ? ' checked' : '').'><span class="sl"></span></span> Require 2FA for all staff</label>'
            .'<button type="submit" class="btn2 btn-sm">Save policy</button>'
            .'<a class="btn-ghost btn-sm" href="'.$e($this->url('/security')).'">'.Icons::get('shield', 14).' My own 2FA</a></form></div>'
            .'<div class="bm-card"><h2>Invite a colleague</h2>'
            .'<div class="muted">They get an email with a link to choose their own password. The account is <b>pending</b> and cannot sign in until they do.</div>'
            .'<form method="post" action="'.$e($this->url('/agents')).'">'.$csrf
            .'<div class="grid2"><div><label>Name</label><input type="text" name="name" required></div>'
            .'<div><label>Email (their login - the invitation goes here)</label><input type="text" name="email" required></div>'
            .'<div><label>Role</label><select name="role"><option value="agent">Staff - access set below</option><option value="owner">Owner - full control</option></select></div>'
            .'<div><label>Access preset</label>'.$presetSelect('agent', '#invite-perms').'</div></div>'
            .'<div id="invite-perms" style="margin-top:12px">'.$permBoxes($P::preset('agent')).'</div>'
            .'<div style="margin-top:16px"><button type="submit">'.Icons::get('send', 15).' Send invitation</button></div></form></div>', $this->nav('/agents'), 'Invite colleagues, decide what each of them can do');
    }

    /** Email the activation link; when mail is not set up, hand the owner the link to share. */
    private function sendInvite(array $agent, string $token): string
    {
        $url = Master::siteUrlFromServer($_SERVER).$this->url('/activate/'.$token);
        [$subject, $body] = \Banimark\Notify\Invite::message((string) $agent['name'], $this->auth->name(), (string) $this->settings->get('title', 'Support'), $url);
        $sent = false;
        try {
            $sent = MailerFactory::make($this->settings->all())->send([(string) $agent['email']], $subject, $body);
        } catch (\Throwable $e) {
        }
        // the owner always gets the link too: on many hosts mail() "succeeds" into
        // the void, and an owner can paste a link into chat far faster than debugging SMTP
        $link = '<br>You can also share this link with them directly (works for 7 days):<br><code style="user-select:all">'.Html::e($url).'</code>';
        return $sent
            ? '<div class="flash-ok">Invitation emailed to '.Html::e($agent['email']).'. The account stays pending until they set a password.'.$link.'</div>'
            : '<div class="flash-err">Invitation created for '.Html::e($agent['email']).', but the email could not be sent (check Notifications → Email).'.$link.'</div>';
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
        $e = fn ($v) => Html::e((string) $v);
        $support = (string) $this->settings->get('support_email', '');
        $supportUrl = (string) $this->settings->get('support_url', '');
        if (!$this->auth->isOwner()) {
            // staff never touch licensing; while locked they can only sign out
            $lock = $this->auth->lockReason();
            return Html::page('Licence', '<div class="bm-card"><div class="empty"><b>'
                .($lock ? 'This desk is locked' : 'Owners only').'</b><div>'
                .($lock ? 'Ask the account owner to check the licence.' : 'Only an owner can manage the licence.')
                .($support !== '' ? '<br>Need help? <a href="mailto:'.$e($support).'">'.$e($support).'</a>' : '')
                .'</div></div></div>', $this->nav('/license'));
        }
        $key = (string) $this->settings->get('license_key', '');
        $status = (string) $this->settings->get('license_status', '');
        $last = (int) $this->settings->get('license_last_ping', '0');
        $lock = $this->auth->lockReason();
        $verdict = Master::verify($key, (string) $this->settings->get('license_token', ''), null, (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $details = json_decode((string) $this->settings->get('license_details', ''), true) ?: [];
        $isTrial = ($details['plan'] ?? $verdict['plan'] ?? '') === 'trial';
        $active = $status === 'active' && $lock === null;
        $expiresAt = (string) ($details['expires_at'] ?? $verdict['expires_at'] ?? '');
        $daysLeft = $expiresAt !== '' ? (int) ceil((strtotime($expiresAt.' 23:59:59') - time()) / 86400) : null;
        $modules = $verdict['modules'] ?: ($details['modules'] ?? []);
        $masked = $key !== '' ? preg_replace('/^(BM-[A-Z0-9]{4})-[A-Z0-9-]+-([A-Z0-9]{4})$/', '$1-••••-••••-$2', $key) : '';
        $checkInterval = Master::intervalFor($key, (string) $this->settings->get('license_token', ''));
        $csrf = $this->csrfField();
        $banner = $lock !== null ? '<div class="flash-err">'.Icons::get('shield', 16).'<span><b>Admin locked.</b> '.$e($lock['message'])
            .($supportUrl !== '' ? ' <a href="'.$e($supportUrl).'" target="_blank" rel="noopener">Buy or renew a licence</a>.' : '')
            .($support !== '' ? ' Need help? <a href="mailto:'.$e($support).'">'.$e($support).'</a>' : '').'</span></div>' : '';
        $widgetNote = '<div class="divider"></div><div class="row" style="align-items:flex-start;gap:9px">'.Icons::get('widget', 16).'<div class="muted">Your chat widget keeps working no matter what your licence says. Only this admin panel is gated.</div></div>';

        if ($active) {
            $pills = '';
            foreach ($modules as $m) { $pills .= '<span class="pill active">'.$e(strtoupper(str_replace('-', ' ', $m))).'</span> '; }
            $trialBlock = '';
            if ($isTrial && $daysLeft !== null) {
                $issued = strtotime((string) ($details['issued_at'] ?? '')) ?: time();
                $total = max(1, (int) ceil((strtotime($expiresAt.' 23:59:59') - $issued) / 86400));
                $pct = min(100, max(4, (int) round(100 * max(0, $daysLeft) / $total)));
                $trialBlock = '<div style="margin:14px 0 6px"><div class="row" style="justify-content:space-between"><b>'.max(0, $daysLeft).' day'.($daysLeft === 1 ? '' : 's').' left</b><span class="muted">ends '.date('j M Y', strtotime($expiresAt)).'</span></div>'
                    .'<div class="hbar" style="margin-top:6px"><span class="fill" style="width:'.$pct.'%;display:block"></span></div></div>'
                    .'<div class="muted">When the trial ends the admin panel locks until you enter a purchased key. Your chat widget keeps working.</div>'
                    .($supportUrl !== '' ? '<div style="margin-top:12px"><a class="btn" href="'.$e($supportUrl).'" target="_blank" rel="noopener">'.Icons::get('key', 15).' Buy a licence</a></div>' : '');
            }
            $left = '<div class="bm-card"><div class="bm-sec-h"><div class="row" style="gap:10px"><span class="avatar">'.Icons::get('license', 16).'</span><div>'
                .'<h2 style="margin:0">'.($isTrial ? 'Free trial' : $e(ucfirst((string) ($details['plan'] ?? 'Licence')))).' <span class="pill active">ACTIVE</span></h2><div class="muted">'.$e($details['customer'] ?? '').'</div></div></div></div>'
                .$trialBlock
                .'<dl class="bm-dl" style="margin-top:14px"><dt>Key</dt><dd class="mono">'.$e($masked).'</dd>'
                .'<dt>Site</dt><dd>'.$e($details['domain'] ?? ($_SERVER['HTTP_HOST'] ?? '')).'</dd>'
                .'<dt>Modules</dt><dd>'.$pills.'</dd>'
                .'<dt>Issued</dt><dd>'.(!empty($details['issued_at']) ? date('j M Y', strtotime($details['issued_at'])) : '—').'</dd>'
                .'<dt>Expires</dt><dd>'.($expiresAt !== '' ? date('j M Y', strtotime($expiresAt)).($daysLeft !== null ? ' · '.max(0, $daysLeft).' days' : '') : 'Never - renewals keep updates flowing').'</dd>'
                .'<dt>Last verified</dt><dd>'.($last > 0 ? date('j M Y, H:i', $last) : '—').' <span class="muted">· re-checked '
                    .($checkInterval >= 86400 ? 'every '.round($checkInterval / 86400).' day'.($checkInterval >= 172800 ? 's' : '')
                        : ($checkInterval >= 3600 ? 'every '.round($checkInterval / 3600).' hour'.($checkInterval >= 7200 ? 's' : '') : 'every '.round($checkInterval / 60).' minutes')).'</span></dd>'
                .($support !== '' ? '<dt>Support</dt><dd><a href="mailto:'.$e($support).'">'.$e($support).'</a></dd>' : '').'</dl>'
                .'<form method="post" action="'.$e($this->url('/license/recheck')).'" style="margin-top:12px">'.$csrf.'<button type="submit" class="btn2 btn-sm">Re-check with HQ now</button></form></div>';
            $right = '<div class="bm-card">'.($isTrial
                ? '<h2>Have a licence key?</h2><div class="muted">Enter your purchased key to replace the trial. Everything you have set up stays.</div>'
                    .'<form method="post" action="'.$e($this->url('/license')).'" style="margin-top:10px">'.$csrf.'<label>License key</label><input type="text" name="license_key" value="" placeholder="BM-XXXX-XXXX-XXXX-XXXX" class="mono">'
                    .'<div style="margin-top:14px"><button type="submit">'.Icons::get('check', 15).' Activate key</button></div></form>'
                : '<h2>Your key is locked</h2><div class="muted">An active licence is bound to this site, so the key cannot be changed here - that is what stops a key walking to another install. It becomes editable if the licence expires or is revoked. Moving servers? '.($support !== '' ? 'Email '.$e($support) : 'Contact support').' and we release it.</div>')
                .$widgetNote.'</div>';
            return Html::page('License', $flash.$banner.'<div class="bm-grid c2">'.$left.$right.'</div>', $this->nav('/license'), 'Your Banimark licence');
        }

        $trialCard = $key === '' ? '<div class="bm-card"><div class="row" style="gap:10px"><span class="avatar">'.Icons::get('bolt', 16).'</span><div><h2 style="margin:0">Start your free trial</h2><div class="muted">Full access, no card. Your vendor sets the length.</div></div></div>'
            .'<p style="margin:12px 0">One trial per site. When it ends, the panel locks until you enter a purchased key - the chat widget keeps working throughout.</p>'
            .'<form method="post" action="'.$e($this->url('/license/trial')).'">'.$csrf.'<button type="submit">'.Icons::get('bolt', 15).' Start free trial</button></form></div>' : '';
        $expiredTrial = ($status === 'expired' && $isTrial) ? '<div class="flash-warn" style="margin-top:10px">'.Icons::get('escalation', 16).'<span>Your free trial ended'.($expiresAt !== '' ? ' on '.date('j M Y', strtotime($expiresAt)) : '').'. Enter a purchased key to continue.'.($supportUrl !== '' ? ' <a href="'.$e($supportUrl).'" target="_blank" rel="noopener">Buy a licence</a>.' : '').'</span></div>' : '';
        $keyCard = '<div class="bm-card"><div class="bm-sec-h"><div><h2>'.($key === '' ? 'Or enter a licence key' : 'Licence key').'</h2>'
            .'<div class="muted">Checked once a day from this panel. The check sends only your key, this site\'s URL and version numbers - never your data.</div></div><div class="spacer"></div>'
            .($status !== '' ? '<span class="pill '.($status === 'expired' ? 'expired' : 'revoked').'">'.$e(strtoupper($status)).'</span>' : '').'</div>'.$expiredTrial
            .'<form method="post" action="'.$e($this->url('/license')).'">'.$csrf.'<label>License key</label><input type="text" name="license_key" value="'.$e($key).'" placeholder="BM-XXXX-XXXX-XXXX-XXXX" class="mono">'
            .'<div style="margin-top:16px"><button type="submit">'.Icons::get('check', 15).' Save &amp; check now</button></div></form>'
            .($last > 0 ? '<div class="muted" style="margin-top:8px">Last checked '.date('d M Y, H:i', $last).'</div>' : '').$widgetNote.'</div>';
        return Html::page('License', $flash.$banner.'<div class="bm-grid c2">'.$trialCard.$keyCard.'</div>', $this->nav('/license'), 'Activate your Banimark licence');
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
