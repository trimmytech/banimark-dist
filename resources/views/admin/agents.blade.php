@php use Banimark\Ui\Chart; use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Staff')
@section('sub', 'Banimark has its own login, independent of your app’s users')
@section('content')
    @unless($isOwner)
        <div class="bm-card"><div class="empty"><b>Owners only</b><div>Only an owner can manage staff accounts.</div></div></div>
    @else
    <div class="bm-card pad0">
        <div class="t-wrap">
            <table>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>2FA</th><th></th></tr>
                @foreach($rows as $a)
                    <tr>
                        <td><div class="row"><span class="avatar">{{ strtoupper(substr($a['name'], 0, 1)) }}</span><b>{{ $a['name'] }}</b></div></td>
                        <td class="muted">{{ $a['email'] }}</td>
                        <td><span class="pill {{ $a['role'] === 'owner' ? 'ai' : 'agent' }}">{{ strtoupper($a['role']) }}</span></td>
                        <td><span class="pill {{ $a['enabled'] ? 'good' : 'closed' }}">{{ $a['enabled'] ? 'ACTIVE' : 'DISABLED' }}</span></td>
                        <td>
                            @if(!empty($a['totp_enabled']))
                                <div class="row" style="gap:6px"><span class="pill good">ON</span>
                                <form method="post" action="{{ route('banimark.admin.agents.totp.reset') }}">@csrf
                                    <input type="hidden" name="id" value="{{ $a['id'] }}">
                                    <button class="btn-ghost btn-sm" onclick="return confirm('Reset 2FA for {{ $a['name'] }}? They sign in with just their password until they enrol again.')">Reset</button>
                                </form></div>
                            @else
                                <span class="pill closed">OFF</span>
                            @endif
                        </td>
                        <td>
                            <form method="post" action="{{ route('banimark.admin.agents.delete') }}">@csrf
                                <input type="hidden" name="id" value="{{ $a['id'] }}">
                                <button class="btn-ghost btn-icon" onclick="return confirm('Remove this staff account?')" title="Remove">{!! Icons::get('trash', 15) !!}</button>
                            </form>
                        </td>
                    </tr>
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
        <h2>Add staff</h2>
        <div class="muted">Agents attend escalated conversations. Owners can also manage staff and settings.</div>
        <form method="post" action="{{ route('banimark.admin.agents.save') }}">
            @csrf
            <div class="grid2">
                <div><label>Name</label><input type="text" name="name" required></div>
                <div><label>Email (their login)</label><input type="text" name="email" required></div>
                <div><label>Password (min 8)</label><input type="password" name="password" required autocomplete="new-password"></div>
                <div><label>Role</label><select name="role"><option value="agent">Agent — handles chats</option><option value="owner">Owner — full control</option></select></div>
            </div>
            <div style="margin-top:16px"><button type="submit">Add staff</button></div>
        </form>
    </div>
    @endunless
@endsection
