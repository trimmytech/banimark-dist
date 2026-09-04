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
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr>
                @foreach($rows as $a)
                    <tr>
                        <td><div class="row"><span class="avatar">{{ strtoupper(substr($a['name'], 0, 1)) }}</span><b>{{ $a['name'] }}</b></div></td>
                        <td class="muted">{{ $a['email'] }}</td>
                        <td><span class="pill {{ $a['role'] === 'owner' ? 'ai' : 'agent' }}">{{ strtoupper($a['role']) }}</span></td>
                        <td><span class="pill {{ $a['enabled'] ? 'good' : 'closed' }}">{{ $a['enabled'] ? 'ACTIVE' : 'DISABLED' }}</span></td>
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
