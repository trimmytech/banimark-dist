<?php

namespace Banimark\Laravel\Admin;

use Banimark\Auth\AgentAuth;
use Banimark\Auth\Agents;
use Banimark\Auth\Totp;
use Banimark\Auth\Permissions;
use Banimark\Licensing\PhoneHome;
use Banimark\Desk\QuickReplies;
use Banimark\Storage\TranscriptView;
use Banimark\Licensing\Master;
use Banimark\Storage\Analytics;
use Banimark\Storage\PdoStore;
use Banimark\Tools\SqlTool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The whole admin panel behind one controller: inbox + human takeover,
 * providers, rules, the Tool Builder, widget settings. Deliberately plain
 * server-rendered pages - it must work inside ANY host app with zero build
 * step. Access control is the host's: the route group takes its middleware
 * from config('banimark.admin.middleware').
 */
class PanelController
{
    /**
     * Laravel's ControllerDispatcher calls this instead of the action when it
     * exists. A try/catch HERE is the only one that runs before the framework's:
     * Illuminate\Routing\Pipeline catches around every middleware and the
     * controller call, hands the exception to the host's ExceptionHandler and
     * returns whatever THAT renders - so a catch in an outer middleware never
     * sees it (the old AdminErrorPage middleware caught nothing; the pilot host
     * turned the throw into a redirect loop). The view is rendered inside the
     * try too, so a Blade error gets the same screen. Framework flow (abort(404),
     * a thrown redirect, validation) passes through untouched.
     */
    public function callAction(string $method, array $parameters)
    {
        // Self-heal before drawing anything, like ensureCurrent does for the
        // database: an update that reached the disk but not the running
        // server (OPcache holding old code, or a route cache from before) is
        // fixed here once, then the page is loaded again on the fresh state.
        if (request()->isMethod('GET') && $method !== 'asset') {
            $healed = \Banimark\Update\CacheRefresh::healStaleCode(
                Master::PACKAGE_VERSION,
                (int) (DB::table('banimark_settings')->where('key', 'opcache_heal_at')->value('value') ?? 0),
                fn (int $t) => self::setSetting('opcache_heal_at', (string) $t),
            ) || \Banimark\Laravel\RouteCache::heal();
            if ($healed) {
                return redirect()->to(request()->fullUrl());
            }
        }
        try {
            $result = $this->{$method}(...array_values($parameters));
            if ($result instanceof \Illuminate\Contracts\View\View) {
                $result = new \Illuminate\Http\Response($result->render());
            }
            return $result;
        } catch (\Throwable $e) {
            if (\Banimark\Laravel\AdminErrorPage::isFrameworkFlow($e)) {
                throw $e;
            }
            $request = null;
            foreach ($parameters as $p) {
                if ($p instanceof Request) { $request = $p; break; }
            }
            return \Banimark\Laravel\AdminErrorPage::render($request ?? app('request'), $e);
        }
    }

    private function gate(AgentAuth $auth, bool $checkLicense = true)
    {
        if (!$auth->sessionValid()) {
            return redirect()->route('banimark.admin.login');
        }
        // no valid license = no admin (widget and chat are untouched). The
        // verdict rides on AgentAuth->lockReason() (encoded Master), so it
        // cannot be stripped from this plaintext controller. Only a missing key
        // or an HQ-confirmed bad status locks - never an outage.
        if ($checkLicense && $auth->lockReason() !== null) {
            return redirect()->route('banimark.admin.license');
        }
        return null;
    }

    /** Panel CSS/JS as files (see Ui\Assets). Public: no secrets, needed on the login page. */
    public function asset(string $name)
    {
        if (!\Banimark\Ui\Assets::exists($name)) {
            abort(404);
        }
        return response(\Banimark\Ui\Assets::content($name), 200, \Banimark\Ui\Assets::headers($name));
    }

    /* ---------------- banimark's own staff login ---------------- */

    public function login(AgentAuth $auth)
    {
        if ($auth->check()) {
            return redirect()->route('banimark.admin.dashboard');
        }
        return view('banimark::admin.login');
    }

    public function doLogin(Request $request, AgentAuth $auth)
    {
        $result = $auth->attempt((string) $request->input('email'), (string) $request->input('password'));
        if ($result === '2fa') {
            return redirect()->route('banimark.admin.login.2fa');
        }
        if ($result === 'pending') {
            return back()->with('bm_error', 'Your account is not activated yet. Use the link in your invitation email, or ask an owner to resend it.');
        }
        if ($result) {
            return redirect()->route('banimark.admin.dashboard');
        }
        return back()->with('bm_error', 'Wrong email or password.');
    }

    /** Second login step for accounts with 2FA on. */
    public function login2fa(AgentAuth $auth)
    {
        if (!$auth->pendingTotp()) {
            return redirect()->route('banimark.admin.login');
        }
        return view('banimark::admin.login-2fa');
    }

    public function doLogin2fa(Request $request, AgentAuth $auth)
    {
        if ($auth->verifyTotp((string) $request->input('code'))) {
            return redirect()->route('banimark.admin.dashboard');
        }
        return back()->with('bm_error', 'That code did not match. Codes change every 30 seconds - try the current one.');
    }

    /* ---------------- security: my 2FA ---------------- */

    public function security(AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $me = $agents->find((int) $auth->id()) ?? [];
        $enabled = (int) ($me['totp_enabled'] ?? 0) === 1;
        $pending = !$enabled ? (string) ($me['totp_secret'] ?? '') : '';
        return view('banimark::admin.security', [
            'enabled' => $enabled,
            'pendingSecret' => $pending,
            'uri' => $pending !== '' ? Totp::uri($pending, (string) ($me['email'] ?? ''), 'Banimark') : '',
            'required' => (\Banimark\Laravel\BanimarkServiceProvider::settings()['require_2fa'] ?? '0') === '1',
        ]);
    }

    public function securityBegin(AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $agents->beginTotp((int) $auth->id());
        return redirect()->route('banimark.admin.security');
    }

    public function securityConfirm(Request $request, AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if ($agents->confirmTotp((int) $auth->id(), (string) $request->input('code'))) {
            return redirect()->route('banimark.admin.security')->with('bm_ok', 'Two-factor authentication is on. You will be asked for a code at every sign-in.');
        }
        return back()->with('bm_error', 'That code did not match - check the time on your phone and try the current code.');
    }

    public function securityDisable(Request $request, AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $me = $agents->find((int) $auth->id()) ?? [];
        if (!Totp::verify((string) ($me['totp_secret'] ?? ''), (string) $request->input('code'))) {
            return back()->with('bm_error', 'Enter a current code from your app to switch 2FA off.');
        }
        $agents->resetTotp((int) $auth->id());
        return redirect()->route('banimark.admin.security')->with('bm_ok', 'Two-factor authentication is off for your account.');
    }

    /* ---------------- staff 2FA policy (owners) ---------------- */

    public function staffTotpReset(Request $request, AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if (!$auth->isOwner()) { return back()->with('bm_error', 'Only an owner can reset 2FA.'); }
        $agents->resetTotp((int) $request->input('id'));
        return back()->with('bm_ok', '2FA reset - they sign in with their password and can enrol again.');
    }

    public function staffTotpRequire(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if (!$auth->isOwner()) { return back()->with('bm_error', 'Only an owner can change this.'); }
        $on = $request->boolean('require_2fa');
        DB::table('banimark_settings')->updateOrInsert(['key' => 'require_2fa'], ['value' => $on ? '1' : '0']);
        return back()->with('bm_ok', $on ? 'Every staff member must now set up 2FA before using the panel.' : '2FA is optional again.');
    }

    public function logout(AgentAuth $auth)
    {
        $auth->logout();
        return redirect()->route('banimark.admin.login');
    }

    /* ---------------- staff (owners only) ---------------- */

    public function agents(AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $s = \Banimark\Laravel\BanimarkServiceProvider::settings();
        return view('banimark::admin.agents', [
            'rows' => $agents->all(), 'isOwner' => $auth->isOwner(), 'meId' => (int) $auth->id(),
            'require2fa' => ($s['require_2fa'] ?? '0') === '1',
            'permissions' => Permissions::ALL, 'presets' => Permissions::PRESETS,
            // seats left, so the invite form can say so before it is filled in
            'seats' => \Banimark\Licensing\Entitlements::allowance($this->entitlements(), 'staff', count($agents->all())),
            'upgradeUrl' => (string) ($s['support_url'] ?? ''),
        ]);
    }

    public function saveAgent(Request $request, AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if (!$auth->isOwner()) { return back()->with('bm_error', 'Only an owner can add staff.'); }
        if (!filter_var($request->input('email'), FILTER_VALIDATE_EMAIL)) {
            return back()->with('bm_error', 'A valid email is required - the invitation goes there.');
        }
        $perms = $request->input('preset', 'agent') === 'custom'
            ? (array) $request->input('perms', [])
            : Permissions::preset((string) $request->input('preset', 'agent'));
        // the plan's seat count. Nobody already invited is touched - this only
        // ever stops the NEXT one.
        $refuse = \Banimark\Licensing\Entitlements::refuseStaff($this->entitlements(), count($agents->all()));
        if ($refuse !== null) {
            return back()->with('bm_error', $refuse);
        }
        $inv = $agents->invite((string) $request->input('name'), (string) $request->input('email'),
            $request->input('role') === 'owner' ? 'owner' : 'agent', $perms);
        if ($inv === false) {
            return back()->with('bm_error', 'That email is already a staff account.');
        }
        return back()->with($this->sendInvite($agents->find($inv['id']), $inv['token'], $auth->name()) ? 'bm_ok' : 'bm_error', session()->get('bm_invite_note'));
    }

    /** Email the activation link; when mail is not set up, hand the owner the link to share. */
    private function sendInvite(array $agent, string $token, string $inviter): bool
    {
        $url = route('banimark.admin.activate', $token);
        $settings = \Banimark\Laravel\BanimarkServiceProvider::settings();
        [$subject, $body] = \Banimark\Notify\Invite::message((string) $agent['name'], $inviter, (string) ($settings['title'] ?? 'Support'), $url);
        $sent = false;
        try {
            $sent = app(\Banimark\Notify\Mailer::class)->send([(string) $agent['email']], $subject, $body);
        } catch (\Throwable $e) {
        }
        // the owner always gets the link too: on many hosts mail() "succeeds" into
        // the void, and an owner can paste a link into chat far faster than debugging SMTP
        session()->flash('bm_invite_note', ($sent
            ? 'Invitation emailed to '.$agent['email'].'. The account stays pending until they set a password.'
            : 'Invitation created for '.$agent['email'].', but the email could not be sent (check Notifications → Email).')
            .' You can also share this link with them directly (works for 7 days): '.$url);
        return $sent;
    }

    public function reinviteAgent(Request $request, AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if (!$auth->isOwner()) { return back()->with('bm_error', 'Only an owner can resend invitations.'); }
        $token = $agents->reinvite((int) $request->input('id'));
        if ($token === null) {
            return back()->with('bm_error', 'That account is not pending.');
        }
        return back()->with($this->sendInvite($agents->find((int) $request->input('id')), $token, $auth->name()) ? 'bm_ok' : 'bm_error', session()->get('bm_invite_note'));
    }

    /** Owner edits what a colleague may do (and their role). */
    public function setPermissions(Request $request, AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if (!$auth->isOwner()) { return back()->with('bm_error', 'Only an owner can change permissions.'); }
        $id = (int) $request->input('id');
        $target = $agents->find($id);
        if (!$target) { return back()->with('bm_error', 'Unknown staff member.'); }
        $agents->setRole($id, $request->input('role') === 'owner' ? 'owner' : 'agent');
        $perms = $request->input('preset', 'custom') === 'custom'
            ? (array) $request->input('perms', [])
            : Permissions::preset((string) $request->input('preset'));
        $agents->setPermissions($id, $perms);
        return back()->with('bm_ok', 'Access for '.$target['name'].' updated - it applies on their next click.');
    }

    /* ---------------- activation (public: the invitee has no session yet) ---------------- */

    public function activate(string $token, Agents $agents)
    {
        $agent = $agents->findByInviteToken($token);
        return view('banimark::admin.activate', ['agent' => $agent, 'token' => $token]);
    }

    public function doActivate(Request $request, string $token, Agents $agents)
    {
        $agent = $agents->findByInviteToken($token);
        if (!$agent) {
            return redirect()->route('banimark.admin.activate', $token);
        }
        $pw = (string) $request->input('password');
        if (strlen($pw) < 8 || $pw !== (string) $request->input('password_confirmation')) {
            return back()->with('bm_error', 'Use at least 8 characters, and type the same password twice.');
        }
        $agents->activate((int) $agent['id'], (string) $request->input('name', $agent['name']), $pw);
        return redirect()->route('banimark.admin.login')->with('bm_ok', 'Your account is active - sign in with your new password.');
    }

    public function deleteAgent(Request $request, AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if ($auth->isOwner()) { $agents->delete((int) $request->input('id')); }
        return back()->with('bm_ok', 'Staff removed.');
    }

    public function saveWorkingHours(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        \Banimark\Desk\BusinessHours::save($request->all(), fn (string $k, string $v) => self::setSetting($k, $v));
        return back()->with('bm_ok', 'Working hours saved.');
    }

    /* ---------------- AI settings / data & protection / team ---------------- */

    private static function setSetting(string $k, string $v): void
    {
        DB::table('banimark_settings')->updateOrInsert(['key' => $k], ['value' => $v]);
    }

    public function aiSettings(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        return view('banimark::admin.ai', ['body' => \Banimark\Ui\Pages::aiSettings(
            \Banimark\Laravel\BanimarkServiceProvider::settings(), route('banimark.admin.ai.save'), csrf_field())]);
    }

    public function saveAiSettings(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        \Banimark\Ui\Pages::saveAiSettings($request->all(), fn (string $k, string $v) => self::setSetting($k, $v));
        return back()->with('bm_ok', 'AI settings saved. They apply from the next message.');
    }

    public function dataPage(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $stats = [
            'conversations' => (int) DB::table('banimark_conversations')->count(),
            'messages' => (int) DB::table('banimark_messages')->count(),
            'files' => (int) DB::table('banimark_attachments')->count(),
            'oldest' => (int) DB::table('banimark_conversations')->where('last_message_at', '>', 0)->min('last_message_at'),
        ];
        return view('banimark::admin.data', ['body' => \Banimark\Ui\Pages::dataPage(
            \Banimark\Laravel\BanimarkServiceProvider::settings(), $stats,
            ['save' => route('banimark.admin.data.save'), 'delete_all' => route('banimark.admin.data.delete_all')], csrf_field())]);
    }

    public function saveDataSettings(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        \Banimark\Ui\Pages::saveDataSettings($request->all(), fn (string $k, string $v) => self::setSetting($k, $v));
        return back()->with('bm_ok', 'Saved.');
    }

    private function retention(): \Banimark\Storage\Retention
    {
        return new \Banimark\Storage\Retention(DB::connection()->getPdo(), app(\Banimark\Files\FileStore::class));
    }

    public function deleteAllHistory(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if (trim((string) $request->input('confirm')) !== 'DELETE') {
            return back()->with('bm_error', 'Type DELETE in the box to confirm.');
        }
        $n = $this->retention()->deleteAll();
        return back()->with('bm_ok', "Deleted {$n} conversation(s) and everything in them.");
    }

    public function deleteConversation(string $sessionId, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $this->retention()->deleteConversation($sessionId);
        return redirect()->route('banimark.admin.inbox')->with('bm_ok', 'Conversation deleted.');
    }

    /** A conversation the visitor deleted: keep it (never erased automatically) or let it go again. */
    public function keepConversation(Request $request, string $sessionId, PdoStore $store, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $keep = $request->input('keep', '1') === '1';
        $store->setKept($sessionId, $keep);
        return back()->with('bm_ok', $keep
            ? 'Kept - this conversation will not be erased automatically.'
            : 'It will be erased automatically when its time comes.');
    }

    public function forgetVisitor(string $sessionId, PdoStore $store, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $identity = $store->identityOf($sessionId);
        if ($identity === '' || $identity === 'anon') {
            // an anonymous thread has no identity to follow - delete just this one
            $this->retention()->deleteConversation($sessionId);
            return redirect()->route('banimark.admin.inbox')->with('bm_ok', 'Conversation deleted (the visitor was anonymous, so there was nothing else of theirs to find).');
        }
        $n = $this->retention()->deleteVisitor($identity);
        return redirect()->route('banimark.admin.inbox')->with('bm_ok', "Deleted {$n} conversation(s) from that visitor.");
    }

    public function team(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $days = in_array((int) $request->query('days', 7), [7, 30, 90], true) ? (int) $request->query('days', 7) : 7;
        $stats = new \Banimark\Storage\TeamStats(DB::connection()->getPdo());
        $since = time() - $days * 86400;
        return view('banimark::admin.team', ['body' => \Banimark\Ui\Pages::team(
            $stats->summary($since), $stats->recent(25), $stats->overview($since), $days,
            route('banimark.admin.team'), fn (string $sid) => route('banimark.admin.conversation', $sid))]);
    }

    /* ---------------- files ---------------- */

    public function files(AgentAuth $auth, \Banimark\Storage\Attachments $attachments)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $s = \Banimark\Laravel\BanimarkServiceProvider::settings();
        $row = DB::table('banimark_attachments')->selectRaw('COUNT(*) AS c, COALESCE(SUM(size), 0) AS s')->first();
        return view('banimark::admin.files', [
            's' => $s,
            'problem' => \Banimark\Files\FileStoreFactory::misconfigured($s),
            'defaultDir' => storage_path('app/banimark-files'),
            'stats' => ['count' => (int) ($row->c ?? 0), 'size' => (int) ($row->s ?? 0)],
            // greyed out rather than absent: an owner who cannot see S3 here
            // never learns it exists, and one who can fill it in only finds out
            // at the save
            'locks' => \Banimark\Licensing\Entitlements::locks($this->entitlements()),
            'upgradeUrl' => (string) ($s['support_url'] ?? ''),
        ]);
    }

    public function saveFiles(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $set = fn (string $k, string $v) => DB::table('banimark_settings')->updateOrInsert(['key' => $k], ['value' => $v]);
        $set('files_enabled', $request->boolean('files_enabled') ? '1' : '0');
        $set('files_ai_read', $request->boolean('files_ai_read') ? '1' : '0');
        $set('files_max_mb', (string) max(1, min(100, (int) $request->input('files_max_mb', 10))));
        $set('files_types', trim((string) $request->input('files_types')));
        $wantsS3 = $request->input('files_driver') === 's3';
        if ($wantsS3) {
            $refuse = \Banimark\Licensing\Entitlements::refuseS3($this->entitlements());
            if ($refuse !== null) {
                return back()->with('bm_error', $refuse);
            }
        }
        $set('files_driver', $wantsS3 ? 's3' : 'local');
        $set('files_local_path', trim((string) $request->input('files_local_path')));
        foreach (['files_s3_bucket', 'files_s3_region', 'files_s3_endpoint', 'files_s3_prefix'] as $k) {
            $set($k, trim((string) $request->input($k)));
        }
        $set('files_s3_key', trim((string) $request->input('files_s3_key')));
        $set('files_s3_path_style', $request->boolean('files_s3_path_style') ? '1' : '0');
        // blank keeps the stored secret, like every other key in this panel
        $secret = (string) $request->input('files_s3_secret', '');
        if ($secret !== '') {
            $set('files_s3_secret', $secret);
        }
        return back()->with('bm_ok', 'File settings saved.');
    }

    public function testFiles(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $s = \Banimark\Laravel\BanimarkServiceProvider::settings();
        $problem = \Banimark\Files\FileStoreFactory::misconfigured($s);
        if ($problem !== '') {
            return back()->with('bm_files_test', $problem)->with('bm_files_ok', false);
        }
        $result = \Banimark\Files\SelfTest::run(\Banimark\Files\FileStoreFactory::make($s, storage_path('app/banimark-files')));
        return back()->with('bm_files_test', $result['message'])->with('bm_files_ok', $result['ok']);
    }

    /* ---------------- licensing ---------------- */


    /** @return array<string, string> the license_* / hq_url settings rows */
    private function licenseSettings(): array
    {
        return DB::table('banimark_settings')
            // 'schema_version' is here because the update card asks this array
            // whether the tables have caught up. Leaving it out does not read as
            // "up to date" - it reads as "never installed", so the panel nagged
            // every install forever and the button then blamed the database.
            ->whereIn('key', ['license_key', 'license_status', 'license_last_ping', 'license_token', 'license_unreachable_since', 'license_details', 'license_check_interval', 'hq_url', 'updates_cache', 'updates_checked_at', 'support_email', 'support_url', 'schema_version'])
            ->pluck('value', 'key')->all();
    }

    /** Panel-entered key first, then env/config - so a stale published config
     *  or no .env access never blocks entering a key. */
    private function licenseKey(array $s): string
    {
        return trim((string) (($s['license_key'] ?? '') !== '' ? $s['license_key']
            : (config('banimark.license.key') ?? env('BANIMARK_LICENSE_KEY', ''))));
    }

    /** The signed verdict for this install - what the plan actually allows. */
    private function entitlements(): array
    {
        return \Banimark\Licensing\Entitlements::verdict(
            \Banimark\Laravel\BanimarkServiceProvider::settings(),
            (string) request()->getHost()
        );
    }

    private function hqEndpoint(array $s): string
    {
        return (string) ((($s['hq_url'] ?? '') !== '' ? $s['hq_url']
            : (config('banimark.license.hq_url') ?? env('BANIMARK_HQ_URL', ''))) ?: Master::DEFAULT_ENDPOINT);
    }

    public function license(AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) {
            // staff never touch licensing; while locked they can only sign out
            return $auth->lockReason()
                ? view('banimark::admin.locked-staff', ['supportEmail' => (string) ($this->licenseSettings()['support_email'] ?? '')])
                : redirect()->route('banimark.admin.dashboard');
        }
        $s = $this->licenseSettings();
        $key = $this->licenseKey($s);
        $status = (string) ($s['license_status'] ?? '');
        $verdict = Master::verify($key, (string) ($s['license_token'] ?? ''), null, (string) request()->getHost());
        $details = json_decode((string) ($s['license_details'] ?? ''), true) ?: [];
        $isTrial = ($details['plan'] ?? $verdict['plan'] ?? '') === 'trial';
        $active = $status === 'active' && $auth->lockReason() === null;
        return view('banimark::admin.license', [
            // an ACTIVE paid key is read-only (swapping it is how a licence walks to
            // another install); a TRIAL key stays editable so a purchased key can replace it
            'keyLocked' => $active && !$isTrial,
            'active' => $active,
            'isTrial' => $isTrial,
            'details' => $details,
            'expiresAt' => (string) ($details['expires_at'] ?? $verdict['expires_at'] ?? ''),
            'daysLeft' => ($d = (string) ($details['expires_at'] ?? $verdict['expires_at'] ?? '')) !== '' ? (int) ceil((strtotime($d.' 23:59:59') - time()) / 86400) : null,
            'supportEmail' => (string) ($s['support_email'] ?? ''),
            'supportUrl' => (string) ($s['support_url'] ?? ''),
            'lock' => $auth->lockReason(),
            'modules' => $verdict['modules'] ?: ($details['modules'] ?? []),
            'key' => $key,
            'maskedKey' => $key !== '' ? preg_replace('/^(BM-[A-Z0-9]{4})-[A-Z0-9-]+-([A-Z0-9]{4})$/', '$1-••••-••••-$2', $key) : '',
            'hqUrl' => (string) ($s['hq_url'] ?? ''),
            'status' => $status,
            'lastPing' => (int) ($s['license_last_ping'] ?? 0),
            'checkInterval' => Master::intervalFor($key, (string) ($s['license_token'] ?? '')),
            'canTrial' => $key === '',
            // what the plan covers AND what is already used, so the page can
            // show "2 of 5 staff" rather than waiting for a save to refuse
            'plan' => \Banimark\Licensing\Entitlements::summary($verdict, $details, [
                'staff' => count($agents->all()),
                'tools' => (int) DB::table('banimark_tools')->count(),
            ]),
        ]);
    }

    /** Owner asks HQ for the free trial this site is entitled to. */
    public function startTrial(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return redirect()->route('banimark.admin.dashboard'); }
        $settings = $this->licenseSettings();
        $settings['hq_url'] = $this->hqEndpoint($settings);
        $result = PhoneHome::startTrial(
            $settings,
            $request->getSchemeAndHttpHost(),
            fn (string $k, string $v) => DB::table('banimark_settings')->updateOrInsert(['key' => $k], ['value' => $v]),
            fn (string $k) => DB::table('banimark_settings')->where('key', $k)->delete(),
        );
        if (!empty($result['ok'])) {
            return redirect()->route('banimark.admin.dashboard')->with('bm_ok', 'Your free trial has started - '.(int) ($result['trial_days'] ?? 0).' days, until '.$result['expires_at'].'. Welcome to your Support Desk.');
        }
        $why = (string) ($result['message'] ?? '');
        return back()->with('bm_error', $why !== '' ? $why : 'Could not reach Banimark HQ to start the trial. Try again in a moment, or enter a purchased key.');
    }

    /** "Re-check now" on the licence page: the daily check, forced. */
    public function recheckLicense(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return redirect()->route('banimark.admin.dashboard'); }
        $settings = $this->licenseSettings();
        $settings['license_key'] = $this->licenseKey($settings);
        $settings['hq_url'] = $this->hqEndpoint($settings);
        $result = PhoneHome::run($settings, $request->getSchemeAndHttpHost(),
            fn (string $k, string $v) => DB::table('banimark_settings')->updateOrInsert(['key' => $k], ['value' => $v]),
            fn (string $k) => DB::table('banimark_settings')->where('key', $k)->delete(), force: true);
        if ($result === null || empty($result['ok'])) {
            return back()->with('bm_error', PhoneHome::unreachableMessage($settings));
        }
        return back()->with('bm_ok', 'Checked with HQ just now - status: '.$result['license'].'.');
    }

    /**
     * Version + changelog from HQ, cached in settings. Never licence-gated:
     * a lapsed customer must still see what is new and how to get it.
     *
     * @return array{ok: bool, latest: ?string, releases: array, update_command: string, outdated: bool}
     */
    private function updates(array $s): array
    {
        $cached = json_decode((string) ($s['updates_cache'] ?? ''), true);
        $cached = is_array($cached) ? $cached : null;

        if (\Banimark\Update\UpdateCheck::due((string) ($s['updates_checked_at'] ?? '0'))) {
            try {
                $fresh = (new \Banimark\Update\UpdateCheck(
                    \Banimark\Update\UpdateCheck::endpointFrom($this->hqEndpoint($s)),
                    null,
                    $this->licenseKey($s)   // lets HQ offer TEST releases to test installs
                ))->fetch();
                DB::table('banimark_settings')->updateOrInsert(['key' => 'updates_checked_at'], ['value' => (string) time()]);
                if ($fresh['ok']) {
                    DB::table('banimark_settings')->updateOrInsert(['key' => 'updates_cache'], ['value' => json_encode($fresh)]);
                    $cached = $fresh;
                }
            } catch (\Throwable $e) {
                // a version check must never break the panel
            }
        }

        $cached = $cached ?: ['ok' => false, 'latest' => null, 'releases' => [], 'update_command' => 'composer update banimark/banimark'];
        $cached['outdated'] = \Banimark\Update\UpdateCheck::isNewer($cached['latest'] ?? null);
        return $cached;
    }

    /**
     * What's new, and whether to update. Owner-only, and NOT licence-gated:
     * the person who pays for renewals must be able to see that a release
     * exists even while the panel is locked.
     */
    public function changelog(AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) {
            return redirect()->route('banimark.admin.dashboard');
        }
        $s = $this->licenseSettings();
        return view('banimark::admin.changelog', [
            'updates' => $this->updates($s),
            'status' => \Banimark\Update\Status::build($this->updates($s), $s, $this->licenseKey($s), null, $this->hqEndpoint($s)),
        ]);
    }

    /**
     * Download and swap in the new version. Owner-only, and never silent: the
     * result - good or bad - comes back as a flash the owner can read.
     */
    public function runUpdate(AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return redirect()->route('banimark.admin.dashboard'); }

        $s = $this->licenseSettings();
        $st = \Banimark\Update\Status::build($this->updates($s), $s, $this->licenseKey($s), null, $this->hqEndpoint($s));
        if (!$st['one_click'] || !$st['ready'] || $st['latest'] === null) {
            return back()->with('bm_error', $st['blocked_reason'] !== '' ? $st['blocked_reason']
                : 'This server cannot install updates automatically - see the checks on this page.');
        }

        $installer = new \Banimark\Update\Installer(
            \Banimark\Update\Installer::endpointFrom($this->hqEndpoint($s), (string) $st['download_url']),
            $this->licenseKey($s)
        );
        $out = $installer->install((string) $st['latest'], (array) $st['manifest']);
        if (!$out['ok']) {
            return back()->with('bm_error', $out['message']);
        }

        // the files just moved under our feet; the version check cached before
        // the swap is now stale, so drop it rather than show yesterday's answer
        DB::table('banimark_settings')->where('key', 'updates_checked_at')->delete();
        $installer->pruneBackups();

        return back()->with('bm_ok', $out['message'].' Now update your database with the button on this page.');
    }

    /**
     * Stage one of the update, over AJAX: download and verify.
     *
     * Two endpoints rather than one because the two halves have different
     * risks - this one is slow and reversible, the next is instant and not -
     * and because a panel that says "downloading" then "installing" is telling
     * the truth about where it actually is.
     */
    public function runUpdateFetch(AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return response()->json(['ok' => false, 'error' => 'Only an owner can update Banimark.'], 403); }

        $s = $this->licenseSettings();
        $st = \Banimark\Update\Status::build($this->updates($s), $s, $this->licenseKey($s), null, $this->hqEndpoint($s));
        if (!$st['one_click'] || !$st['ready'] || $st['latest'] === null) {
            return response()->json(['ok' => false, 'error' => $st['blocked_reason'] !== '' ? $st['blocked_reason']
                : 'This server cannot install updates automatically - see the checks on this page.'], 422);
        }

        $out = (new \Banimark\Update\Installer(
            \Banimark\Update\Installer::endpointFrom($this->hqEndpoint($s), (string) $st['download_url']),
            $this->licenseKey($s)
        ))->fetch((string) $st['latest'], (array) $st['manifest']);

        if (!$out['ok']) {
            return response()->json(['ok' => false, 'error' => $out['message']], 422);
        }
        // the staged path stays server-side; the browser never names a directory
        DB::table('banimark_settings')->updateOrInsert(['key' => 'update_staged'], ['value' => $out['staged']]);
        DB::table('banimark_settings')->updateOrInsert(['key' => 'update_staged_version'], ['value' => (string) $st['latest']]);

        return response()->json(['ok' => true, 'message' => $out['message'], 'version' => $st['latest']]);
    }

    /** Stage two: the swap. */
    public function runUpdateApply(AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return response()->json(['ok' => false, 'error' => 'Only an owner can update Banimark.'], 403); }

        $staged = (string) (DB::table('banimark_settings')->where('key', 'update_staged')->value('value') ?? '');
        $version = (string) (DB::table('banimark_settings')->where('key', 'update_staged_version')->value('value') ?? '');
        if ($staged === '') {
            return response()->json(['ok' => false, 'error' => 'Nothing is staged - download the update first.'], 422);
        }

        $installer = new \Banimark\Update\Installer('', '');
        $out = $installer->apply($staged, $version);
        DB::table('banimark_settings')->whereIn('key', ['update_staged', 'update_staged_version'])->delete();

        if (!$out['ok']) {
            return response()->json(['ok' => false, 'error' => $out['message']], 422);
        }
        // the files moved under our feet; yesterday's version check is stale
        DB::table('banimark_settings')->where('key', 'updates_checked_at')->delete();
        $installer->pruneBackups();
        // Laravel's caches (routes, config, events, views) would keep serving
        // the old version: dropped now, rebuilt by the database step, which
        // runs on the new code
        \Banimark\Laravel\RouteCache::clearForUpdate();

        return response()->json(['ok' => true, 'message' => $out['message'], 'schema_next' => true]);
    }

    /**
     * Ask HQ again, now. The answer is cached for six hours, and the first
     * thing anyone does after fixing a connection is retry - making them wait
     * out a cache they cannot see is how "it is not working" becomes support.
     */
    /**
     * Reinstall this version, or leave a TEST build for the latest stable one -
     * the ways out an updater that only offers NEWER versions never had. Only
     * a version Status itself offers is accepted; the form cannot name one.
     */
    public function runSwitch(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return redirect()->route('banimark.admin.dashboard'); }

        $s = $this->licenseSettings();
        $st = \Banimark\Update\Status::build($this->updates($s), $s, $this->licenseKey($s), null, $this->hqEndpoint($s));
        $pick = null;
        foreach ((array) $st['alternatives'] as $alt) {
            if ($alt['version'] === (string) $request->input('version')) {
                $pick = $alt;
            }
        }
        if ($pick === null) {
            return back()->with('bm_error', 'That release is not on offer any more. Check for updates, then try again.');
        }
        $installer = new \Banimark\Update\Installer(
            \Banimark\Update\Installer::endpointFrom($this->hqEndpoint($s), (string) $st['download_url']),
            $this->licenseKey($s)
        );
        $out = $installer->install($pick['version'], $pick['manifest']);
        if (!$out['ok']) {
            return back()->with('bm_error', $out['message']);
        }
        DB::table('banimark_settings')->where('key', 'updates_checked_at')->delete();
        $installer->pruneBackups();
        return back()->with('bm_ok', $out['message'].' If this page asks you to, update the database with the button on it.');
    }

    public function runRecheck(AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return redirect()->route('banimark.admin.dashboard'); }

        DB::table('banimark_settings')->where('key', 'updates_checked_at')->delete();
        $s = $this->licenseSettings();
        $fresh = $this->updates($s);

        if (!($fresh['ok'] ?? false)) {
            return $this->answer(false, 'Still could not reach '.$this->hqEndpoint($s).'. Check the server can make outbound requests.');
        }
        return $this->answer(true, \Banimark\Update\UpdateCheck::isNewer($fresh['latest'] ?? null)
            ? 'Version '.$fresh['latest'].' is available.'
            : 'Checked - you are on the latest version.');
    }

    /** Apply the new version's database changes. The second, explicit button. */
    public function runSchema(AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return redirect()->route('banimark.admin.dashboard'); }

        $did = \Banimark\Storage\Schema::ensureCurrent(DB::connection()->getPdo(), Master::PACKAGE_VERSION);
        // and the caches the install step dropped, rebuilt from the new files
        $rebuilt = \Banimark\Laravel\RouteCache::rebuildAfterUpdate();
        $cachesNote = $rebuilt === [] ? '' : ' Laravel\'s '.implode(', ', $rebuilt).' cache'.(count($rebuilt) > 1 ? 's were' : ' was').' rebuilt.';
        if ($did) {
            return $this->answer(true, 'Your database is up to date with '.Master::PACKAGE_VERSION.'.'.$cachesNote);
        }
        // ensureCurrent never throws, so "false" is either nothing-to-do or a
        // permission problem. Ask the DATABASE which it was - asking a cached
        // settings array is how this came to accuse healthy installs.
        $stored = (string) (DB::table('banimark_settings')->where('key', 'schema_version')->value('value') ?? '');
        return $stored === Master::PACKAGE_VERSION
            ? $this->answer(true, 'Your database was already up to date.'.$cachesNote)
            : $this->answer(false, 'The database did not change - it is still on '
                .($stored !== '' ? $stored : 'no recorded version')
                .'. The database user Banimark connects with probably cannot alter tables; ask your host for CREATE, ALTER and INDEX rights, then try again.');
    }

    /** Same verdict either way: JSON for the progress panel, a flash without JS. */
    private function answer(bool $ok, string $message)
    {
        if (request()->ajax() || request()->wantsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message, 'error' => $ok ? null : $message], $ok ? 200 : 422);
        }
        return back()->with($ok ? 'bm_ok' : 'bm_error', $message);
    }

    /** Put a previous version back. */
    public function runRollback(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return redirect()->route('banimark.admin.dashboard'); }

        $out = (new \Banimark\Update\Installer('', ''))->rollback((string) $request->input('backup', ''));
        DB::table('banimark_settings')->where('key', 'updates_checked_at')->delete();
        return back()->with($out['ok'] ? 'bm_ok' : 'bm_error', $out['message']);
    }

    public function saveLicense(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) { return redirect()->route('banimark.admin.dashboard'); }
        $key = trim((string) $request->input('license_key', ''));
        $current = $this->licenseSettings();
        if (($current['license_status'] ?? '') === 'active' && $auth->lockReason() === null
            && $key !== '' && $key !== $this->licenseKey($current)) {
            return back()->with('bm_error', 'Your licence is active. To move to a different key, contact support.');
        }
        DB::table('banimark_settings')->updateOrInsert(['key' => 'license_key'], ['value' => $key]);
        // hq_url is deliberately NOT a panel field - customers should never have
        // to know it. Support can still override it via BANIMARK_HQ_URL, and an
        // already-stored value is left alone rather than blanked by this save.
        if ($key === '') {
            DB::table('banimark_settings')->whereIn('key', ['license_status', 'license_token'])->delete();
            return back()->with('bm_ok', 'License settings saved.');
        }
        // immediate check - through the same fail-open path as the daily one, so
        // pressing the button during an HQ outage can never lock an active licence
        $settings = $this->licenseSettings();
        $settings['license_key'] = $key;
        $settings['hq_url'] = $this->hqEndpoint($settings);
        $result = \Banimark\Licensing\PhoneHome::run(
            $settings,
            $request->getSchemeAndHttpHost(),
            fn (string $k, string $v) => DB::table('banimark_settings')->updateOrInsert(['key' => $k], ['value' => $v]),
            fn (string $k) => DB::table('banimark_settings')->where('key', $k)->delete(),
            force: true,
        );
        if ($result === null || empty($result['ok'])) {
            return back()->with('bm_error', \Banimark\Licensing\PhoneHome::unreachableMessage($settings));
        }
        if ($result['license'] === 'active') {
            // activated: straight into the module this licence unlocks
            return redirect()->route('banimark.admin.dashboard')->with('bm_ok', 'Licence active - welcome to your Support Desk.');
        }
        return back()->with('bm_error', 'License checked - status: '.$result['license'].($result['message'] !== '' ? ' · '.$result['message'] : ''));
    }

    /* ---------------- escalation settings ---------------- */

    public function escalation(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $s = DB::table('banimark_settings')->pluck('value', 'key')->all();
        return view('banimark::admin.escalation', [
            'mode' => $s['escalation_mode'] ?? 'staff',
            'email' => $s['escalation_email'] ?? '',
            's' => $s,
            'hasSmtpPass' => trim((string) ($s['smtp_pass'] ?? '')) !== '',
        ]);
    }

    public function saveEscalation(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $set = function (string $key, $value) {
            DB::table('banimark_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value]);
        };
        $set('escalation_mode', $request->input('escalation_mode') === 'email' ? 'email' : 'staff');
        $set('escalation_email', trim((string) $request->input('escalation_email')));

        $set('smtp_enabled', $request->boolean('smtp_enabled') ? '1' : '0');
        $set('smtp_host', trim((string) $request->input('smtp_host')));
        $set('smtp_port', (string) max(1, min(65535, (int) $request->input('smtp_port', 587))));
        $set('smtp_user', trim((string) $request->input('smtp_user')));
        $set('smtp_encryption', in_array($request->input('smtp_encryption'), ['tls', 'ssl', 'none'], true) ? $request->input('smtp_encryption') : 'tls');
        $set('smtp_from_email', trim((string) $request->input('smtp_from_email')));
        $set('smtp_from_name', trim((string) $request->input('smtp_from_name')) ?: 'Support');
        // blank keeps the stored password, like the AI provider keys
        $pass = (string) $request->input('smtp_pass', '');
        if ($pass !== '') {
            $set('smtp_pass', $pass);
        }

        $set('visitor_followup', $request->boolean('visitor_followup') ? '1' : '0');
        $set('visitor_followup_after', (string) max(30, (int) $request->input('visitor_followup_after', 120)));

        return back()->with('bm_ok', 'Notification settings saved.');
    }

    /** Prove the SMTP details work before an escalation depends on them. */
    public function testEmail(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $to = trim((string) $request->input('test_email'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return back()->with('bm_error', 'Enter a valid address to send the test to.');
        }
        $mailer = \Banimark\Notify\MailerFactory::make(\Banimark\Laravel\BanimarkServiceProvider::settings());
        $ok = $mailer->send([$to], 'Banimark test email', "This is a test from your Banimark support desk.\n\nIf you are reading it, escalation alerts and visitor follow-ups will send correctly.");
        return back()->with($ok ? 'bm_ok' : 'bm_error', $ok
            ? 'Test email sent to '.$to.'.'
            : 'Could not send: '.($mailer->lastError() ?: 'unknown error'));
    }

    /* ---------------- dashboard ---------------- */

    /** How many analyses an hour: each one is a large call to the owner's provider. */
    private const INSIGHTS_PER_HOUR = 10;

    /**
     * Customer insights: the owner's AI provider reads what visitors wrote over
     * a period and the report is stored for the dashboard. Owner-only (the
     * route is not in the Permissions map), rate-limited, never automatic.
     */
    public function runInsights(Request $request, AgentAuth $auth, \Banimark\Http\RateLimiter $limiter)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if (!$auth->isOwner()) { return redirect()->route('banimark.admin.dashboard'); }
        $back = redirect()->to(route('banimark.admin.dashboard').'#insights');
        if ($limiter->hit('insights', 3600) > self::INSIGHTS_PER_HOUR) {
            return $back->with('bm_error', 'That is a lot of analyses in one hour. Give it a little while - each one is a call to your AI provider.');
        }
        [$driver, , $model] = \Banimark\Laravel\EngineFactory::driver();
        if ($driver === null) {
            return $back->with('bm_error', 'Connect an AI provider first - the analysis uses the same key your chat does.');
        }
        $days = (int) $request->input('days', 30);
        $days = isset(\Banimark\Insights\ConversationInsights::PERIODS[$days]) ? $days : 30;
        @set_time_limit(180);   // a long period is a big read for the model
        $out = (new \Banimark\Insights\ConversationInsights(DB::connection()->getPdo()))->analyse($driver, $model, $days);
        if (!$out['ok']) {
            return $back->with('bm_error', $out['error']);
        }
        DB::table('banimark_settings')->updateOrInsert(['key' => \Banimark\Insights\ConversationInsights::SETTING], ['value' => json_encode($out['report'])]);
        return $back->with('bm_ok', 'Analysis ready - '.$out['report']['conversations_used'].' conversations read.');
    }

    public function dashboard(Request $request, AgentAuth $auth, PdoStore $store)
    {
        // the dashboard stays reachable while the licence is locked (the middleware
        // exempts it too), so an owner on a never-activated install lands somewhere
        // that shows what to do. Login is still required; only the licence check is skipped.
        if ($r = $this->gate($auth, false)) { return $r; }
        // the daily HQ re-check now lives in EnsureBanimarkAccess (before the verdict)
        $pdo = DB::connection()->getPdo();
        $days = (int) $request->query('days', 30);
        $insights = \Banimark\Insights\ConversationInsights::stored(
            DB::table('banimark_settings')->where('key', \Banimark\Insights\ConversationInsights::SETTING)->pluck('value', 'key')->all());
        $hasProvider = \Banimark\Laravel\EngineFactory::driver()[0] !== null;
        return view('banimark::admin.dashboard', [
            'name' => $auth->name(),
            'body' => \Banimark\Ui\Pages::dashboard((new Analytics($pdo))->period($days), $store->listConversations(6), [
                'period_url' => fn (int $d) => route('banimark.admin.dashboard', ['days' => $d]),
                'conversation_url' => fn (string $sid) => route('banimark.admin.conversation', $sid),
                'inbox' => route('banimark.admin.inbox'),
                'providers' => route('banimark.admin.providers'),
                'tools' => route('banimark.admin.tools'),
                'widget' => route('banimark.admin.widget'),
                'insights' => $insights,
                'has_provider' => $hasProvider,
                'tools_count' => (int) DB::table('banimark_tools')->count(),
                'owner' => $auth->isOwner(),
                'insights_html' => \Banimark\Insights\ConversationInsights::render($insights, [
                    'action' => route('banimark.admin.insights'),
                    'csrf' => csrf_field()->toHtml(),
                    'can_run' => $auth->isOwner(),
                    'has_provider' => $hasProvider,
                ]),
            ]),
        ]);
    }

    /* ---------------- inbox / live chat ---------------- */

    public function inbox(Request $request, PdoStore $store, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $filters = self::inboxFilters($request->all());
        $counts = $store->inboxCounts();
        return view('banimark::admin.inbox', [
            'counts' => $counts,
            'subtitle' => \Banimark\Ui\Pages::inboxSubtitle($counts),
            'body' => \Banimark\Ui\Pages::inbox(
                $store->listConversations(100, $filters['mode'], $filters['q'], $filters),
                $counts,
                $filters,
                route('banimark.admin.inbox'),
                fn (string $sid) => route('banimark.admin.conversation', $sid),
                $auth->name(),
            ),
        ]);
    }

    /** The inbox filters, from the query string, sanitised. */
    private static function inboxFilters(array $input): array
    {
        return [
            'mode' => in_array($input['mode'] ?? '', ['ai', 'agent', 'closed'], true) ? $input['mode'] : null,
            'q' => trim((string) ($input['q'] ?? '')),
            'unread' => empty($input['unread']) ? 0 : 1,
            'waiting' => empty($input['waiting']) ? 0 : 1,
            'files' => empty($input['files']) ? 0 : 1,
            'known' => empty($input['known']) ? 0 : 1,
            'sort' => ($input['sort'] ?? '') === 'waiting' ? 'waiting' : '',
        ];
    }

    public function conversation(string $sessionId, PdoStore $store, AgentAuth $auth, \Banimark\Storage\Attachments $attachments)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $rows = TranscriptView::rows($store->transcript($sessionId), $attachments);
        $store->markStaffSeen($sessionId); // opening it clears the unread dot in the inbox
        $settings = \Banimark\Laravel\BanimarkServiceProvider::settings();
        return view('banimark::admin.conversation', [
            // ONE body for both runtimes: Ui\Pages::conversation
            'body' => \Banimark\Ui\Pages::conversation([
                'session_id' => $sessionId,
                'mode' => $store->mode($sessionId),
                'rows' => $rows,
                'presence' => $store->presence($sessionId) ?? [],
                'quick' => QuickReplies::fromSettings($settings),
                'files_on' => \Banimark\Files\FileStoreFactory::enabled($settings),
                'can_delete' => $auth->can('inbox.delete'),
                'visitor_delete_days' => \Banimark\Storage\Retention::visitorDeleteDays($settings),
                'csrf_field' => csrf_field()->toHtml(),
                'csrf_name' => '_token',
                'csrf_value' => csrf_token(),
                'urls' => [
                    'inbox' => route('banimark.admin.inbox'),
                    'mode' => route('banimark.admin.conversation.mode', $sessionId),
                    'delete' => route('banimark.admin.conversation.delete', $sessionId),
                    'forget' => route('banimark.admin.conversation.forget', $sessionId),
                    // a stale route cache must not take the whole page down
                    'keep' => \Banimark\Laravel\RouteCache::url('banimark.admin.conversation.keep', $sessionId),
                    'messages' => route('banimark.admin.conversation.messages', $sessionId),
                    'reply' => route('banimark.admin.conversation.reply', $sessionId),
                    'upload' => route('banimark.admin.conversation.upload', $sessionId),
                    'file' => url('banimark/file').'/',
                ],
            ]),
        ]);
    }

    /** Staff attach a file to their reply (same store and rules as the visitor's). */
    public function uploadReply(Request $request, string $sessionId, AgentAuth $auth, \Banimark\Storage\Attachments $attachments, \Banimark\Files\FileStore $files)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if (!$auth->can('inbox.reply')) {
            return response()->json(['ok' => false, 'error' => 'You do not have permission to reply here.'], 403);
        }
        $settings = \Banimark\Laravel\BanimarkServiceProvider::settings();
        if (!\Banimark\Files\FileStoreFactory::enabled($settings)) {
            return response()->json(['ok' => false, 'error' => 'File sharing is switched off on the Files page.'], 422);
        }
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'That upload did not arrive in one piece.'], 422);
        }
        $bytes = (string) @file_get_contents($file->getRealPath());
        $check = \Banimark\Files\UploadPolicy::fromSettings($settings)->check((string) $file->getClientOriginalName(), (int) $file->getSize(), $bytes);
        if (!$check['ok']) {
            return response()->json(['ok' => false, 'error' => $check['error']], 422);
        }
        $key = \Banimark\Files\UploadPolicy::key($check['ext']);
        if (!$files->put($key, $bytes, $check['mime'])) {
            return response()->json(['ok' => false, 'error' => 'Could not store the file: '.$files->lastError()], 422);
        }
        $row = $attachments->create($sessionId, $files->name(), $key, $check['name'], $check['mime'], strlen($bytes), 'agent');
        return response()->json(['ok' => true, 'attachment' => [
            'id' => (int) $row['id'], 'token' => (string) $row['token'], 'name' => (string) $row['name'],
            'mime' => (string) $row['mime'], 'size' => (int) $row['size'],
            'is_image' => \Banimark\Files\UploadPolicy::isImage((string) $row['mime']),
        ]]);
    }

    /** Live view: rows after a cursor + presence, polled by chat.js. */
    public function messages(Request $request, string $sessionId, PdoStore $store, AgentAuth $auth, \Banimark\Storage\Attachments $attachments)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if ($request->boolean('typing')) {
            $store->markTyping($sessionId, 'agent'); // the visitor's widget shows the dots
        }
        $store->markStaffSeen($sessionId);
        return response()->json([
            'ok' => true,
            'mode' => $store->mode($sessionId),
            'messages' => TranscriptView::rows($store->messagesSince($sessionId, (int) $request->query('after', 0)), $attachments),
            'presence' => $store->presence($sessionId),
        ]);
    }

    /** Staff alert feed: new visitor messages + handovers since the browser last asked. */
    public function events(Request $request, PdoStore $store, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        return response()->json($store->staffEvents((int) $request->query('since', 0)));
    }

    public function saveQuickReplies(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        DB::table('banimark_settings')->updateOrInsert(['key' => 'quick_replies'], ['value' => trim((string) $request->input('quick_replies'))]);
        return back()->with('bm_ok', 'Quick replies saved.');
    }

    /** Agent reply = takeover: the AI goes silent until handed back. */
    public function reply(Request $request, string $sessionId, PdoStore $store, AgentAuth $auth, \Banimark\Storage\Attachments $attachments)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $text = trim((string) $request->input('message', ''));
        $files = $attachments->pending((array) $request->input('attachments', []), $sessionId);
        if ($files !== []) {
            // files ride in the text as markers - the same shape the visitor's do
            $text = \Banimark\Files\Markers::append($text, $files);
            $attachments->markSent(array_column($files, 'token'), $sessionId);
        }
        $emailed = false;
        $row = null;
        if ($text !== '') {
            $store->appendAgentMessage($sessionId, $text, (int) $auth->id());
            $all = $store->transcript($sessionId);
            $row = $all === [] ? null : TranscriptView::row(end($all), $attachments);
            // the visitor may have closed the tab - post the reply on to them
            try {
                $settings = \Banimark\Laravel\BanimarkServiceProvider::settings();
                $emailed = (new \Banimark\Notify\FollowUp($store, app(\Banimark\Notify\Mailer::class), $settings))
                    ->afterAgentReply($sessionId, \Banimark\Files\Markers::parse($text)['text'] ?: 'Please see the attached file.');
            } catch (\Throwable $e) { /* a mail problem must never lose the reply */ }
        }
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['ok' => $text !== '', 'message' => $row, 'emailed' => $emailed, 'mode' => $store->mode($sessionId)]);
        }
        $redirect = redirect()->route('banimark.admin.conversation', $sessionId);
        return $emailed ? $redirect->with('bm_ok', 'Sent. The visitor had left the chat, so we emailed them your reply.') : $redirect;
    }

    public function setMode(Request $request, string $sessionId, PdoStore $store, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $mode = in_array($request->input('mode'), ['ai', 'agent', 'closed'], true) ? $request->input('mode') : 'ai';
        if ($mode === 'closed' && !$auth->can('inbox.close')) {
            return back()->with('bm_error', 'You do not have permission to close conversations.');
        }
        $store->setMode($sessionId, $mode);
        return redirect()->route('banimark.admin.conversation', $sessionId);
    }

    /* ---------------- providers ---------------- */

    public function providers(AgentAuth $auth, Request $request)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $editing = null;
        if (($slug = (string) $request->query('edit', '')) !== '') {
            $row = DB::table('banimark_providers')->where('slug', $slug)->first();
            // the key never round-trips to the form
            $editing = $row ? ['slug' => $row->slug, 'driver' => $row->driver, 'model' => $row->model, 'base_url' => (string) $row->base_url,
                'temperature' => (float) $row->temperature, 'enabled' => (bool) $row->enabled, 'has_key' => trim((string) $row->api_key) !== ''] : null;
        }
        $rows = [];
        foreach (DB::table('banimark_providers')->orderByDesc('enabled')->orderBy('id')->get() as $r) {
            $rows[] = ['slug' => (string) $r->slug, 'driver' => (string) $r->driver, 'model' => (string) $r->model, 'base_url' => (string) $r->base_url,
                'temperature' => $r->temperature, 'enabled' => (bool) $r->enabled, 'has_key' => trim((string) $r->api_key) !== ''];
        }
        return view('banimark::admin.providers', [
            // ONE body for both runtimes: Ui\Pages::providers
            'body' => \Banimark\Ui\Pages::providers($rows, $editing, [
                'csrf' => csrf_field()->toHtml(),
                'urls' => [
                    'save' => route('banimark.admin.providers.save'),
                    'activate' => route('banimark.admin.providers.activate'),
                    'delete' => route('banimark.admin.providers.delete'),
                    'page' => route('banimark.admin.providers'),
                ],
            ]),
        ]);
    }

    /** Exactly one provider answers the widget: switching one on switches the others off. */
    public function activateProvider(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $slug = (string) $request->input('slug');
        if (!DB::table('banimark_providers')->where('slug', $slug)->exists()) {
            return back()->with('bm_error', 'Unknown provider.');
        }
        DB::table('banimark_providers')->update(['enabled' => false, 'is_default' => false]);
        DB::table('banimark_providers')->where('slug', $slug)->update(['enabled' => true, 'is_default' => true]);
        return back()->with('bm_ok', '"'.$slug.'" is now the provider answering your chat.');
    }

    public function saveProvider(Request $request)
    {
        $data = [
            'slug' => strtolower(trim((string) $request->input('slug'))),
            'driver' => in_array($request->input('driver'), \Banimark\Ai\ProviderPresets::KNOWN_DRIVERS, true) ? $request->input('driver') : 'gemini',
            'model' => trim((string) $request->input('model')),
            // an address only means something to the OpenAI-compatible driver
            'base_url' => $request->input('driver') === 'openai-compat' ? (trim((string) $request->input('base_url')) ?: null) : null,
            'temperature' => max(0, min(2, (float) $request->input('temperature', 0.4))),
            'enabled' => (bool) $request->input('enabled'),
            'updated_at' => now(),
        ];
        if (!preg_match('/^[a-z0-9\-]+$/', $data['slug']) || $data['model'] === '') {
            return back()->with('bm_error', 'Slug (lowercase) and model are required.');
        }
        // only offered drivers and TESTED models - but what this provider already
        // had is kept as it was (ProviderPresets::refuse)
        $saved = DB::table('banimark_providers')->where('slug', $data['slug'])->first(['driver', 'model']);
        if ($why = \Banimark\Ai\ProviderPresets::refuse($data['driver'], $data['model'], (string) ($saved->driver ?? ''), (string) ($saved->model ?? ''))) {
            return back()->with('bm_error', $why);
        }
        // an empty key on edit keeps the stored one - keys never round-trip to the form
        $key = (string) $request->input('api_key', '');
        if ($key !== '') {
            $data['api_key'] = $key;
        } elseif (!DB::table('banimark_providers')->where('slug', $data['slug'])->exists()) {
            return back()->with('bm_error', 'An API key is required for a new provider.');
        }
        // ONE provider at a time: enabling this one disables every other
        $data['is_default'] = $data['enabled'];
        if ($data['enabled']) {
            DB::table('banimark_providers')->update(['enabled' => false, 'is_default' => false]);
        }
        DB::table('banimark_providers')->updateOrInsert(['slug' => $data['slug']], $data + ['created_at' => now()]);
        if (!DB::table('banimark_providers')->where('enabled', true)->exists()) {
            // never leave the chat without a provider when one exists to use
            $first = DB::table('banimark_providers')->orderBy('id')->value('slug');
            if ($first) { DB::table('banimark_providers')->where('slug', $first)->update(['enabled' => true, 'is_default' => true]); }
        }
        return redirect()->route('banimark.admin.providers')->with('bm_ok', 'Provider saved.'.($data['enabled'] ? ' It is now the one answering your chat.' : ''));
    }

    public function deleteProvider(Request $request)
    {
        DB::table('banimark_providers')->where('slug', (string) $request->input('slug'))->delete();
        return back()->with('bm_ok', 'Provider removed.');
    }

    /* ---------------- rules ---------------- */

    public function rules(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $rules = $this->rulesRepo();
        $rules->seedDefaults(); // desks installed before folders existed
        $url = \Banimark\Laravel\RouteCache::url('banimark.admin.rules.library');
        return view('banimark::admin.rules', [
            'folders' => $rules->tree(),
            'library' => $url === null ? null : ['installed' => (new \Banimark\Library\LibraryInstaller(DB::connection()->getPdo()))->installedRules(), 'url' => $url],
        ]);
    }

    /** The Tools page's templates section; absent (not broken) on a stale route cache. */
    private function toolLibrary(): ?array
    {
        $url = \Banimark\Laravel\RouteCache::url('banimark.admin.tools.template');
        if ($url === null) {
            return null;
        }
        $lib = new \Banimark\Library\LibraryInstaller(DB::connection()->getPdo());
        [$templates, $total] = $lib->toolCounts();
        return [
            'installed' => $lib->installedTemplates(),
            'allowance' => \Banimark\Licensing\Entitlements::templateAllowance($this->entitlements(), $templates, $total),
            'url' => $url,
            'edit' => fn (string $name) => route('banimark.admin.tools').'?edit='.rawurlencode($name).'#build',
        ];
    }

    public function installToolTemplate(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $out = (new \Banimark\Library\LibraryInstaller(DB::connection()->getPdo()))->installTool((string) $request->input('template', ''), $this->entitlements());
        return back()->with($out['ok'] ? 'bm_ok' : 'bm_error', $out['message']);
    }

    public function installRulePack(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $out = (new \Banimark\Library\LibraryInstaller(DB::connection()->getPdo()))->installRules(
            (string) $request->input('pack', ''), array_map('strval', (array) $request->input('rules', []))
        );
        return back()->with($out['ok'] ? 'bm_ok' : 'bm_error', $out['message']);
    }

    private function rulesRepo(): \Banimark\Storage\Rules
    {
        return new \Banimark\Storage\Rules(DB::connection()->getPdo());
    }

    public function saveFolder(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $title = trim((string) $request->input('title'));
        if ($title === '') { return back()->with('bm_error', 'A folder needs a name.'); }
        $id = (int) $request->input('id', 0);
        $id > 0
            ? $this->rulesRepo()->updateFolder($id, $title, (string) $request->input('description', ''), $request->boolean('enabled'))
            : $this->rulesRepo()->createFolder($title, (string) $request->input('description', ''));
        return back()->with('bm_ok', $id > 0 ? 'Folder updated.' : 'Folder added.');
    }

    public function deleteFolder(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $this->rulesRepo()->deleteFolder((int) $request->input('id'));
        return back()->with('bm_ok', 'Folder and its rules removed.');
    }

    public function moveFolder(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $this->rulesRepo()->moveFolder((int) $request->input('id'), (int) $request->input('direction', 1));
        return back();
    }

    public function moveRule(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $this->rulesRepo()->moveRule((int) $request->input('id'), (int) $request->input('direction', 1));
        return back();
    }

    public function saveRule(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $content = trim((string) $request->input('content'));
        if ($content === '') { return back()->with('bm_error', 'A rule needs some content.'); }
        $id = (int) $request->input('id', 0);
        if ($id > 0) {
            $this->rulesRepo()->updateRule($id, (string) $request->input('title', ''), $content, $request->boolean('enabled'));
            return back()->with('bm_ok', 'Rule updated.');
        }
        $folder = (int) $request->input('folder_id', 0);
        if ($folder <= 0) { return back()->with('bm_error', 'Pick a folder for the rule.'); }
        $this->rulesRepo()->addRule($folder, (string) $request->input('title', ''), $content);
        return back()->with('bm_ok', 'Rule added.');
    }

    public function deleteRule(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $this->rulesRepo()->deleteRule((int) $request->input('id'));
        return back()->with('bm_ok', 'Rule removed.');
    }

    /* ---------------- tool builder ---------------- */

    public function tools(AgentAuth $auth, Request $request)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $editing = null;
        if (($name = (string) $request->query('edit', '')) !== '') {
            $row = DB::table('banimark_tools')->where('name', $name)->first();
            if ($row) {
                $params = [];
                foreach ((array) (json_decode($row->parameters, true) ?: []) as $pn => $spec) {
                    $params[] = ['name' => $pn, 'type' => $spec['type'] ?? 'string', 'desc' => $spec['description'] ?? '', 'required' => !empty($spec['required'])];
                }
                $config = json_decode((string) ($row->config ?? ''), true) ?: [];
                $editing = ['name' => $row->name, 'description' => $row->description, 'sql' => $row->sql, 'max_rows' => (int) $row->max_rows,
                    'columns' => implode(', ', json_decode($row->columns, true) ?: []), 'context' => implode(', ', json_decode((string) $row->context, true) ?: []),
                    'enabled' => (bool) $row->enabled, 'params' => $params,
                    'kind' => $row->kind ?? 'sql',
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
        foreach (DB::table('banimark_tools')->orderBy('id')->get() as $r) {
            $rows[] = ['name' => (string) $r->name, 'description' => (string) $r->description, 'sql' => (string) $r->sql,
                'params' => array_keys(json_decode((string) $r->parameters, true) ?: []), 'max_rows' => (int) $r->max_rows, 'enabled' => (bool) $r->enabled];
        }
        // form values: what was just submitted (a refused save comes back filled
        // in), else the tool being edited, else a blank new tool
        $e = $editing ?? [];
        $keys = ['name', 'max_rows', 'description', 'kind', 'sql', 'http_method', 'http_url', 'http_headers', 'http_body', 'http_path', 'http_fields',
            'http_auth', 'http_auth_header', 'http_auth_user', 'http_auth_issuer', 'http_auth_audience', 'http_auth_ttl'];
        $form = ['params' => $e['params'] ?? [], 'http_auth_has_secret' => $e['http_auth_has_secret'] ?? false,
            'enabled' => old('enabled', $e ? $e['enabled'] : true),
            'columns_csv' => old('columns', $e['columns'] ?? ''), 'context_csv' => old('context', $e['context'] ?? 'user_id')];
        foreach ($keys as $k) {
            if (($val = old($k, $e[$k] ?? null)) !== null) {
                $form[$k] = $val;
            }
        }
        $settings = \Banimark\Laravel\BanimarkServiceProvider::settings();
        return view('banimark::admin.tools', [
            // ONE body for both runtimes: Ui\Pages::tools
            'body' => \Banimark\Ui\Pages::tools($rows, $form, [
                'editing' => $editing !== null,
                'csrf' => csrf_field()->toHtml(),
                // how many tools the plan covers. Editing an existing one is never
                // blocked - only adding the next one.
                'allowance' => \Banimark\Licensing\Entitlements::allowance($this->entitlements(), 'tools', count($rows)),
                ...(($lib = $this->toolLibrary()) !== null ? ['library' => $lib] : []),
                'upgrade_url' => (string) ($settings['support_url'] ?? ''),
                'assist_ready' => \Banimark\Laravel\EngineFactory::driver()[0] !== null,
                'data_card' => \Banimark\Ui\Layout::dataConnection($settings, route('banimark.admin.tools.data'), route('banimark.admin.tools.data.test'), csrf_field()->toHtml(),
                    'Banimark reads the tables next to its own: '.(DB::connection()->getDatabaseName() ?: 'this application\'s database').'.',
                    (string) session('bm_data_test', ''), (bool) session('bm_data_ok')),
                'urls' => [
                    'save' => route('banimark.admin.tools.save'),
                    'delete' => route('banimark.admin.tools.delete'),
                    'page' => route('banimark.admin.tools'),
                    'schema' => route('banimark.admin.tools.schema'),
                    'try' => route('banimark.admin.tools.try'),
                    'assist' => route('banimark.admin.tools.assist'),
                    'providers' => route('banimark.admin.providers'),
                ],
            ]),
        ]);
    }

    public function saveDataConnection(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        \Banimark\Storage\DataSource::save($request->all(), fn (string $k, string $v) => self::setSetting($k, $v));
        return back()->with('bm_ok', 'Saved. Test the connection to be sure it works.');
    }

    public function testDataConnection(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        // save first: testing what is on screen is what the owner expects
        \Banimark\Storage\DataSource::save($request->all(), fn (string $k, string $v) => self::setSetting($k, $v));
        $result = \Banimark\Storage\DataSource::check(\Banimark\Laravel\BanimarkServiceProvider::settings(), DB::connection()->getPdo());
        return back()->with('bm_data_test', $result['message'])->with('bm_data_ok', $result['ok']);
    }

    /**
     * The Tool Builder's assistant: the owner says what they want in plain
     * words, their own AI provider drafts it, and the answer fills the form.
     * The model is shown table and column NAMES only - never a row - and the
     * draft is validated here before it is handed back.
     */
    public function assistTool(Request $request, AgentAuth $auth, \Banimark\Http\RateLimiter $limiter)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if ($limiter->hit('tool-assist', 3600) > self::ASSIST_PER_HOUR) {
            return response()->json(['ok' => false, 'error' => 'That is a lot of drafting in one hour. Give it a few minutes - every question costs a call to your AI provider.'], 429);
        }
        [$driver, $slug] = \Banimark\Laravel\EngineFactory::driver();
        if ($driver === null) {
            return response()->json(['ok' => false, 'error' => 'Connect an AI provider first - the assistant uses the same key your chat does.'], 422);
        }
        try {
            $schema = (new \Banimark\Tools\SchemaInspector(\Banimark\Laravel\EngineFactory::dataPdo()))->all();
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Your data connection is not working: '.$e->getMessage()], 422);
        }
        $known = [];
        $existing = [];
        foreach (DB::table('banimark_tools')->get(['name', 'context']) as $row) {
            $existing[] = (string) $row->name;
            foreach ((array) (json_decode((string) $row->context, true) ?: []) as $k) {
                $known[(string) $k] = true;
            }
        }
        $out = (new \Banimark\Tools\ToolDraftsman($driver))->draft(
            self::assistTurns((array) $request->input('turns', [])),
            $schema,
            array_keys($known),
            $existing,
            \Banimark\Storage\Dialect::of(\Banimark\Laravel\EngineFactory::dataPdo()),
        );
        if (!$out['ok']) {
            \Illuminate\Support\Facades\Log::warning('Banimark tool assistant: '.$out['error']);
            return response()->json(['ok' => false, 'error' => \Banimark\Tools\ToolDraftsman::explain($out['error'], $slug)], 422);
        }
        return response()->json(['ok' => true, 'reply' => $out['reply'], 'tool' => $out['tool']]);
    }

    /** No more than this many drafting questions an hour, across the desk. */
    private const ASSIST_PER_HOUR = 60;

    /** @return array<int, array{role: string, text: string}> */
    private static function assistTurns(array $turns): array
    {
        $out = [];
        foreach (array_slice($turns, -12) as $t) { // a long ramble is not worth paying for
            $text = trim((string) ($t['text'] ?? ''));
            if ($text !== '') {
                $out[] = ['role' => ($t['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'text' => mb_substr($text, 0, 2000)];
            }
        }
        return $out;
    }

    /**
     * "Try it" - run the definition in the form with sample values, the way the
     * engine would. Read-only by construction (the validator only passes SELECT).
     */
    public function tryTool(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $definition = self::toolDefinitionFromRequest($request);
        $args = (array) $request->input('args', []);
        $context = array_filter((array) $request->input('context_values', []), fn ($v) => $v !== '' && $v !== null);
        try {
            $runner = \Banimark\Storage\DataSource::runner(\Banimark\Laravel\EngineFactory::dataPdo());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Your data connection is not working: '.$e->getMessage(), 'diagnostic' => '', 'rows' => [], 'count' => 0, 'needs' => [], 'sql' => ''], 422);
        }
        return response()->json(\Banimark\Tools\ToolTester::run($definition, $args, $context, $runner));
    }

    /** Tables + columns for the visual builder. Owner-gated like every admin route. */
    public function toolSchema(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        try {
            $tables = (new \Banimark\Tools\SchemaInspector(\Banimark\Laravel\EngineFactory::dataPdo()))->all();
        } catch (\Throwable $e) {
            return response()->json(['tables' => [], 'error' => 'Your data connection is not working: '.$e->getMessage()]);
        }
        return response()->json(['tables' => $tables]);
    }

    /** The auth block already saved for the tool being edited, if any. */
    private static function storedAuth(Request $request): array
    {
        $name = trim((string) ($request->input('original_name') ?: $request->input('name')));
        if ($name === '') {
            return [];
        }
        $row = DB::table('banimark_tools')->where('name', $name)->first();
        return (array) ((json_decode((string) ($row->config ?? ''), true) ?: [])['auth'] ?? []);
    }

    /** The tool definition as typed into the form - shared by save and "Try it". */
    private static function toolDefinitionFromRequest(Request $request): array
    {
        // rows arrive as positional arrays; a checkbox only posts when ticked,
        // so param_required[] carries the INDEXES of the required rows
        $names = array_values((array) $request->input('param_name', []));
        $types = array_values((array) $request->input('param_type', []));
        $descs = array_values((array) $request->input('param_desc', []));
        $required = array_map('intval', (array) $request->input('param_required', []));
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
            'name' => strtolower(trim((string) $request->input('name'))),
            'description' => trim((string) $request->input('description')),
            'parameters' => $params,
            'sql' => trim((string) $request->input('sql')),
            'columns' => array_values(array_filter(array_map('trim', explode(',', (string) $request->input('columns'))))),
            'context' => array_values(array_filter(array_map('trim', explode(',', (string) $request->input('context'))))),
            'max_rows' => max(1, min(50, (int) $request->input('max_rows', 10))),
            'kind' => $request->input('kind') === 'http' ? 'http' : 'sql',
            'config' => [
                'method' => strtoupper((string) $request->input('http_method', 'GET')) === 'POST' ? 'POST' : 'GET',
                'url' => trim((string) $request->input('http_url')),
                'headers' => \Banimark\Tools\ToolFactory::parseHeaders((string) $request->input('http_headers')),
                // a blank secret box means "keep what is stored" - the panel
                // never renders a stored secret back into the page
                'auth' => \Banimark\Tools\HttpAuth::fromForm($request->all(), self::storedAuth($request)),
                'body' => trim((string) $request->input('http_body')),
                'path' => trim((string) $request->input('http_path')),
                'fields' => array_values(array_filter(array_map('trim', explode(',', (string) $request->input('http_fields'))))),
            ],
        ];
    }

    public function saveTool(Request $request)
    {
        $definition = self::toolDefinitionFromRequest($request);

        // only when this would be a NEW tool; editing one you already have is
        // never blocked, whatever the plan says
        if ((string) $request->input('original_name', '') === '') {
            $refuse = \Banimark\Licensing\Entitlements::refuseTool(
                $this->entitlements(), (int) DB::table('banimark_tools')->count()
            );
            if ($refuse !== null) {
                return back()->with('bm_error', $refuse)->withInput();
            }
        }

        // the same compile-time gate the runtime uses - a bad tool dies HERE
        try {
            \Banimark\Tools\ToolFactory::make($definition, fn () => [], fn () => ['status' => 200, 'body' => '[]', 'error' => '']);
        } catch (\Throwable $e) {
            return back()->with('bm_error', $e->getMessage())->withInput();
        }

        // editing: original_name is the row to update, name may be a rename
        $original = trim((string) $request->input('original_name', ''));
        $target = $original !== '' && DB::table('banimark_tools')->where('name', $original)->exists() ? $original : $definition['name'];
        if ($target !== $definition['name'] && DB::table('banimark_tools')->where('name', $definition['name'])->exists()) {
            return back()->with('bm_error', 'A tool called "'.$definition['name'].'" already exists.')->withInput();
        }
        $data = [
            'name' => $definition['name'],
            'description' => $definition['description'],
            'parameters' => json_encode($definition['parameters']),
            'sql' => $definition['sql'],
            'columns' => json_encode($definition['columns']),
            'context' => json_encode($definition['context']),
            'max_rows' => $definition['max_rows'],
            'kind' => $definition['kind'],
            'config' => $definition['kind'] === 'http' ? json_encode($definition['config']) : null,
            'enabled' => $request->boolean('enabled'),
            'updated_at' => now(),
        ];
        DB::table('banimark_tools')->where('name', $target)->exists()
            ? DB::table('banimark_tools')->where('name', $target)->update($data)
            : DB::table('banimark_tools')->insert($data + ['created_at' => now()]);
        return redirect()->route('banimark.admin.tools')->with('bm_ok', 'Tool "'.$definition['name'].'" '.($original !== '' ? 'updated' : 'saved').' and validated.');
    }

    public function deleteTool(Request $request)
    {
        DB::table('banimark_tools')->where('name', (string) $request->input('name'))->delete();
        return back()->with('bm_ok', 'Tool removed.');
    }

    /* ---------------- widget settings ---------------- */

    public function widget(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $settings = DB::table('banimark_settings')->pluck('value', 'key')->all();
        $updates = $this->updates($settings);
        $cfg = array_merge((array) config('banimark.widget', []), $settings);
        $locks = \Banimark\Licensing\Entitlements::locks($this->entitlements());
        return view('banimark::admin.widget', [
            // ONE body for both runtimes: Ui\Pages::widget
            'body' => \Banimark\Ui\Pages::widget($cfg, [
                'csrf' => csrf_field()->toHtml(),
                'save_url' => route('banimark.admin.widget.save'),
                'widget_js' => url('banimark/widget.js'),
                'chat_page_url' => route('banimark.chat.page'),
                'try_url' => route('banimark.admin.widget.try'),
                'token_snippet' => "\$token = \\Banimark\\Identity\\VisitorToken::mint(\n    ['user_id' => auth()->id()],\n    config('banimark.identity_secret')\n);",
                'flutter' => $updates['sdks']['flutter'] ?? null, // advertised by HQ; null = not published yet
                'flutter_lock' => $locks['flutter'],
                'whitelabel_lock' => $locks['whitelabel'],
                'upgrade_url' => (string) ($settings['support_url'] ?? ''),
                'support_email' => (string) ($settings['support_email'] ?? ''),
                'flutter_config' => "BanimarkChat(\n  config: BanimarkConfig.laravel('".url('/')."', token: userToken), // token: mint it server-side like the widget's data-token; null = guest\n  theme: BanimarkTheme.fromScheme(Theme.of(context).colorScheme)\n      .copyWith(title: '".str_replace("'", "\\'", (string) ($cfg['title'] ?? 'Support'))."'),\n)",
            ]),
        ]);
    }

    public function saveWidget(Request $request, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $allowed = [
            'color' => fn ($v) => preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : '#6F04D9',
            'position' => fn ($v) => $v === 'left' ? 'left' : 'right',
            'title' => fn ($v) => mb_substr(trim($v), 0, 60) ?: 'Support',
            'greeting' => fn ($v) => mb_substr(trim($v), 0, 300),
            // clamped here AND in WidgetConfig - a silly value would become a
            // request storm against the host's own server
            'poll_seconds' => fn ($v) => (string) max(3, min(600, (int) $v ?: 10)),
            'poll_idle_seconds' => fn ($v) => (string) max(10, min(600, (int) $v ?: 30)),
            'launcher_reappear_minutes' => fn ($v) => (string) max(0, min(1440, $v === '' ? 10 : (int) $v)),
            'guest_mode' => fn ($v) => in_array($v, ['off', 'optional', 'required'], true) ? $v : 'off',
            'offline_note' => fn ($v) => mb_substr(trim($v), 0, 200),
            // auto follows the visitor's OS; light/dark force it (Flutter reads the same value)
            'theme' => fn ($v) => in_array($v, ['auto', 'light', 'dark'], true) ? $v : 'auto',
        ];
        foreach ($allowed as $key => $clean) {
            DB::table('banimark_settings')->updateOrInsert(['key' => $key], ['value' => $clean((string) $request->input($key, ''))]);
        }
        // whitelabel is a plan feature. When it is NOT covered the stored value
        // is left exactly as it was: saving the widget's colour must never put
        // our name back on a site that had already removed it.
        if (\Banimark\Licensing\Entitlements::locked($this->entitlements(), 'whitelabel') === null) {
            self::setSetting('hide_brand', $request->boolean('hide_brand') ? '1' : '0');
        }
        // the first-run screen posts from the same form
        \Banimark\Ui\Pages::saveFirstRun($request->all(), fn (string $k, string $v) => self::setSetting($k, $v));
        $file = $request->file('logo_file');
        // read one byte past the cap: enough to say "too large", never a whole huge file
        $upload = ($file && $file->isValid()) ? (string) file_get_contents($file->getRealPath(), false, null, 0, \Banimark\Http\WidgetLogo::MAX_BYTES + 1) : null;
        $logoProblem = \Banimark\Ui\Pages::saveLook($request->all(), fn (string $k, string $v) => self::setSetting($k, $v), $upload);
        if ($logoProblem !== null) {
            return back()->with('bm_error', 'Everything else was saved, but not the logo: '.$logoProblem);
        }
        return back()->with('bm_ok', 'Widget saved. The embed script serves the new settings within 5 minutes (cache).');
    }

    /** GET <admin>/widget/try - a sample page carrying the real widget. */
    public function tryWidget(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        return response(\Banimark\Ui\Pages::widgetTryPage(route('banimark.widget'), route('banimark.admin.widget')), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
