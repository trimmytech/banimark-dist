@php use Banimark\Ui\Chart; use Banimark\Ui\Icons; use Banimark\Ui\Layout; @endphp
@extends('banimark::admin.layout')
@section('title', 'Tools')
@section('sub', 'Let the AI look things up in your own database — safely, read-only')
@section('content')
    <div class="bm-card pad0">
        <div class="bm-sec-h" style="padding:18px 20px 0">
            <div><h2>Your tools</h2>
                <div class="muted">Each tool is one question the AI can answer from your data, e.g. "find this customer's orders".</div>
            </div>
        </div>
        <div class="t-wrap">
            <table>
                <tr><th>Name</th><th>What it does</th><th>Asks the customer for</th><th>Rows</th><th></th></tr>
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
        <div class="muted">Three steps: name it, say what the AI needs to ask the customer, then point at your data. No SQL knowledge needed — the builder writes it for you.</div>
        <form method="post" action="{{ route('banimark.admin.tools.save') }}">
            @csrf
            <h3 class="bm-step">1. What is this tool?</h3>
            <div class="grid2">
                <div><label>Name <span class="muted">(letters, numbers, underscores)</span></label><input type="text" name="name" required placeholder="find_orders" value="{{ old('name') }}"></div>
                <div><label>Most rows to return</label><input type="number" name="max_rows" value="{{ old('max_rows', 10) }}" min="1" max="50"></div>
            </div>
            <label>Describe it in plain words — the AI reads this to know when to use it</label>
            <textarea name="description" required placeholder="Look up a customer's orders by order number or by what they bought.">{{ old('description') }}</textarea>

            <h3 class="bm-step">2. What should the AI ask the customer for?</h3>
            <div class="muted" style="margin-bottom:8px">Each item becomes a question the AI can ask (an order number, a date, a product name). Add as many as you need.</div>
            <div data-params></div>
            <button type="button" class="btn-ghost btn-sm" data-add-param>{!! Icons::get('plus', 14) !!} Add another</button>

            <h3 class="bm-step">3. Where is the data?</h3>
            <div data-toolbuilder data-schema-url="{{ route('banimark.admin.tools.schema') }}" style="background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:14px 16px">
                <div class="row" style="justify-content:space-between">
                    <b>Visual builder</b><span class="muted" data-status>…</span>
                </div>
                <div class="grid2" style="margin-top:8px">
                    <div><label>Table</label><select data-table><option value="">Loading…</option></select></div>
                    <div><label>Who is chatting is identified by <span class="muted">(identity keys, comma-separated)</span></label><input type="text" name="context" value="{{ old('context', 'user_id') }}" placeholder="user_id"></div>
                </div>
                <label>Columns the AI may show the customer</label>
                <div data-columns><span class="muted">Pick a table first.</span></div>
                <label style="margin-top:12px">Only show rows where…</label>
                <div data-conditions></div>
                <button type="button" class="btn-ghost btn-sm" data-add-condition>{!! Icons::get('plus', 14) !!} Add a condition</button>
                <div class="muted" style="margin:10px 0 4px">Tip: add a condition on the customer's own id using the <i>identity</i> option so every customer only ever sees their own rows.</div>
                <pre class="mono" data-preview style="white-space:pre-wrap;padding:10px 12px;border-radius:8px;margin:8px 0">-- pick a table and at least one column</pre>
                <button type="button" class="btn2 btn-sm" data-apply disabled>{!! Icons::get('check', 14) !!} Use this query</button>
            </div>

            <details style="margin-top:14px">
                <summary class="muted" style="cursor:pointer">Advanced: the query the AI will run (editable)</summary>
                <label>SQL — SELECT only. <code>:param</code> for values the AI asks for, <code>:_key</code> for identity values</label>
                <textarea name="sql" required placeholder="SELECT reference, status, total FROM orders WHERE reference = :reference AND user_id = :_user_id">{{ old('sql') }}</textarea>
                <label>Columns the AI may see</label><input type="text" name="columns" required placeholder="reference, status, total" value="{{ old('columns') }}">
            </details>
            <div style="margin-top:16px"><button type="submit">{!! Icons::get('check', 15) !!} Validate &amp; save tool</button></div>
        </form>
    </div>
    {!! Layout::toolBuilderScript() !!}
@endsection
