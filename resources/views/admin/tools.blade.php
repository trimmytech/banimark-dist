@php use Banimark\Ui\Chart; use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Tools')
@section('sub', 'Read-only lookups the AI can run against your own database')
@section('content')
    <div class="bm-card pad0">
        <div class="bm-sec-h" style="padding:18px 20px 0">
            <div><h2>Your tools</h2>
                <div class="muted">Values are always bound. <code>:_key</code> placeholders come from the signed visitor identity and can never be set by the AI.</div>
            </div>
        </div>
        <div class="t-wrap">
            <table>
                <tr><th>Name</th><th>Description</th><th>Parameters</th><th>Rows</th><th></th></tr>
                @forelse($rows as $r)
                    <tr>
                        <td><div class="row">{!! Icons::get('tools', 15) !!}<b class="mono" style="background:none;padding:0">{{ $r->name }}</b></div></td>
                        <td class="muted">{{ \Illuminate\Support\Str::limit($r->description, 110) }}</td>
                        <td class="muted">{{ implode(', ', array_keys(json_decode($r->parameters, true) ?: [])) ?: '—' }}</td>
                        <td style="font-variant-numeric:tabular-nums">{{ $r->max_rows }}</td>
                        <td>
                            <form method="post" action="{{ route('banimark.admin.tools.delete') }}">@csrf
                                <input type="hidden" name="name" value="{{ $r->name }}">
                                <button class="btn-ghost btn-icon" onclick="return confirm('Delete this tool?')" title="Delete">{!! Icons::get('trash', 15) !!}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">{!! Chart::empty('No tools yet', 'The AI can chat, but it cannot look anything up until you build one.') !!}</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    <div class="bm-card">
        <h2>Build a tool</h2>
        <div class="muted">Saved only if it passes validation: SELECT-only, no semicolons or comments, every placeholder declared.</div>
        <form method="post" action="{{ route('banimark.admin.tools.save') }}">
            @csrf
            <div class="grid2">
                <div><label>Name (a–z, 0–9, _)</label><input type="text" name="name" required placeholder="search_order"></div>
                <div><label>Max rows returned</label><input type="number" name="max_rows" value="10"></div>
            </div>
            <label>Description — what the AI reads to decide when to call it</label>
            <textarea name="description" required placeholder="Look up a customer order by its reference number."></textarea>

            <label>Parameters</label>
            @for($i = 0; $i < 4; $i++)
                <div class="row" style="margin-bottom:7px">
                    <input type="text" name="param_name[{{ $i }}]" placeholder="name e.g. reference" style="flex:2">
                    <select name="param_type[{{ $i }}]" style="flex:1">
                        <option>string</option><option>integer</option><option>number</option><option>boolean</option>
                    </select>
                    <input type="text" name="param_desc[{{ $i }}]" placeholder="description for the AI" style="flex:3">
                    <label style="margin:0;white-space:nowrap"><input type="checkbox" name="param_required[{{ $i }}]" value="1">req</label>
                </div>
            @endfor

            <label>SQL — SELECT only. <code>:param</code> for AI values, <code>:_key</code> for identity values</label>
            <textarea name="sql" required placeholder="SELECT reference, status, total FROM orders WHERE reference = :reference AND user_id = :_user_id"></textarea>
            <div class="grid2">
                <div><label>Columns the AI may see</label><input type="text" name="columns" required placeholder="reference, status, total"></div>
                <div><label>Identity context keys used</label><input type="text" name="context" value="user_id"></div>
            </div>
            <div style="margin-top:16px"><button type="submit">{!! Icons::get('check', 15) !!} Validate &amp; save tool</button></div>
        </form>
    </div>
@endsection
