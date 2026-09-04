@php use Banimark\Ui\Chart; use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Rules')
@section('sub', "Every enabled rule joins the AI's system instruction, in sort order")
@section('content')
    <div class="bm-card pad0">
        <div class="t-wrap">
            <table>
                <tr><th>#</th><th>Title</th><th>Content</th><th>Status</th><th></th></tr>
                @forelse($rows as $r)
                    <tr>
                        <td class="muted" style="font-variant-numeric:tabular-nums">{{ $r->sort }}</td>
                        <td><b>{{ $r->title }}</b></td>
                        <td class="muted" style="white-space:pre-wrap;max-width:520px">{{ \Illuminate\Support\Str::limit($r->content, 220) }}</td>
                        <td><span class="pill {{ $r->enabled ? 'good' : 'closed' }}">{{ $r->enabled ? 'ON' : 'OFF' }}</span></td>
                        <td>
                            <form method="post" action="{{ route('banimark.admin.rules.delete') }}">@csrf
                                <input type="hidden" name="id" value="{{ $r->id }}">
                                <button class="btn-ghost btn-icon" onclick="return confirm('Delete this rule?')" title="Delete">{!! Icons::get('trash', 15) !!}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">{!! Chart::empty('No rules yet', 'The AI runs on the built-in base instruction only.') !!}</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    <div class="bm-card">
        <h2>Add a rule</h2>
        <form method="post" action="{{ route('banimark.admin.rules.save') }}">
            @csrf
            <div class="grid2">
                <div><label>Title</label><input type="text" name="title" required placeholder="Refund policy"></div>
                <div><label>Sort</label><input type="number" name="sort" value="0"></div>
            </div>
            <label>Content</label>
            <textarea name="content" required placeholder="Never promise a refund date. Always point customers to the returns page."></textarea>
            <label style="display:flex;align-items:center;gap:8px;margin-top:12px"><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
            <div style="margin-top:14px"><button type="submit">Save rule</button></div>
        </form>
    </div>
@endsection
