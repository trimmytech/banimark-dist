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
            if (!preg_match('#^[a-z]+\.(?:css|js|woff2)$#', $name) || !\Banimark\Ui\Assets::exists($name)) {
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
                $this->go($this->url('/login/2fa'));
                return;
            }
            if ($result === 'pending') {
                $this->fail('Your account is not activated yet. Use the link in your invitation email, or ask an owner to resend it.', fn (string $m) => $this->login($m));
                return;
            }
            if ($result) {
                $this->go($this->url());
                return;
            }
            $this->fail('Wrong email or password.', fn (string $m) => $this->login($m));
            return;
        }
        if (preg_match('#^/activate/([a-f0-9]{48})$#', $route, $m)) {
            $agent = $this->agents->findByInviteToken($m[1]);
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $agent) {
                $pw = (string) ($_POST['password'] ?? '');
                if (strlen($pw) < 8 || $pw !== (string) ($_POST['password_confirmation'] ?? '')) {
                    $this->fail('Use at least 8 characters, and type the same password twice.', fn (string $e) => Html::activate($this->url('/activate/'.$m[1]), $this->url('/login'), $agent, $e));
                    return;
                }
                $this->agents->activate((int) $agent['id'], (string) ($_POST['name'] ?? $agent['name']), $pw);
                if (self::ajaxForm()) {
                    $this->json(['ok' => true, 'message' => 'Your account is active - sign in with your new password.', 'redirect' => $this->url('/login')]);
                    return;
                }
                echo $this->login('', 'Your account is active - sign in with your new password.');
                return;
            }
            echo Html::activate($this->url('/activate/'.$m[1]), $this->url('/login'), $agent);
            return;
        }
        if ($route === '/login/2fa') {
            if (!$this->auth->pendingTotp()) {
                $this->go($this->url());
                return;
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                if ($this->auth->verifyTotp((string) ($_POST['code'] ?? ''))) {
                    $this->go($this->url());
                    return;
                }
                $this->fail('That code did not match. Codes change every 30 seconds - try the current one.', fn (string $e) => Html::totp($this->url('/login/2fa'), $this->url('/logout'), $e));
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
            $this->go($this->url());
            return;
        }

        // an existing standalone install never re-runs the installer, so this is
        // where a package update reaches the database: once per version, on a
        // staff visit. The widget/chat path never pays for it.
        \Banimark\Storage\Schema::ensureCurrent($this->pdo, Master::PACKAGE_VERSION);
        // the same for PHP's compiled code: files updated on disk while OPcache
        // (validate_timestamps=0) still runs the old ones - reset once, reload
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && \Banimark\Update\CacheRefresh::healStaleCode(
            Master::PACKAGE_VERSION,
            (int) $this->settings->get('opcache_heal_at', '0'),
            fn (int $t) => $this->settings->set('opcache_heal_at', (string) $t),
        )) {
            header('Location: '.$this->url($route === '/' ? '' : $route).(($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?'.$_SERVER['QUERY_STRING'] : ''));
            return;
        }
        $this->auth->touchActivity(); // "last seen" for the team page; throttled inside

        // CSRF on every mutation
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$this->auth->csrfOk($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            $this->fail('Your session expired. Reload the page and try again.', fn (string $e) => Html::page('Expired', '<div class="bm-card"><h2>Session expired</h2><p><a href="'.Html::e($this->url()).'">Back</a></p></div>'));
            return;
        }

        // (the daily HQ re-check runs in App::maybePhoneHome(), before we get here)
        // license lock: no valid license = no admin, pages AND actions. The
        // verdict comes from AgentAuth->lockReason() (encoded Master), not a
        // local call, so it cannot be stripped here. Widget/chat is never gated.
        // the DASHBOARD ('/' or '') stays reachable while locked; every other
        // page needs a verified licence. GET -> the licence screen; POST -> the
        // licence screen carrying the actionable error.
        $lock = $this->auth->lockReason();
        if (!in_array($route, ['/', '', '/license', '/changelog', '/logout'], true) && !str_starts_with($route, '/license/') && $lock !== null) {
            $msg = ($lock['reason'] ?? '') === 'missing'
                ? 'Start your free trial or enter your licence key on this page to enable Banimark.'
                : (string) ($lock['message'] ?? 'Enter a valid licence key to restore access.');
            $to = $this->url('/license');
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                $to .= (str_contains($to, '?') ? '&' : '?').'bm_err='.rawurlencode($msg);
            }
            $this->go($to);
            return;
        }
        // owner policy "everyone uses 2FA": an un-enrolled account can only reach the page where it enrols
        if (!in_array($route, ['/security', '/security/begin', '/security/confirm', '/license', '/changelog', '/logout'], true)
            && $this->settings->get('require_2fa', '0') === '1' && !$this->agents->totpEnabled((int) $this->auth->id())) {
            $this->go($this->url('/security'));
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
                return; // redirected (or answered JSON itself)
            }
            if (self::ajaxForm()) {
                $this->formAnswer($flash); // the message in place, no page render
                return;
            }
        }
        if (isset($_GET['bm_ok']) && trim((string) $_GET['bm_ok']) !== '') {
            $flash .= '<div class="flash-ok" data-flash>'.Html::e(mb_substr((string) $_GET['bm_ok'], 0, 200)).'</div>';
        }
        if (isset($_GET['bm_err']) && trim((string) $_GET['bm_err']) !== '') {
            $flash .= '<div class="flash-err" data-flash>'.Html::e(mb_substr((string) $_GET['bm_err'], 0, 200)).'</div>';
        }
        $flash = \Banimark\Licensing\HqNotice::html($this->settings->all(), (string) ($_SERVER['HTTP_HOST'] ?? ''))
            .\Banimark\Update\Notice::html($this->settings->all(), $this->auth->isOwner(),
                $this->url('/changelog'), $route === '/changelog')
            .$flash;

        if ($route === '/widget/try') {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo \Banimark\Ui\Pages::widgetTryPage($this->base.'/widget.js', $this->url('/widget'));
            return;
        }
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
                $tables = (new \Banimark\Tools\SchemaInspector($this->dataPdo()))->all();
            } catch (\Throwable $e) {
                echo json_encode(['tables' => [], 'error' => 'Your data connection is not working: '.$e->getMessage()]);
                return;
            }
            echo json_encode(['tables' => $tables]);
            return;
        }

        echo match (true) {
            $route === '/' || $route === '', $route === '/insights' => $this->dashboard($flash),
            $route === '/inbox' => $this->inbox($flash),
            str_starts_with($route, '/conversation/') => $this->conversation(substr($route, 14), $flash),
            str_starts_with($route, '/tools') => $this->tools($flash),
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
            str_starts_with($route, '/escalation') => $this->escalationPage($flash),
            $route === '/widget' => $this->widget($flash),
            str_starts_with($route, '/license') => $this->licensePage($flash),
            $route === '/changelog' => $this->changelogPage($flash),
            default => Html::page('Not found', '<div class="bm-card"><h2>Not found</h2></div>', $this->nav()),
        };
    }

    /** @return string|null flash html, or null when a redirect was sent */
    /* ---- answering a button posted by panel.js ----
     * The page prints the message at the top and as a toast; on success it
     * goes where we say (or reloads), on an error the owner keeps what they
     * typed. Every branch keeps its plain redirect / flash for a browser
     * without JavaScript - go() and formAnswer() translate, they never decide. */

    /** True when panel.js posted the form (X-Banimark-Form: 1). */
    private static function ajaxForm(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_SERVER['HTTP_X_BANIMARK_FORM'] ?? '') === '1';
    }

    private function json(array $answer, int $status = 200): void
    {
        if ($status !== 200) {
            http_response_code($status);
        }
        header('Content-Type: application/json');
        echo json_encode($answer);
    }

    /** Redirect - as a Location header, or as the JSON answer when panel.js asked. Returns null so a POST branch can `return $this->go(…)`. */
    private function go(string $to): ?string
    {
        if (!self::ajaxForm()) {
            header('Location: '.$to);
            return null;
        }
        $a = self::redirectAnswer($to, (string) ($_SERVER['HTTP_X_BANIMARK_PAGE'] ?? ''));
        $this->json($a, $a['ok'] ? 200 : 422);
        return null;
    }

    /**
     * What a redirect means to the page: its ?bm_ok / ?bm_err is the message,
     * and an error back to the SAME page is answered in place (no redirect) so
     * the typed form survives. Static so a test can pin every case.
     */
    public static function redirectAnswer(string $to, string $page): array
    {
        $q = [];
        parse_str((string) parse_url($to, PHP_URL_QUERY), $q);
        $ok = !isset($q['bm_err']) || trim((string) $q['bm_err']) === '';
        $message = mb_substr(trim((string) ($ok ? ($q['bm_ok'] ?? '') : $q['bm_err'])), 0, 200);
        $bare = preg_replace('/[?&]bm_(?:ok|err)=[^&#]*/', '', $to) ?? $to;
        $bare = preg_replace('/\?&/', '?', $bare) ?? $bare;
        $norm = static fn (string $u): string => rtrim(rtrim((string) strtok($u, '#'), '/'), '?');
        $stay = !$ok && $page !== '' && $norm($bare) === $norm($page);
        return ['ok' => $ok, 'message' => $message, 'redirect' => $stay ? null : $to];
    }

    /** A flash a POST branch rendered in place (`<div class="flash-ok">…`), as the JSON answer. */
    private function formAnswer(string $flash): void
    {
        $ok = !str_contains($flash, 'flash-err');
        $text = trim(html_entity_decode(strip_tags($flash), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->json(['ok' => $ok, 'message' => $text, 'redirect' => null], $ok ? 200 : 422);
    }

    /** An auth-page error: the page with the message for a browser, JSON for panel.js. */
    private function fail(string $message, callable $page): void
    {
        if (self::ajaxForm()) {
            $this->json(['ok' => false, 'message' => $message, 'redirect' => null], 422);
            return;
        }
        echo $page($message);
    }

    private function handlePost(string $route): ?string
    {
        $p = $_POST;

        /* ---- one-click updates (owner only, same as the page) ---- */
        if (in_array($route, ['/changelog/update', '/changelog/update/fetch', '/changelog/update/apply',
                              '/changelog/database', '/changelog/rollback', '/changelog/check', '/changelog/switch'], true)) {
            if (!$this->auth->isOwner()) {
                return '<div class="flash-err">Only an owner can update Banimark.</div>';
            }
            return $this->handleUpdatePost($route, $p);
        }
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
            $this->go($this->url('/conversation/'.$m[1]));
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
            $this->go($this->url('/inbox').'?bm_ok='.rawurlencode('Conversation deleted.'));
            return null;
        }
        if (preg_match('#^/conversation/([a-f0-9]{32})/keep$#', $route, $m)) {
            $keep = ($p['keep'] ?? '1') === '1';
            $this->store->setKept($m[1], $keep);
            return $this->go($this->url('/conversation/'.$m[1]).'?bm_ok='.rawurlencode($keep
                ? 'Kept - this conversation will not be erased automatically.'
                : 'It will be erased automatically when its time comes.'));
        }
        if (preg_match('#^/conversation/([a-f0-9]{32})/forget$#', $route, $m)) {
            $identity = $this->store->identityOf($m[1]);
            if ($identity === '' || $identity === 'anon') {
                $this->retention()->deleteConversation($m[1]);
                $notice = 'Conversation deleted (the visitor was anonymous, so there was nothing else of theirs to find).';
            } else {
                $notice = 'Deleted '.$this->retention()->deleteVisitor($identity).' conversation(s) from that visitor.';
            }
            $this->go($this->url('/inbox').'?bm_ok='.rawurlencode($notice));
            return null;
        }
        if ($route === '/files') {
            $set = fn (string $k, string $v) => $this->settings->set($k, $v);
            $set('files_enabled', !empty($p['files_enabled']) ? '1' : '0');
            $set('files_ai_read', !empty($p['files_ai_read']) ? '1' : '0');
            $set('files_max_mb', (string) max(1, min(100, (int) ($p['files_max_mb'] ?? 10))));
            $set('files_types', trim((string) ($p['files_types'] ?? '')));
            $wantsS3 = ($p['files_driver'] ?? '') === 's3';
            if ($wantsS3) {
                $refuse = \Banimark\Licensing\Entitlements::refuseS3($this->entitlements());
                if ($refuse !== null) {
                    return '<div class="flash-err">'.Html::e($refuse).'</div>';
                }
            }
            $set('files_driver', $wantsS3 ? 's3' : 'local');
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
        if ($route === '/escalation/hours') {
            \Banimark\Desk\BusinessHours::save($p, fn (string $k, string $v) => $this->settings->set($k, $v));
            return '<div class="flash-ok">Working hours saved.</div>';
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
            $this->go($this->url('/agents'));
            return null;
        }
        if ($route === '/agents/2fa-require') {
            if ($this->auth->isOwner()) {
                $this->settings->set('require_2fa', !empty($p['require_2fa']) ? '1' : '0');
            }
            $this->go($this->url('/agents'));
            return null;
        }
        if (preg_match('#^/conversation/([a-f0-9]{32})/mode$#', $route, $m)) {
            $mode = in_array($p['mode'] ?? '', ['ai', 'agent', 'closed'], true) ? $p['mode'] : 'ai';
            $this->store->setMode($m[1], $mode);
            $this->go($this->url('/conversation/'.$m[1]));
            return null;
        }
        if ($route === '/tools/data') {
            \Banimark\Storage\DataSource::save($p, fn (string $k, string $v) => $this->settings->set($k, $v));
            return '<div class="flash-ok">Saved. Test the connection to be sure it works.</div>';
        }
        if ($route === '/tools/data/test') {
            \Banimark\Storage\DataSource::save($p, fn (string $k, string $v) => $this->settings->set($k, $v));
            $r = \Banimark\Storage\DataSource::check($this->settings->all(), $this->pdo);
            return '<div class="'.($r['ok'] ? 'flash-ok' : 'flash-err').'">'.Html::e($r['message']).'</div>';
        }
        if ($route === '/insights') {
            // customer insights: owner-only (not in the Permissions map), rate-limited, never automatic
            if (!$this->auth->isOwner()) {
                return '<div class="flash-err">Only an owner can run the analysis.</div>';
            }
            if ((new \Banimark\Http\RateLimiter($this->pdo))->hit('insights', 3600) > 10) {
                return '<div class="flash-err">That is a lot of analyses in one hour. Give it a little while - each one is a call to your AI provider.</div>';
            }
            [$driver, , $model] = \Banimark\Standalone\EngineBuilder::driver($this->pdo);
            if ($driver === null) {
                return '<div class="flash-err">Connect an AI provider first - the analysis uses the same key your chat does.</div>';
            }
            $days = (int) ($p['days'] ?? 30);
            $days = isset(\Banimark\Insights\ConversationInsights::PERIODS[$days]) ? $days : 30;
            @set_time_limit(180);
            $out = (new \Banimark\Insights\ConversationInsights($this->pdo))->analyse($driver, $model, $days);
            if (!$out['ok']) {
                return '<div class="flash-err">'.Html::e($out['error']).'</div>';
            }
            $this->settings->set(\Banimark\Insights\ConversationInsights::SETTING, (string) json_encode($out['report']));
            return '<div class="flash-ok">Analysis ready - '.(int) $out['report']['conversations_used'].' conversations read.</div>';
        }
        if ($route === '/tools/assist') {
            header('Content-Type: application/json');
            $limiter = new \Banimark\Http\RateLimiter($this->pdo);
            if ($limiter->hit('tool-assist', 3600) > 60) {
                http_response_code(429);
                echo json_encode(['ok' => false, 'error' => 'That is a lot of drafting in one hour. Give it a few minutes - every question costs a call to your AI provider.']);
                return null;
            }
            [$driver, $slug] = \Banimark\Standalone\EngineBuilder::driver($this->pdo);
            if ($driver === null) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Connect an AI provider first - the assistant uses the same key your chat does.']);
                return null;
            }
            try {
                $schema = (new \Banimark\Tools\SchemaInspector($this->dataPdo()))->all();
            } catch (\Throwable $e) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Your data connection is not working: '.$e->getMessage()]);
                return null;
            }
            $known = [];
            $existing = [];
            foreach ($this->query('SELECT name, context FROM banimark_tools', []) as $row) {
                $existing[] = (string) $row['name'];
                foreach ((array) (json_decode((string) $row['context'], true) ?: []) as $k) {
                    $known[(string) $k] = true;
                }
            }
            $turns = [];
            foreach (array_slice((array) ($p['turns'] ?? []), -12) as $t) {
                $text = trim((string) ($t['text'] ?? ''));
                if ($text !== '') {
                    $turns[] = ['role' => ($t['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'text' => mb_substr($text, 0, 2000)];
                }
            }
            $out = (new \Banimark\Tools\ToolDraftsman($driver))->draft($turns, $schema, array_keys($known), $existing,
                \Banimark\Storage\Dialect::of($this->dataPdo()));
            if (!$out['ok']) {
                error_log('Banimark tool assistant: '.$out['error']);
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => \Banimark\Tools\ToolDraftsman::explain($out['error'], $slug)]);
                return null;
            }
            echo json_encode(['ok' => true, 'reply' => $out['reply'], 'tool' => $out['tool']]);
            return null;
        }
        if ($route === '/tools/try') {
            header('Content-Type: application/json');
            $context = array_filter((array) ($p['context_values'] ?? []), fn ($v) => $v !== '' && $v !== null);
            try {
                $runner = \Banimark\Storage\DataSource::runner($this->dataPdo());
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'error' => 'Your data connection is not working: '.$e->getMessage(), 'diagnostic' => '', 'rows' => [], 'count' => 0, 'needs' => [], 'sql' => '']);
                return null;
            }
            // "Try it" runs what is ON SCREEN - including the secret already
            // stored, since the box is blank until someone types a new one
            echo json_encode(\Banimark\Tools\ToolTester::run(self::toolDefinitionFromForm($p, $this->storedAuth($p)), (array) ($p['args'] ?? []), $context, $runner));
            return null;
        }
        if ($route === '/tools') {
            return $this->saveTool($p);
        }
        if ($route === '/tools/delete') {
            $this->exec('DELETE FROM banimark_tools WHERE name = ?', [(string) ($p['name'] ?? '')]);
            $this->go($this->url('/tools'));
            return null;
        }
        if (str_starts_with($route, '/rules')) {
            $flash = $this->handleRulesPost($route, $p);
            if ($flash !== '') {
                return $flash;
            }
            $this->go($this->url('/rules'));
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
            $this->go($this->url('/providers'));
            return null;
        }
        if ($route === '/agents') {
            if (!$this->auth->isOwner()) {
                return '<div class="flash-err">Only an owner can manage staff.</div>';
            }
            $perms = ($p['preset'] ?? 'agent') === 'custom' ? (array) ($p['perms'] ?? []) : \Banimark\Auth\Permissions::preset((string) ($p['preset'] ?? 'agent'));
            // the plan's seat count; nobody already invited is touched
            $refuse = \Banimark\Licensing\Entitlements::refuseStaff($this->entitlements(), count($this->agents->all()));
            if ($refuse !== null) {
                return '<div class="flash-err">'.Html::e($refuse).'</div>';
            }
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
            $this->go($this->url('/agents'));
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
            $this->settings->set('poll_idle_seconds', (string) max(10, min(600, (int) ($p['poll_idle_seconds'] ?? 30) ?: 30)));
            $this->settings->set('launcher_reappear_minutes', (string) max(0, min(1440, ($p['launcher_reappear_minutes'] ?? '') === '' ? 10 : (int) $p['launcher_reappear_minutes'])));
            $this->settings->set('guest_mode', in_array($p['guest_mode'] ?? '', ['off', 'optional', 'required'], true) ? $p['guest_mode'] : 'off');
            $this->settings->set('offline_note', mb_substr(trim((string) ($p['offline_note'] ?? '')), 0, 200));
            // whitelabel is a plan feature; when it is not covered the stored
            // value is LEFT ALONE, so saving a colour cannot put our name back
            // on a site that had already removed it
            if (\Banimark\Licensing\Entitlements::locked($this->entitlements(), 'whitelabel') === null) {
                $this->settings->set('hide_brand', !empty($p['hide_brand']) ? '1' : '0');
            }
            // the first-run screen posts from the same form
            \Banimark\Ui\Pages::saveFirstRun($p, fn (string $k, string $v) => $this->settings->set($k, $v));
            $f = $_FILES['logo_file'] ?? null;
            $upload = (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name']))
                ? (string) file_get_contents((string) $f['tmp_name'], false, null, 0, \Banimark\Http\WidgetLogo::MAX_BYTES + 1) : null;
            $logoProblem = \Banimark\Ui\Pages::saveLook($p, fn (string $k, string $v) => $this->settings->set($k, $v), $upload);
            $this->settings->set('theme', in_array($p['theme'] ?? '', ['auto', 'light', 'dark'], true) ? $p['theme'] : 'auto');
            return $logoProblem !== null
                ? '<div class="flash-err">Everything else was saved, but not the logo: '.Html::e($logoProblem).'</div>'
                : '<div class="flash-ok">Widget saved.</div>';
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
                    $this->go($this->url()); // straight into the desk
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
                $this->go($this->url()); // activated: straight into the module dashboard
                return null;
            }
            return '<div class="flash-err">License checked - status: <b>'.Html::e($result['license']).'</b>'
                .($result['message'] !== '' ? ' · '.Html::e($result['message']) : '').'</div>';
        }
        return '';
    }

    /** The tool definition as typed into the form - shared by save and "Try it". */
    /** @param array $storedAuth the auth already saved for this tool, so a blank secret box keeps it */
    private static function toolDefinitionFromForm(array $p, array $storedAuth = []): array
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
            'kind' => ($p['kind'] ?? '') === 'http' ? 'http' : 'sql',
            'config' => [
                'method' => strtoupper((string) ($p['http_method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET',
                'url' => trim((string) ($p['http_url'] ?? '')),
                'headers' => \Banimark\Tools\ToolFactory::parseHeaders((string) ($p['http_headers'] ?? '')),
                'body' => trim((string) ($p['http_body'] ?? '')),
                'path' => trim((string) ($p['http_path'] ?? '')),
                'fields' => array_values(array_filter(array_map('trim', explode(',', (string) ($p['http_fields'] ?? ''))))),
                // a blank secret box means "keep what is stored"
                'auth' => \Banimark\Tools\HttpAuth::fromForm($p, $storedAuth),
            ],
        ];
    }

    /** The auth block already saved for the tool being edited, if any. */
    private function storedAuth(array $p): array
    {
        $name = trim((string) ($p['original_name'] ?? $p['name'] ?? ''));
        if ($name === '') {
            return [];
        }
        $row = $this->query('SELECT config FROM banimark_tools WHERE name = ?', [$name])[0] ?? null;
        return (array) ((json_decode((string) ($row['config'] ?? ''), true) ?: [])['auth'] ?? []);
    }

    private function saveTool(array $p): ?string
    {
        $definition = self::toolDefinitionFromForm($p, $this->storedAuth($p));
        try {
            \Banimark\Tools\ToolFactory::make($definition, fn () => [], fn () => ['status' => 200, 'body' => '[]', 'error' => '']);
        } catch (\Throwable $e) {
            return '<div class="flash-err">'.Html::e($e->getMessage()).'</div>';
        }
        // editing: original_name is the row to replace, name may be a rename
        $original = trim((string) ($p['original_name'] ?? ''));
        if ($original === '') {
            $count = (int) ($this->query('SELECT COUNT(*) AS c FROM banimark_tools', [])[0]['c'] ?? 0);
            $refuse = \Banimark\Licensing\Entitlements::refuseTool($this->entitlements(), $count);
            if ($refuse !== null) {
                return '<div class="flash-err">'.Html::e($refuse).'</div>';
            }
        }
        $target = $original !== '' && $this->query('SELECT 1 FROM banimark_tools WHERE name = ?', [$original]) !== [] ? $original : $definition['name'];
        if ($target !== $definition['name'] && $this->query('SELECT 1 FROM banimark_tools WHERE name = ?', [$definition['name']]) !== []) {
            return '<div class="flash-err">A tool called "'.Html::e($definition['name']).'" already exists.</div>';
        }
        $this->exec('DELETE FROM banimark_tools WHERE name = ?', [$target]);
        $sqlCol = \Banimark\Storage\Dialect::quote(\Banimark\Storage\Dialect::of($this->pdo), 'sql');
        $this->exec("INSERT INTO banimark_tools (name, description, parameters, {$sqlCol}, columns, context, max_rows, kind, config, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $definition['name'], $definition['description'], json_encode($definition['parameters']),
            $definition['sql'], json_encode($definition['columns']), json_encode($definition['context']),
            $definition['max_rows'], $definition['kind'], $definition['kind'] === 'http' ? json_encode($definition['config']) : null,
            !empty($p['enabled']) ? 1 : 0, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);
        $this->go($this->url('/tools'));
        return null;
    }

    private function saveProvider(array $p): string
    {
        $slug = strtolower(trim((string) ($p['slug'] ?? '')));
        $model = trim((string) ($p['model'] ?? ''));
        if (!preg_match('/^[a-z0-9\-]+$/', $slug) || $model === '') {
            return '<div class="flash-err">Slug (lowercase) and model are required.</div>';
        }
        $driver = in_array($p['driver'] ?? '', \Banimark\Ai\ProviderPresets::KNOWN_DRIVERS, true) ? $p['driver'] : 'gemini';
        $existing = $this->query('SELECT api_key, driver, model FROM banimark_providers WHERE slug = ?', [$slug])[0] ?? null;
        // only offered drivers and TESTED models - but what this provider already
        // had is kept as it was (ProviderPresets::refuse)
        if ($why = \Banimark\Ai\ProviderPresets::refuse($driver, $model, (string) ($existing['driver'] ?? ''), (string) ($existing['model'] ?? ''))) {
            return '<div class="flash-err">'.Html::e($why).'</div>';
        }
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
        $days = (int) ($_GET['days'] ?? 30);
        $insights = \Banimark\Insights\ConversationInsights::stored($this->settings->all());
        $hasProvider = \Banimark\Standalone\EngineBuilder::driver($this->pdo)[0] !== null;
        // ONE body for both runtimes: Ui\Pages::dashboard
        $body = $flash.\Banimark\Ui\Pages::dashboard((new Analytics($this->pdo))->period($days), $this->store->listConversations(6), [
            'period_url' => fn (int $d) => $this->url('/').'?days='.$d,
            'conversation_url' => fn (string $sid) => $this->url('/conversation/'.$sid),
            'inbox' => $this->url('/inbox'),
            'providers' => $this->url('/providers'),
            'tools' => $this->url('/tools'),
            'widget' => $this->url('/widget'),
            'insights' => $insights,
            'has_provider' => $hasProvider,
            'tools_count' => count($this->query('SELECT id FROM banimark_tools', [])),
            'owner' => $this->auth->isOwner(),
            'insights_html' => \Banimark\Insights\ConversationInsights::render($insights, [
                'action' => $this->url('/insights'),
                'csrf' => $this->csrfField(),
                'can_run' => $this->auth->isOwner(),
                'has_provider' => $hasProvider,
            ]),
        ]);

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
        $rows = TranscriptView::rows($this->store->transcript($sessionId), $this->attachments());
        $this->store->markStaffSeen($sessionId); // opening it clears the unread dot in the inbox
        $c = '/conversation/'.$sessionId;
        // ONE body for both runtimes: Ui\Pages::conversation
        $body = $flash.\Banimark\Ui\Pages::conversation([
            'session_id' => $sessionId,
            'mode' => $this->store->mode($sessionId),
            'rows' => $rows,
            'presence' => $this->store->presence($sessionId) ?? [],
            'quick' => QuickReplies::fromSettings($this->settings->all()),
            'files_on' => \Banimark\Files\FileStoreFactory::enabled($this->settings->all()),
            'can_delete' => $this->auth->can('inbox.delete'),
            'visitor_delete_days' => \Banimark\Storage\Retention::visitorDeleteDays($this->settings->all()),
            'csrf_field' => $this->csrfField(),
            'csrf_name' => '_csrf',
            'csrf_value' => $this->auth->csrf(),
            'urls' => [
                'inbox' => $this->url('/inbox'),
                'mode' => $this->url($c.'/mode'),
                'delete' => $this->url($c.'/delete'),
                'forget' => $this->url($c.'/forget'),
                'keep' => $this->url($c.'/keep'),
                'messages' => $this->url($c.'/messages'),
                'reply' => $this->url($c.'/reply'),
                'upload' => $this->url($c.'/upload'),
                'file' => $this->base.'/file/',
            ],
        ]);
        $actions = '<a class="btn2 btn-sm" href="'.Html::e($this->url('/inbox')).'">'.Icons::get('back', 15).' Inbox</a>';
        return Html::page('Conversation', $body, $this->nav('/inbox'), 'Replying takes over - the AI stays silent until you hand it back', $actions);
    }

    private function filesPage(string $flash): string
    {
        $all = $this->settings->all();
        $row = $this->query('SELECT COUNT(*) AS c, COALESCE(SUM(size), 0) AS s FROM banimark_attachments', [])[0] ?? ['c' => 0, 's' => 0];
        // ONE body for both runtimes: Ui\Pages::files
        return Html::page('Files', $flash.\Banimark\Ui\Pages::files($all, [
            'problem' => \Banimark\Files\FileStoreFactory::misconfigured($all),
            'default_dir' => dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__).'/banimark-files',
            'stats' => ['count' => (int) $row['c'], 'size' => (int) $row['s']],
            's3_lock' => \Banimark\Licensing\Entitlements::locked($this->entitlements(), 's3'),
            'upgrade_url' => (string) $this->settings->get('support_url', ''),
            'csrf' => $this->csrfField(),
            'test' => null,   // the standalone runtime reports the test in $flash
            'urls' => ['save' => $this->url('/files'), 'test' => $this->url('/files/test')],
        ]), $this->nav('/files'), 'Where files shared in a chat are kept');
    }

    private function dataPdo(): \PDO
    {
        return \Banimark\Storage\DataSource::pdo($this->settings->all(), $this->pdo);
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

    private function handleSecurityPost(string $route, array $p): ?string
    {
        $id = (int) $this->auth->id();
        switch ($route) {
            case '/security/begin':
                $this->agents->beginTotp($id);
                $this->go($this->url('/security'));
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
        $me = $this->agents->find((int) $this->auth->id()) ?? [];
        $enabled = (int) ($me['totp_enabled'] ?? 0) === 1;
        $pending = !$enabled ? (string) ($me['totp_secret'] ?? '') : '';
        // ONE body for both runtimes: Ui\Pages::security
        $body = $flash.\Banimark\Ui\Pages::security([
            'enabled' => $enabled,
            'pending' => $pending,
            'required' => $this->settings->get('require_2fa', '0') === '1',
            'uri' => $pending !== '' ? Totp::uri($pending, (string) ($me['email'] ?? ''), 'Banimark') : '',
            'csrf' => $this->csrfField(),
            'urls' => [
                'begin' => $this->url('/security/begin'),
                'confirm' => $this->url('/security/confirm'),
                'disable' => $this->url('/security/disable'),
                'staff' => $this->url('/agents'),
            ],
        ]);
        return Html::page('Security', $body, $this->nav('/security'), 'Two-factor authentication for your own account');
    }

    private function tools(string $flash): string
    {
        $e = fn ($v) => Html::e((string) $v);
        // how many tools the plan covers, worked out before the form is drawn
        $toolRoom = \Banimark\Licensing\Entitlements::allowance($this->entitlements(), 'tools',
            (int) ($this->query('SELECT COUNT(*) AS c FROM banimark_tools', [])[0]['c'] ?? 0));
        $upgradeUrl = (string) $this->settings->get('support_url', '');
        $ed = null; // editing an existing tool?
        if (($name = (string) ($_GET['edit'] ?? '')) !== '') {
            $row = $this->query('SELECT * FROM banimark_tools WHERE name = ?', [$name])[0] ?? null;
            if ($row) {
                $params = [];
                foreach ((array) (json_decode($row['parameters'], true) ?: []) as $pn => $spec) {
                    $params[] = ['name' => $pn, 'type' => $spec['type'] ?? 'string', 'desc' => $spec['description'] ?? '', 'required' => !empty($spec['required'])];
                }
                $config = json_decode((string) ($row['config'] ?? ''), true) ?: [];
                $ed = $row + ['params' => $params,
                    'columns_csv' => implode(', ', json_decode($row['columns'], true) ?: []),
                    'context_csv' => implode(', ', json_decode((string) $row['context'], true) ?: []),
                    'kind' => $row['kind'] ?? 'sql',
                    'http_method' => (string) ($config['method'] ?? 'GET'),
                    'http_url' => (string) ($config['url'] ?? ''),
                    'http_headers' => \Banimark\Tools\ToolFactory::headerLines((array) ($config['headers'] ?? [])),
                    'http_body' => (string) ($config['body'] ?? ''),
                    'http_path' => (string) ($config['path'] ?? ''),
                    'http_fields' => implode(', ', (array) ($config['fields'] ?? [])),
                    // everything about the auth EXCEPT the secret itself
                    'http_auth' => (string) (($config['auth'] ?? [])['type'] ?? 'none'),
                    'http_auth_header' => (string) (($config['auth'] ?? [])['name'] ?? ''),
                    'http_auth_user' => (string) (($config['auth'] ?? [])['user'] ?? ''),
                    'http_auth_issuer' => (string) (($config['auth'] ?? [])['issuer'] ?? 'banimark'),
                    'http_auth_audience' => (string) (($config['auth'] ?? [])['audience'] ?? ''),
                    'http_auth_ttl' => (int) (($config['auth'] ?? [])['ttl'] ?? \Banimark\Tools\HttpAuth::JWT_TTL),
                    'http_auth_has_secret' => \Banimark\Tools\HttpAuth::hasSecret((array) ($config['auth'] ?? []))];
            }
        }
        $rows = [];
        foreach ($this->query('SELECT * FROM banimark_tools ORDER BY id', []) as $r) {
            $rows[] = ['name' => (string) $r['name'], 'description' => (string) $r['description'], 'sql' => (string) $r['sql'],
                'params' => array_keys(json_decode((string) $r['parameters'], true) ?: []), 'max_rows' => (int) $r['max_rows'], 'enabled' => (bool) $r['enabled']];
        }
        $form = $ed !== null ? $ed + ['enabled' => !empty($ed['enabled'])] : ['enabled' => true, 'context_csv' => 'user_id', 'max_rows' => 10];
        [$assistDriver] = \Banimark\Standalone\EngineBuilder::driver($this->pdo);
        // ONE body for both runtimes: Ui\Pages::tools
        return Html::page('Tools', $flash.\Banimark\Ui\Pages::tools($rows, $form, [
            'editing' => $ed !== null,
            'csrf' => $this->csrfField(),
            'allowance' => $toolRoom,
            'upgrade_url' => $upgradeUrl,
            'assist_ready' => $assistDriver !== null,
            'data_card' => Layout::dataConnection($this->settings->all(), $this->url('/tools/data'), $this->url('/tools/data/test'),
                $this->csrfField(), 'Banimark reads the tables next to its own.'),
            'urls' => [
                'save' => $this->url('/tools'),
                'delete' => $this->url('/tools/delete'),
                'page' => $this->url('/tools'),
                'schema' => $this->url('/tools/schema'),
                'try' => $this->url('/tools/try'),
                'assist' => $this->url('/tools/assist'),
                'providers' => $this->url('/providers'),
            ],
        ]), $this->nav('/tools'), 'Let the assistant look things up in your own data - safely, read-only');
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
        // ONE body for both runtimes: Ui\Pages::rules
        return Html::page('Rules', $flash.\Banimark\Ui\Pages::rules($repo->tree(), [
            'csrf' => $this->csrfField(),
            'urls' => [
                'folder' => $this->url('/rules/folder'),
                'folder_move' => $this->url('/rules/folder/move'),
                'folder_delete' => $this->url('/rules/folder/delete'),
                'save' => $this->url('/rules'),
                'move' => $this->url('/rules/move'),
                'delete' => $this->url('/rules/delete'),
            ],
        ]), $this->nav('/rules'), 'How your assistant behaves - organised in folders, applied in order');
    }

    private function providers(string $flash): string
    {
        $editing = null;
        if (($slug = (string) ($_GET['edit'] ?? '')) !== '') {
            $row = $this->query('SELECT * FROM banimark_providers WHERE slug = ?', [$slug])[0] ?? null;
            // the key never reaches the form
            $editing = $row ? ['slug' => $row['slug'], 'driver' => $row['driver'], 'model' => $row['model'], 'base_url' => (string) $row['base_url'],
                'temperature' => $row['temperature'], 'enabled' => (bool) $row['enabled'], 'has_key' => trim((string) $row['api_key']) !== ''] : null;
        }
        $rows = [];
        foreach ($this->query('SELECT * FROM banimark_providers ORDER BY enabled DESC, id', []) as $r) {
            $rows[] = ['slug' => (string) $r['slug'], 'driver' => (string) $r['driver'], 'model' => (string) $r['model'], 'base_url' => (string) $r['base_url'],
                'temperature' => $r['temperature'], 'enabled' => (bool) $r['enabled'], 'has_key' => trim((string) $r['api_key']) !== ''];
        }
        // ONE body for both runtimes: Ui\Pages::providers
        return Html::page('AI providers', $flash.\Banimark\Ui\Pages::providers($rows, $editing, [
            'csrf' => $this->csrfField(),
            'urls' => [
                'save' => $this->url('/providers'),
                'activate' => $this->url('/providers/activate'),
                'delete' => $this->url('/providers/delete'),
                'page' => $this->url('/providers'),
            ],
        ]), $this->nav('/providers'), 'Bring your own key - it never leaves your server');
    }

    private function widget(string $flash): string
    {
        $s = $this->settings;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $entry = $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'yourapp.com').$this->base;
        // ONE body for both runtimes: Ui\Pages::widget
        return Html::page('Widget', $flash.\Banimark\Ui\Pages::widget($s->all(), [
            'csrf' => $this->csrfField(),
            'save_url' => $this->url('/widget'),
            'widget_js' => $this->base.'/widget.js',
            'chat_page_url' => Master::siteUrlFromServer($_SERVER).$this->base.'/chat-page',
            'try_url' => $this->url('/widget/try'),
            'token_snippet' => "\$token = \\Banimark\\Identity\\VisitorToken::mint(\n    ['user_id' => \$userId],\n    \$identitySecret // identity_secret from Banimark's settings\n);",
            'flutter' => $this->updates()['sdks']['flutter'] ?? null,
            'flutter_lock' => \Banimark\Licensing\Entitlements::locked($this->entitlements(), 'flutter'),
            'whitelabel_lock' => \Banimark\Licensing\Entitlements::locked($this->entitlements(), 'whitelabel'),
            'upgrade_url' => (string) $s->get('support_url', ''),
            'support_email' => (string) $s->get('support_email', ''),
            'flutter_config' => "BanimarkChat(\n  config: BanimarkConfig.standalone('".$entry."', token: userToken), // token: mint it server-side like the widget's data-token; null = guest\n  theme: BanimarkTheme.fromScheme(Theme.of(context).colorScheme)\n      .copyWith(title: '".str_replace("'", "\\'", (string) $s->get('title', 'Support'))."'),\n)",
        ]), $this->nav('/widget'), 'How the chat looks and greets on your site, in a link and in your app');
    }

    private function agentsPage(string $flash): string
    {
        if (!$this->auth->isOwner()) {
            return Html::page('Staff', '<div class="bm-card"><div class="empty"><b>Owners only</b><div>Only an owner can manage staff accounts.</div></div></div>', $this->nav('/agents'));
        }
        // ONE body for both runtimes: Ui\Pages::staff
        return Html::page('Staff', $flash.\Banimark\Ui\Pages::staff($this->agents->all(), [
            'me_id' => (int) $this->auth->id(),
            'require_2fa' => $this->settings->get('require_2fa', '0') === '1',
            'seats' => \Banimark\Licensing\Entitlements::allowance($this->entitlements(), 'staff', count($this->agents->all())),
            'upgrade_url' => (string) $this->settings->get('support_url', ''),
            'csrf' => $this->csrfField(),
            'urls' => [
                'save' => $this->url('/agents'),
                'delete' => $this->url('/agents/delete'),
                'reinvite' => $this->url('/agents/reinvite'),
                'permissions' => $this->url('/agents/permissions'),
                'totp_reset' => $this->url('/agents/2fa-reset'),
                'totp_require' => $this->url('/agents/2fa-require'),
                'security' => $this->url('/security'),
            ],
        ]), $this->nav('/agents'), 'Invite colleagues, decide what each of them can do');
    }

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
        // ONE body for both runtimes: Ui\Pages::notifications
        $body = $flash.\Banimark\Ui\Pages::notifications($this->settings->all(), [
            'hours' => $this->url('/escalation/hours'),
            'save' => $this->url('/escalation'),
            'test' => $this->url('/escalation/test'),
            'quick' => $this->url('/quick-replies'),
        ], $this->csrfField());
        return Html::page('Notifications', $body, $this->nav('/escalation'), 'Working hours, handover alerts, outgoing email and visitor follow-ups');
    }

    private function updates(): array
    {
        $cache = json_decode((string) $this->settings->get('updates_cache', ''), true);
        $cache = is_array($cache) ? $cache : null;
        if (\Banimark\Update\UpdateCheck::due($this->settings->get('updates_checked_at', '0'))) {
            try {
                $fresh = (new \Banimark\Update\UpdateCheck(\Banimark\Update\UpdateCheck::endpointFrom(
                    (string) ($this->settings->get('hq_url', '') ?: Master::DEFAULT_ENDPOINT)
                ), null, (string) $this->settings->get('license_key', '')))->fetch();
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
    /**
     * The three update buttons. Kept apart from the rest of handlePost so the
     * riskiest thing the panel can do reads as one short, auditable block.
     *
     * @return string the flash to render
     */
    /** The signed verdict for this install - what the plan actually allows. */
    private function entitlements(): array
    {
        return \Banimark\Licensing\Entitlements::verdict($this->settings->all(), (string) ($_SERVER['HTTP_HOST'] ?? ''));
    }

    /** @return string|null the flash to render, or null when the answer was JSON */
    private function handleUpdatePost(string $route, array $p): ?string
    {
        $ok = fn (string $m) => '<div class="flash-ok">'.Html::e($m).'</div>';
        $err = fn (string $m) => '<div class="flash-err">'.Html::e($m).'</div>';
        $settings = $this->settings->all();
        $key = (string) $this->settings->get('license_key', '');

        if ($route === '/changelog/database') {
            $did = \Banimark\Storage\Schema::ensureCurrent($this->pdo, Master::PACKAGE_VERSION);
            // this request runs the new code: make sure no worker keeps the old
            \Banimark\Update\CacheRefresh::opcache(\Banimark\Update\Paths::packageRoot());
            if ($did) {
                return $ok('Your database is up to date with '.Master::PACKAGE_VERSION.'.');
            }
            // ensureCurrent never throws, so false is either nothing-to-do or a
            // rights problem. Ask the DATABASE which, not a cached array.
            $stored = (string) $this->settings->get('schema_version', '');
            $wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
            if ($wantsJson) {
                header('Content-Type: application/json');
                $good = $stored === Master::PACKAGE_VERSION;
                if (!$good) { http_response_code(422); }
                $m = $good ? 'Your database is up to date with '.Master::PACKAGE_VERSION.'.'
                    : 'The database did not change - it is still on '.($stored !== '' ? $stored : 'no recorded version')
                        .'. The database user Banimark connects with probably cannot alter tables; ask your host for CREATE, ALTER and INDEX rights, then try again.';
                echo json_encode(['ok' => $good, 'message' => $m, 'error' => $good ? null : $m]);
                return null;
            }
            return $stored === Master::PACKAGE_VERSION
                ? $ok('Your database was already up to date.')
                : $err('The database did not change - it is still on '
                    .($stored !== '' ? $stored : 'no recorded version')
                    .'. The database user Banimark connects with probably cannot alter tables; ask your host for CREATE, ALTER and INDEX rights, then try again.');
        }

        if ($route === '/changelog/check') {
            // the answer is cached for six hours; nobody should have to wait out
            // a cache they cannot see after fixing a connection
            $this->settings->set('updates_checked_at', '0');
            $fresh = $this->updates();
            $good = (bool) ($fresh['ok'] ?? false);
            $msg = !$good
                ? 'Still could not reach '.((string) ($settings['hq_url'] ?? Master::DEFAULT_ENDPOINT)).'. Check the server can make outbound requests.'
                : (\Banimark\Update\UpdateCheck::isNewer($fresh['latest'] ?? null)
                    ? 'Version '.$fresh['latest'].' is available.'
                    : 'Checked - you are on the latest version.');
            if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                if (!$good) { http_response_code(422); }
                echo json_encode(['ok' => $good, 'message' => $msg, 'error' => $good ? null : $msg]);
                return null;
            }
            return $good ? $ok($msg) : $err($msg);
        }

        if ($route === '/changelog/rollback') {
            $out = (new \Banimark\Update\Installer('', ''))->rollback((string) ($p['backup'] ?? ''));
            $this->settings->set('updates_checked_at', '0');
            return $out['ok'] ? $ok($out['message']) : $err($out['message']);
        }

        /* ---- the two AJAX stages: download+verify, then swap ---- */
        if ($route === '/changelog/update/fetch' || $route === '/changelog/update/apply') {
            header('Content-Type: application/json');
            $json = function (bool $ok, string $message, array $extra = []) {
                if (!$ok) { http_response_code(422); }
                echo json_encode(['ok' => $ok, 'message' => $message, 'error' => $ok ? null : $message] + $extra);
                return null;
            };

            if ($route === '/changelog/update/fetch') {
                $st = \Banimark\Update\Status::build($this->updates(), $settings, $key, null,
                    (string) ($settings['hq_url'] ?? Master::DEFAULT_ENDPOINT));
                if (!$st['one_click'] || !$st['ready'] || $st['latest'] === null) {
                    return $json(false, $st['blocked_reason'] ?: 'This server cannot install updates automatically - see the checks on this page.');
                }
                $out = (new \Banimark\Update\Installer(
                    \Banimark\Update\Installer::endpointFrom(
                        (string) ($settings['hq_url'] ?? Master::DEFAULT_ENDPOINT), (string) $st['download_url']),
                    $key
                ))->fetch((string) $st['latest'], (array) $st['manifest']);
                if (!$out['ok']) {
                    return $json(false, $out['message']);
                }
                // the staged path stays server-side; the browser never names a directory
                $this->settings->set('update_staged', $out['staged']);
                $this->settings->set('update_staged_version', (string) $st['latest']);
                return $json(true, $out['message'], ['version' => $st['latest']]);
            }

            $staged = (string) $this->settings->get('update_staged', '');
            if ($staged === '') {
                return $json(false, 'Nothing is staged - download the update first.');
            }
            $installer = new \Banimark\Update\Installer('', '');
            $out = $installer->apply($staged, (string) $this->settings->get('update_staged_version', ''));
            $this->settings->set('update_staged', '');
            $this->settings->set('update_staged_version', '');
            if (!$out['ok']) {
                return $json(false, $out['message']);
            }
            $this->settings->set('updates_checked_at', '0');
            $installer->pruneBackups();
            return $json(true, $out['message'], ['schema_next' => true]);
        }

        $st = \Banimark\Update\Status::build($this->updates(), $settings, $key);

        // reinstall this version / leave a TEST build - only a version Status offers
        if ($route === '/changelog/switch') {
            $pick = null;
            foreach ((array) $st['alternatives'] as $alt) {
                if ($alt['version'] === (string) ($p['version'] ?? '')) {
                    $pick = $alt;
                }
            }
            if ($pick === null) {
                return $err('That release is not on offer any more. Check for updates, then try again.');
            }
            $installer = new \Banimark\Update\Installer(
                \Banimark\Update\Installer::endpointFrom(
                    (string) ($settings['hq_url'] ?? Master::DEFAULT_ENDPOINT), (string) $st['download_url']),
                $key
            );
            $out = $installer->install($pick['version'], $pick['manifest']);
            if (!$out['ok']) {
                return $err($out['message']);
            }
            $this->settings->set('updates_checked_at', '0');
            $installer->pruneBackups();
            return $ok($out['message'].' If this page asks you to, update the database with the button on it.');
        }

        if (!$st['one_click'] || !$st['ready'] || $st['latest'] === null) {
            return $err($st['blocked_reason'] !== '' ? $st['blocked_reason']
                : 'This server cannot install updates automatically - see the checks on this page.');
        }

        $installer = new \Banimark\Update\Installer(
            \Banimark\Update\Installer::endpointFrom(
                (string) ($settings['hq_url'] ?? Master::DEFAULT_ENDPOINT), (string) $st['download_url']),
            $key
        );
        $out = $installer->install((string) $st['latest'], (array) $st['manifest']);
        if (!$out['ok']) {
            return $err($out['message']);
        }
        // the files moved under our feet - yesterday's version check is stale
        $this->settings->set('updates_checked_at', '0');
        $installer->pruneBackups();
        return $ok($out['message'].' Now update your database with the button on this page.');
    }

    private function changelogPage(string $flash): string
    {
        if (!$this->auth->isOwner()) {
            return Html::page('Changelog', '<div class="bm-card"><div class="empty"><b>Owners only</b>'
                .'<div>Only an owner can see release information.</div></div></div>', $this->nav('/changelog'));
        }
        $u = $this->updates();

        // the same card the Laravel panel renders - one implementation, so an
        // owner is never told two different stories about the same update
        $advice = \Banimark\Ui\Pages::updateCard(
            \Banimark\Update\Status::build($u, $this->settings->all(), (string) $this->settings->get('license_key', ''),
                null, (string) ($this->settings->get('hq_url', '') ?: Master::DEFAULT_ENDPOINT)),
            [
                'update' => $this->url('/changelog/update'),
                'schema' => $this->url('/changelog/database'),
                'rollback' => $this->url('/changelog/rollback'),
                'recheck' => $this->url('/changelog/check'),
                'switch' => $this->url('/changelog/switch'),
                'fetch' => $this->url('/changelog/update/fetch'),
                'apply' => $this->url('/changelog/update/apply'),
            ],
            $this->csrfField()
        );

        return Html::page('Changelog', $flash.$advice
            .\Banimark\Ui\Pages::releaseNotes((array) $u['releases'], Master::PACKAGE_VERSION), $this->nav('/changelog'), 'What is new in Banimark');
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
        $plan = \Banimark\Licensing\Entitlements::summary($verdict, $details, [
            'staff' => count($this->agents->all()),
            'tools' => (int) ($this->query('SELECT COUNT(*) AS c FROM banimark_tools', [])[0]['c'] ?? 0),
        ]);
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
                .'<h2 style="margin:0">'.($isTrial ? 'Free trial' : $e($plan['name'])).' <span class="pill active">ACTIVE</span></h2><div class="muted">'.$e($details['customer'] ?? '').'</div></div></div></div>'
                .$trialBlock
                .'<dl class="bm-dl" style="margin-top:14px">'
                .'<dt>Plan</dt><dd>'.($isTrial ? 'Free trial' : $e($plan['name']))
                    .(!$isTrial && $plan['plan'] !== '' ? ' <span class="muted mono">· '.$e($plan['plan']).'</span>' : '').'</dd>'
                .'<dt>Key</dt><dd class="mono">'.$e($masked).'</dd>'
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
            // what the plan covers, from the same summary the panel greys controls with
            $coverage = \Banimark\Ui\Layout::planCard($plan, $supportUrl, $support);
            return Html::page('License', $flash.$banner.'<div class="bm-grid c2">'.$left.$right.'</div>'.$coverage, $this->nav('/license'), 'Your Banimark licence');
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
        // how to PAY - there used to be no way at all (see Layout::renewCard)
        $renew = ($lock && !in_array($lock['reason'], ['stale', 'module'], true))
            ? Layout::renewCard([
                'reason' => ($lock['reason'] === 'expired' && $isTrial) ? 'trial' : $lock['reason'],
                'key' => $key,
                'site' => (string) ($details['domain'] ?? ($_SERVER['HTTP_HOST'] ?? '')),
                'plan' => $isTrial ? 'Free trial' : (string) ($plan['name'] ?? ''),
                'expires_at' => $expiresAt !== '' ? date('j M Y', strtotime($expiresAt)) : '',
                'support_email' => $support,
                'support_url' => $supportUrl,
            ])
            : '';
        return Html::page('License', $flash.$banner.$renew.'<div class="bm-grid c2">'.$trialCard.$keyCard.'</div>', $this->nav('/license'), 'Activate your Banimark licence');
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
