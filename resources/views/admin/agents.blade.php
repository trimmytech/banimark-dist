@php use Banimark\Ui\Chart; use Banimark\Ui\Icons; use Banimark\Auth\Permissions; @endphp
@extends('banimark::admin.layout')
@section('title', 'Staff')
@section('sub', 'Invite colleagues, decide what each of them can do')
@section('content')
    @unless($isOwner)
        <div class="bm-card"><div class="empty"><b>Owners only</b><div>Only an owner can manage staff accounts.</div></div></div>
    @else
    <div class="bm-card pad0">
        <div class="t-wrap">
            <table>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Access</th><th>Status</th><th>2FA</th><th></th></tr>
                @foreach($rows as $a)
                    @php $perms = Permissions::of($a); $preset = Permissions::presetOf($perms); $pending = ($a['status'] ?? 'active') === 'pending'; @endphp
                    <tr>
                        <td><div class="row"><span class="avatar">{{ strtoupper(substr($a['name'], 0, 1)) }}</span><b>{{ $a['name'] }}</b>@if((int) $a['id'] === $meId)<span class="muted">(you)</span>@endif</div></td>
                        <td class="muted">{{ $a['email'] }}</td>
                        <td><span class="pill {{ $a['role'] === 'owner' ? 'ai' : 'agent' }}">{{ strtoupper($a['role']) }}</span></td>
                        <td>
                            @if($a['role'] === 'owner')<span class="muted">everything</span>
                            @else <span class="pill closed">{{ strtoupper($presets[$preset]['label'] ?? 'custom') }}</span>
                                <button type="button" class="btn-ghost btn-sm" data-toggle="#access-{{ $a['id'] }}">Edit</button>
                            @endif
                        </td>
                        <td>
                            @if($pending)<span class="pill expired" title="Invited {{ $a['invited_at'] }}">PENDING</span>
                            @else<span class="pill {{ $a['enabled'] ? 'good' : 'closed' }}">{{ $a['enabled'] ? 'ACTIVE' : 'DISABLED' }}</span>@endif
                        </td>
                        <td>
                            @if(!empty($a['totp_enabled']))
                                <div class="row" style="gap:6px"><span class="pill good">ON</span>
                                <form method="post" action="{{ route('banimark.admin.agents.totp.reset') }}">@csrf
                                    <input type="hidden" name="id" value="{{ $a['id'] }}">
                                    <button class="btn-ghost btn-sm" data-confirm="Reset 2FA for {{ $a['name'] }}? They sign in with just their password until they enrol again.">Reset</button>
                                </form></div>
                            @else<span class="pill closed">OFF</span>@endif
                        </td>
                        <td class="row" style="gap:4px">
                            @if($pending)
                                <form method="post" action="{{ route('banimark.admin.agents.reinvite') }}">@csrf<input type="hidden" name="id" value="{{ $a['id'] }}"><button class="btn2 btn-sm" title="Send a fresh activation link">Resend invite</button></form>
                            @endif
                            <form method="post" action="{{ route('banimark.admin.agents.delete') }}">@csrf
                                <input type="hidden" name="id" value="{{ $a['id'] }}">
                                <button class="btn-ghost btn-icon" data-confirm="Remove this staff account?" title="Remove">{!! Icons::get('trash', 15) !!}</button>
                            </form>
                        </td>
                    </tr>
                    @if($a['role'] !== 'owner')
                    <tr id="access-{{ $a['id'] }}" hidden><td colspan="7" style="background:var(--surface-2)">
                        <form method="post" action="{{ route('banimark.admin.agents.permissions') }}" class="row" style="align-items:flex-start;gap:24px;flex-wrap:wrap">@csrf
                            <input type="hidden" name="id" value="{{ $a['id'] }}">
                            <div style="min-width:220px">
                                <label>Role</label>
                                <select name="role"><option value="agent" selected>Staff</option><option value="owner">Owner — full control</option></select>
                                <label style="margin-top:10px">Preset</label>
                                <select name="preset" data-preset-for="#access-{{ $a['id'] }}">
                                    @foreach($presets as $k => $p)<option value="{{ $k }}" @selected($preset === $k)>{{ $p['label'] }}</option>@endforeach
                                    <option value="custom" @selected($preset === 'custom')>Custom — tick below</option>
                                </select>
                            </div>
                            <div style="flex:1;min-width:260px">
                                <label>What {{ $a['name'] }} can do</label>
                                <div class="bm-perms">
                                    @foreach($permissions as $key => $label)
                                        <label><input type="checkbox" name="perms[]" value="{{ $key }}" @checked(in_array($key, $perms, true))> <b>{{ $key }}</b> <span class="muted">{{ $label }}</span></label>
                                    @endforeach
                                </div>
                            </div>
                            <div style="align-self:flex-end"><button type="submit" class="btn-sm">Save access</button></div>
                        </form>
                    </td></tr>
                    @endif
                @endforeach
            </table>
        </div>
    </div>

    <div class="bm-card">
        <h2>Two-factor policy</h2>
        <div class="muted">When on, every staff member (owners included) must set up an authenticator app before they can use the panel. Anyone locked out can be reset above.</div>
        <form method="post" action="{{ route('banimark.admin.agents.totp.require') }}" class="row" style="margin-top:12px;gap:14px">@csrf
            <label style="display:flex;align-items:center;gap:10px;margin:0">
                <span class="switch"><input type="checkbox" name="require_2fa" value="1" @checked($require2fa)><span class="sl"></span></span>
                Require 2FA for all staff
            </label>
            <button type="submit" class="btn2 btn-sm">Save policy</button>
            <a class="btn-ghost btn-sm" href="{{ route('banimark.admin.security') }}">{!! Icons::get('shield', 14) !!} My own 2FA</a>
        </form>
    </div>

    <div class="bm-card">
        <h2>Invite a colleague</h2>
        <div class="muted">They get an email with a link to choose their own password. The account is <b>pending</b> and cannot sign in until they do.</div>
        <form method="post" action="{{ route('banimark.admin.agents.save') }}">
            @csrf
            <div class="grid2">
                <div><label>Name</label><input type="text" name="name" required></div>
                <div><label>Email (their login — the invitation goes here)</label><input type="text" name="email" required></div>
                <div><label>Role</label><select name="role"><option value="agent">Staff — access set below</option><option value="owner">Owner — full control</option></select></div>
                <div><label>Access preset</label>
                    <select name="preset" data-preset-for="#invite-perms">
                        @foreach($presets as $k => $p)<option value="{{ $k }}" @selected($k === 'agent')>{{ $p['label'] }}</option>@endforeach
                        <option value="custom">Custom — tick below</option>
                    </select>
                </div>
            </div>
            <div id="invite-perms" class="bm-perms" style="margin-top:12px">
                @foreach($permissions as $key => $label)
                    <label><input type="checkbox" name="perms[]" value="{{ $key }}" @checked(in_array($key, Permissions::preset('agent'), true))> <b>{{ $key }}</b> <span class="muted">{{ $label }}</span></label>
                @endforeach
            </div>
            <div style="margin-top:16px"><button type="submit">{!! Icons::get('send', 15) !!} Send invitation</button></div>
        </form>
    </div>
    @endunless
@endsection
