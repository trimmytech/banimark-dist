<?php

namespace Banimark\Laravel\Admin;

use Banimark\Auth\AgentAuth;
use Banimark\Auth\Agents;
use Banimark\Auth\Totp;
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
        return view('banimark::admin.agents', [
            'rows' => $agents->all(), 'isOwner' => $auth->isOwner(), 'meId' => (int) $auth->id(),
            'require2fa' => (\Banimark\Laravel\BanimarkServiceProvider::settings()['require_2fa'] ?? '0') === '1',
        ]);
    }

    public function saveAgent(Request $request, AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if (!$auth->isOwner()) { return back()->with('bm_error', 'Only an owner can add staff.'); }
        if (strlen((string) $request->input('password')) < 8 || !filter_var($request->input('email'), FILTER_VALIDATE_EMAIL)) {
            return back()->with('bm_error', 'A valid email and an 8+ character password are required.');
        }
        $ok = $agents->create((string) $request->input('name'), (string) $request->input('email'),
            (string) $request->input('password'), $request->input('role') === 'owner' ? 'owner' : 'agent');
        return back()->with($ok === false ? 'bm_error' : 'bm_ok', $ok === false ? 'That email is already a staff account.' : 'Staff account added.');
    }

    public function deleteAgent(Request $request, AgentAuth $auth, Agents $agents)
    {
        if ($r = $this->gate($auth)) { return $r; }
        if ($auth->isOwner()) { $agents->delete((int) $request->input('id')); }
        return back()->with('bm_ok', 'Staff removed.');
    }

    /* ---------------- licensing ---------------- */

    /**
     * Daily, fail-open licensing ping - admin landing only, NEVER the chat
     * path. Reads env directly as the fallback so a stale published config
     * (no 'license' block) still works. Stamped BEFORE the request so an
     * unreachable HQ cannot slow the inbox for a day.
     */
    private function maybePhoneHome(Request $request): void
    {
        try {
            $s = $this->licenseSettings();
            $key = $this->licenseKey($s);
            if ($key === '') { return; }
            if (!Master::due(isset($s['license_last_ping']) ? (string) $s['license_last_ping'] : null)) { return; }
            DB::table('banimark_settings')->updateOrInsert(['key' => 'license_last_ping'], ['value' => (string) time()]);
            $result = (new Master($this->hqEndpoint($s), $key, $request->getSchemeAndHttpHost()))->ping();
            DB::table('banimark_settings')->updateOrInsert(['key' => 'license_status'], ['value' => $result['license']]);
            if (($result['support_email'] ?? '') !== '') {
                DB::table('banimark_settings')->updateOrInsert(['key' => 'support_email'], ['value' => (string) $result['support_email']]);
            }
            if (($result['token'] ?? '') !== '') {
                DB::table('banimark_settings')->updateOrInsert(['key' => 'license_token'], ['value' => (string) $result['token']]);
            }
        } catch (\Throwable $e) {
            // licensing must never break the panel
        }
    }

    /** @return array<string, string> the license_* / hq_url settings rows */
    private function licenseSettings(): array
    {
        return DB::table('banimark_settings')
            ->whereIn('key', ['license_key', 'license_status', 'license_last_ping', 'license_token', 'hq_url', 'updates_cache', 'updates_checked_at', 'support_email'])
            ->pluck('value', 'key')->all();
    }

    /** Panel-entered key first, then env/config - so a stale published config
     *  or no .env access never blocks entering a key. */
    private function licenseKey(array $s): string
    {
        return trim((string) (($s['license_key'] ?? '') !== '' ? $s['license_key']
            : (config('banimark.license.key') ?? env('BANIMARK_LICENSE_KEY', ''))));
    }

    private function hqEndpoint(array $s): string
    {
        return (string) ((($s['hq_url'] ?? '') !== '' ? $s['hq_url']
            : (config('banimark.license.hq_url') ?? env('BANIMARK_HQ_URL', ''))) ?: Master::DEFAULT_ENDPOINT);
    }

    public function license(AgentAuth $auth)
    {
        if ($r = $this->gate($auth, false)) { return $r; }
        if (!$auth->isOwner()) {
            // staff never touch licensing; while locked they can only sign out
            return $auth->lockReason() ? view('banimark::admin.locked-staff') : redirect()->route('banimark.admin.dashboard');
        }
        $s = $this->licenseSettings();
        $key = $this->licenseKey($s);
        $status = (string) ($s['license_status'] ?? '');
        $verdict = Master::verify($key, (string) ($s['license_token'] ?? ''), null, (string) request()->getHost());
        return view('banimark::admin.license', [
            // an ACTIVE key is read-only: swapping it is how a licence walks to
            // another install. Editable again the moment it is expired/revoked.
            'keyLocked' => $status === 'active' && $auth->lockReason() === null,
            'supportEmail' => (string) ($s['support_email'] ?? ''),
            'lock' => $auth->lockReason(),
            'modules' => $verdict['modules'] ?? [],
            'key' => $key,
            'hqUrl' => (string) ($s['hq_url'] ?? ''),
            'status' => $status,
            'lastPing' => (int) ($s['license_last_ping'] ?? 0),
        ]);
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
                    \Banimark\Update\UpdateCheck::endpointFrom($this->hqEndpoint($s))
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
        return view('banimark::admin.changelog', ['updates' => $this->updates($this->licenseSettings())]);
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
        $result = (new Master($this->hqEndpoint($this->licenseSettings()), $key, $request->getSchemeAndHttpHost()))->ping();
        DB::table('banimark_settings')->updateOrInsert(['key' => 'license_last_ping'], ['value' => (string) time()]);
        DB::table('banimark_settings')->updateOrInsert(['key' => 'license_status'], ['value' => $result['license']]);
        DB::table('banimark_settings')->updateOrInsert(['key' => 'license_token'], ['value' => (string) ($result['token'] ?? '')]);
        if (($result['support_email'] ?? '') !== '') {
            DB::table('banimark_settings')->updateOrInsert(['key' => 'support_email'], ['value' => (string) $result['support_email']]);
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

    public function dashboard(Request $request, AgentAuth $auth, PdoStore $store)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $this->maybePhoneHome($request);
        $analytics = new Analytics(DB::connection()->getPdo());
        return view('banimark::admin.dashboard', [
            'a' => $analytics->overview(),
            'recent' => $store->listConversations(6),
            'name' => $auth->name(),
        ]);
    }

    /* ---------------- inbox / live chat ---------------- */

    public function inbox(Request $request, PdoStore $store, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $mode = in_array($request->query('mode'), ['ai', 'agent', 'closed'], true) ? $request->query('mode') : null;
        return view('banimark::admin.inbox', [
            'rows' => $store->listConversations(100, $mode),
            'mode' => $mode,
        ]);
    }

    public function conversation(string $sessionId, PdoStore $store, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $rows = TranscriptView::rows($store->transcript($sessionId));
        return view('banimark::admin.conversation', [
            'sessionId' => $sessionId,
            'mode' => $store->mode($sessionId),
            'rows' => $rows,
            'lastId' => $rows === [] ? 0 : end($rows)['id'],
            'presence' => $store->presence($sessionId),
            'quick' => QuickReplies::fromSettings(\Banimark\Laravel\BanimarkServiceProvider::settings()),
        ]);
    }

    /** Live view: rows after a cursor + presence, polled by chat.js. */
    public function messages(Request $request, string $sessionId, PdoStore $store, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        return response()->json([
            'ok' => true,
            'mode' => $store->mode($sessionId),
            'messages' => TranscriptView::rows($store->messagesSince($sessionId, (int) $request->query('after', 0))),
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
    public function reply(Request $request, string $sessionId, PdoStore $store, AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        $text = trim((string) $request->input('message', ''));
        $emailed = false;
        $row = null;
        if ($text !== '') {
            $store->appendAgentMessage($sessionId, $text);
            $all = $store->transcript($sessionId);
            $row = $all === [] ? null : TranscriptView::row(end($all));
            // the visitor may have closed the tab - post the reply on to them
            try {
                $settings = \Banimark\Laravel\BanimarkServiceProvider::settings();
                $emailed = (new \Banimark\Notify\FollowUp($store, app(\Banimark\Notify\Mailer::class), $settings))
                    ->afterAgentReply($sessionId, $text);
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
        $store->setMode($sessionId, $mode);
        return redirect()->route('banimark.admin.conversation', $sessionId);
    }

    /* ---------------- providers ---------------- */

    public function providers(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        return view('banimark::admin.providers', [
            'rows' => DB::table('banimark_providers')->orderBy('id')->get(),
        ]);
    }

    public function saveProvider(Request $request)
    {
        $data = [
            'slug' => strtolower(trim((string) $request->input('slug'))),
            'driver' => in_array($request->input('driver'), ['gemini', 'openai-compat', 'anthropic'], true) ? $request->input('driver') : 'openai-compat',
            'model' => trim((string) $request->input('model')),
            'base_url' => trim((string) $request->input('base_url')) ?: null,
            'temperature' => max(0, min(2, (float) $request->input('temperature', 0.4))),
            'enabled' => (bool) $request->input('enabled'),
            'updated_at' => now(),
        ];
        if (!preg_match('/^[a-z0-9\-]+$/', $data['slug']) || $data['model'] === '') {
            return back()->with('bm_error', 'Slug (lowercase) and model are required.');
        }
        // an empty key on edit keeps the stored one - keys never round-trip to the form
        $key = (string) $request->input('api_key', '');
        if ($key !== '') {
            $data['api_key'] = $key;
        } elseif (!DB::table('banimark_providers')->where('slug', $data['slug'])->exists()) {
            return back()->with('bm_error', 'An API key is required for a new provider.');
        }
        DB::table('banimark_providers')->updateOrInsert(['slug' => $data['slug']], $data + ['created_at' => now()]);
        if ($request->input('is_default')) {
            DB::table('banimark_providers')->update(['is_default' => false]);
            DB::table('banimark_providers')->where('slug', $data['slug'])->update(['is_default' => true]);
        }
        return back()->with('bm_ok', 'Provider saved.');
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
        return view('banimark::admin.rules', ['folders' => $rules->tree()]);
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

    public function tools(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        return view('banimark::admin.tools', [
            'rows' => DB::table('banimark_tools')->orderBy('id')->get(),
        ]);
    }

    /** Tables + columns for the visual builder. Owner-gated like every admin route. */
    public function toolSchema(AgentAuth $auth)
    {
        if ($r = $this->gate($auth)) { return $r; }
        try {
            $tables = (new \Banimark\Tools\SchemaInspector(DB::connection()->getPdo()))->all();
        } catch (\Throwable $e) {
            $tables = [];
        }
        return response()->json(['tables' => $tables]);
    }

    public function saveTool(Request $request)
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
        $definition = [
            'name' => strtolower(trim((string) $request->input('name'))),
            'description' => trim((string) $request->input('description')),
            'parameters' => $params,
            'sql' => trim((string) $request->input('sql')),
            'columns' => array_values(array_filter(array_map('trim', explode(',', (string) $request->input('columns'))))),
            'context' => array_values(array_filter(array_map('trim', explode(',', (string) $request->input('context'))))),
            'max_rows' => max(1, min(50, (int) $request->input('max_rows', 10))),
        ];

        // the same compile-time gate the runtime uses - bad SQL dies HERE
        try {
            SqlTool::fromDefinition($definition, fn () => []);
        } catch (\Throwable $e) {
            return back()->with('bm_error', $e->getMessage())->withInput();
        }

        DB::table('banimark_tools')->updateOrInsert(['name' => $definition['name']], [
            'description' => $definition['description'],
            'parameters' => json_encode($definition['parameters']),
            'sql' => $definition['sql'],
            'columns' => json_encode($definition['columns']),
            'context' => json_encode($definition['context']),
            'max_rows' => $definition['max_rows'],
            'enabled' => (bool) $request->input('enabled', true),
            'updated_at' => now(), 'created_at' => now(),
        ]);
        return back()->with('bm_ok', 'Tool "'.$definition['name'].'" saved and validated.');
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
        return view('banimark::admin.widget', [
            'cfg' => array_merge((array) config('banimark.widget', []), $settings),
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
            'guest_mode' => fn ($v) => in_array($v, ['off', 'optional', 'required'], true) ? $v : 'off',
            'offline_note' => fn ($v) => mb_substr(trim($v), 0, 200),
        ];
        foreach ($allowed as $key => $clean) {
            DB::table('banimark_settings')->updateOrInsert(['key' => $key], ['value' => $clean((string) $request->input($key, ''))]);
        }
        return back()->with('bm_ok', 'Widget saved. The embed script serves the new settings within 5 minutes (cache).');
    }
}
