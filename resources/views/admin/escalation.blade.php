@php use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Notifications')
@section('sub', 'Escalation alerts, outgoing email, and visitor follow-ups')
@section('content')
<div class="bm-grid c2">
    <div>
        <div class="bm-card">
            <h2>When the AI hands over</h2>
            <div class="muted">What should happen the moment a conversation needs a human.</div>
            <form method="post" action="{{ route('banimark.admin.escalation.save') }}" id="notify-form">
                @csrf
                <label style="display:flex;gap:10px;align-items:flex-start;padding:13px;border:1px solid var(--border-2);border-radius:var(--r);margin:14px 0 10px;cursor:pointer">
                    <input type="radio" name="escalation_mode" value="staff" @checked($mode !== 'email') style="margin-top:2px">
                    <span><b style="color:var(--text)">Staff inbox</b>
                        <div class="muted">It appears in the inbox for any staff member to pick up. (Default)</div></span>
                </label>
                <label style="display:flex;gap:10px;align-items:flex-start;padding:13px;border:1px solid var(--border-2);border-radius:var(--r);cursor:pointer">
                    <input type="radio" name="escalation_mode" value="email" @checked($mode === 'email') style="margin-top:2px">
                    <span><b style="color:var(--text)">Email alert</b>
                        <div class="muted">Email your team as well. Needs the SMTP settings alongside.</div></span>
                </label>
                <label>Alert these addresses <span class="muted">(comma separated — blank means all staff)</span></label>
                <input type="text" name="escalation_email" value="{{ $email }}" placeholder="support@yourco.com, ops@yourco.com">

                <div class="divider"></div>
                <h2>Visitor follow-up</h2>
                <div class="muted">The widget reports whether the visitor is still watching. If they have closed the tab when an agent replies, we can email the reply to them — provided we have their address.</div>
                <label style="display:flex;align-items:center;gap:9px;margin-top:14px">
                    <span class="switch"><input type="checkbox" name="visitor_followup" value="1" @checked(($s['visitor_followup'] ?? '1') === '1')><span class="sl"></span></span>
                    <span>Email the visitor when they have left the chat</span>
                </label>
                <label>Consider them gone after</label>
                <div class="row">
                    <input type="number" name="visitor_followup_after" min="30" step="30" value="{{ $s['visitor_followup_after'] ?? 120 }}" style="max-width:140px">
                    <span class="muted">seconds without a heartbeat</span>
                </div>
                <div class="hint">One email per absence — they will not be mailed again until they come back.</div>

                <div style="margin-top:18px"><button type="submit">Save settings</button></div>
            </form>
        </div>
    </div>

    <div>
        <div class="bm-card">
            <div class="bm-sec-h">
                <div><h2>Outgoing email (SMTP)</h2>
                    <div class="muted">Banimark sends with its own SMTP settings, so it never depends on the host app's mail config.</div>
                </div>
            </div>
            <label style="display:flex;align-items:center;gap:9px;margin-top:6px">
                <span class="switch"><input type="checkbox" name="smtp_enabled" value="1" form="notify-form" @checked(($s['smtp_enabled'] ?? '') === '1')><span class="sl"></span></span>
                <span>Use SMTP <span class="muted">(otherwise PHP mail(), which most cloud hosts silently drop)</span></span>
            </label>
            <div class="grid2">
                <div><label>Host</label><input type="text" name="smtp_host" form="notify-form" value="{{ $s['smtp_host'] ?? '' }}" placeholder="smtp.mailgun.org"></div>
                <div><label>Port</label><input type="number" name="smtp_port" form="notify-form" value="{{ $s['smtp_port'] ?? 587 }}"></div>
                <div><label>Username</label><input type="text" name="smtp_user" form="notify-form" value="{{ $s['smtp_user'] ?? '' }}" autocomplete="off"></div>
                <div><label>Password</label><input type="password" name="smtp_pass" form="notify-form" autocomplete="new-password" placeholder="{{ $hasSmtpPass ? '•••••••• (unchanged)' : '' }}"></div>
                <div><label>Encryption</label>
                    <select name="smtp_encryption" form="notify-form">
                        <option value="tls" @selected(($s['smtp_encryption'] ?? 'tls') === 'tls')>STARTTLS (587)</option>
                        <option value="ssl" @selected(($s['smtp_encryption'] ?? '') === 'ssl')>SSL/TLS (465)</option>
                        <option value="none" @selected(($s['smtp_encryption'] ?? '') === 'none')>None (25)</option>
                    </select>
                </div>
                <div><label>From name</label><input type="text" name="smtp_from_name" form="notify-form" value="{{ $s['smtp_from_name'] ?? 'Support' }}"></div>
            </div>
            <label>From address</label>
            <input type="text" name="smtp_from_email" form="notify-form" value="{{ $s['smtp_from_email'] ?? '' }}" placeholder="support@yourco.com">
            <div class="hint">Leave the password blank to keep the stored one.</div>
        </div>

        <div class="bm-card">
            <h2>Send a test</h2>
            <div class="muted">Confirm the details work before an escalation depends on them.</div>
            <form method="post" action="{{ route('banimark.admin.escalation.test') }}">
                @csrf
                <div class="row" style="margin-top:12px">
                    <input type="text" name="test_email" placeholder="you@yourco.com" style="flex:1">
                    <button type="submit" class="btn2">{!! Icons::get('send', 15) !!} Send test</button>
                </div>
            </form>
            <div class="hint">Save your settings first — the test uses what is stored.</div>
        </div>
    </div>
</div>
@endsection
