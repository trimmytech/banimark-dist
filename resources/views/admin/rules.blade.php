@php use Banimark\Ui\Chart; use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Rules')
@section('sub', 'How your assistant behaves — organised in folders, applied in order')
@section('actions')
    <button type="button" class="btn btn-sm" onclick="document.getElementById('new-folder').hidden=false;document.querySelector('#new-folder input[name=title]').focus()">{!! Icons::get('plus', 15) !!} New folder</button>
@endsection
@section('content')
    <div class="bm-card" id="new-folder" hidden>
        <h2>New folder</h2>
        <div class="muted">A folder groups related rules — Personality, Refund policy, Opening hours. Folders are applied top to bottom.</div>
        <form method="post" action="{{ route('banimark.admin.rules.folder') }}">@csrf
            <div class="grid2">
                <div><label>Folder name</label><input type="text" name="title" required placeholder="Refund policy"></div>
                <div><label>What goes in here <span class="muted">(optional)</span></label><input type="text" name="description" placeholder="Everything the assistant may and may not promise about refunds"></div>
            </div>
            <div class="row" style="margin-top:12px"><button type="submit">Create folder</button><button type="button" class="btn-ghost" onclick="this.closest('.bm-card').hidden=true">Cancel</button></div>
        </form>
    </div>

    @forelse($folders as $fi => $f)
        <div class="bm-card pad0" style="{{ $f['enabled'] ? '' : 'opacity:.6' }}">
            <div class="bm-sec-h" style="padding:16px 20px 12px;align-items:center;border-bottom:1px solid var(--border)">
                <div class="row" style="gap:10px">
                    <span class="avatar">{{ $fi + 1 }}</span>
                    <div>
                        <h2 style="margin:0">{{ $f['title'] }} @unless($f['enabled'])<span class="pill closed">OFF</span>@endunless</h2>
                        @if($f['description'] !== '')<div class="muted">{{ $f['description'] }}</div>@endif
                    </div>
                </div>
                <div class="spacer"></div>
                <div class="row" style="gap:4px">
                    <form method="post" action="{{ route('banimark.admin.rules.folder.move') }}">@csrf<input type="hidden" name="id" value="{{ $f['id'] }}"><input type="hidden" name="direction" value="-1"><button class="btn-ghost btn-icon" title="Move up" {{ $fi === 0 ? 'disabled' : '' }}>&uarr;</button></form>
                    <form method="post" action="{{ route('banimark.admin.rules.folder.move') }}">@csrf<input type="hidden" name="id" value="{{ $f['id'] }}"><input type="hidden" name="direction" value="1"><button class="btn-ghost btn-icon" title="Move down" {{ $fi === count($folders) - 1 ? 'disabled' : '' }}>&darr;</button></form>
                    <button type="button" class="btn-ghost btn-sm" onclick="document.getElementById('edit-folder-{{ $f['id'] }}').hidden ^= true">Edit</button>
                    <form method="post" action="{{ route('banimark.admin.rules.folder.delete') }}">@csrf<input type="hidden" name="id" value="{{ $f['id'] }}"><button class="btn-ghost btn-icon" title="Delete folder and its rules" onclick="return confirm('Delete this folder and every rule in it?')">{!! Icons::get('trash', 15) !!}</button></form>
                </div>
            </div>

            <form method="post" action="{{ route('banimark.admin.rules.folder') }}" id="edit-folder-{{ $f['id'] }}" hidden style="padding:12px 20px;border-bottom:1px solid var(--border);background:var(--surface-2)">@csrf
                <input type="hidden" name="id" value="{{ $f['id'] }}">
                <div class="grid2">
                    <div><label>Folder name</label><input type="text" name="title" value="{{ $f['title'] }}" required></div>
                    <div><label>Description</label><input type="text" name="description" value="{{ $f['description'] }}"></div>
                </div>
                <div class="row" style="margin-top:10px;gap:14px">
                    <label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="enabled" value="1" @checked($f['enabled'])> Folder is active</label>
                    <button type="submit" class="btn-sm">Save folder</button>
                </div>
            </form>

            <div style="padding:6px 20px 4px">
                @forelse($f['rules'] as $ri => $r)
                    <div style="display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--border);{{ $r['enabled'] ? '' : 'opacity:.55' }}">
                        <div style="flex:1;min-width:0">
                            <div class="row" style="gap:8px">
                                @if($r['title'] !== '')<b>{{ $r['title'] }}</b>@endif
                                @unless($r['enabled'])<span class="pill closed">OFF</span>@endunless
                            </div>
                            <div class="muted" style="white-space:pre-wrap;margin-top:3px">{{ $r['content'] }}</div>
                            <form method="post" action="{{ route('banimark.admin.rules.save') }}" id="edit-rule-{{ $r['id'] }}" hidden style="margin-top:8px">@csrf
                                <input type="hidden" name="id" value="{{ $r['id'] }}">
                                <input type="text" name="title" value="{{ $r['title'] }}" placeholder="Short title (optional)">
                                <textarea name="content" required style="margin-top:6px">{{ $r['content'] }}</textarea>
                                <div class="row" style="margin-top:8px;gap:14px">
                                    <label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="enabled" value="1" @checked($r['enabled'])> Active</label>
                                    <button type="submit" class="btn-sm">Save rule</button>
                                </div>
                            </form>
                        </div>
                        <div class="row" style="gap:2px;flex:none">
                            <form method="post" action="{{ route('banimark.admin.rules.move') }}">@csrf<input type="hidden" name="id" value="{{ $r['id'] }}"><input type="hidden" name="direction" value="-1"><button class="btn-ghost btn-icon" title="Up" {{ $ri === 0 ? 'disabled' : '' }}>&uarr;</button></form>
                            <form method="post" action="{{ route('banimark.admin.rules.move') }}">@csrf<input type="hidden" name="id" value="{{ $r['id'] }}"><input type="hidden" name="direction" value="1"><button class="btn-ghost btn-icon" title="Down" {{ $ri === count($f['rules']) - 1 ? 'disabled' : '' }}>&darr;</button></form>
                            <button type="button" class="btn-ghost btn-sm" onclick="document.getElementById('edit-rule-{{ $r['id'] }}').hidden ^= true">Edit</button>
                            <form method="post" action="{{ route('banimark.admin.rules.delete') }}">@csrf<input type="hidden" name="id" value="{{ $r['id'] }}"><button class="btn-ghost btn-icon" title="Delete" onclick="return confirm('Delete this rule?')">{!! Icons::get('trash', 15) !!}</button></form>
                        </div>
                    </div>
                @empty
                    <div class="muted" style="padding:10px 0">No rules in this folder yet.</div>
                @endforelse

                <form method="post" action="{{ route('banimark.admin.rules.save') }}" style="padding:12px 0 14px">@csrf
                    <input type="hidden" name="folder_id" value="{{ $f['id'] }}">
                    <div class="row" style="align-items:flex-start">
                        <input type="text" name="title" placeholder="Short title (optional)" style="flex:1">
                        <input type="text" name="content" required placeholder="Add a rule to {{ $f['title'] }}…" style="flex:3">
                        <button type="submit" class="btn2 btn-sm">{!! Icons::get('plus', 14) !!} Add</button>
                    </div>
                </form>
            </div>
        </div>
    @empty
        <div class="bm-card">{!! Chart::empty('No folders yet', 'Create a folder, then add rules to it.') !!}</div>
    @endforelse
@endsection
