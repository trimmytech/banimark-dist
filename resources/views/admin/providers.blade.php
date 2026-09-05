@php use Banimark\Ui\Chart; use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'AI providers')
@section('sub', 'Bring your own key — it never leaves your server')
@section('content')
    <div class="bm-card pad0">
        <div class="t-wrap">
            <table>
                <tr><th>Provider</th><th>Driver</th><th>Model</th><th>Base URL</th><th>Temp</th><th>Status</th><th></th></tr>
                @forelse($rows as $r)
                    <tr style="{{ $r->enabled ? '' : 'opacity:.6' }}">
                        <td>
                            <div class="row">{!! Icons::get('providers', 15) !!}
                                <b>{{ $r->slug }}</b>
                                @if(trim((string) $r->api_key) === '')<span class="pill closed" title="No API key yet">NO KEY</span>@endif
                            </div>
                        </td>
                        <td class="muted">{{ $r->driver }}</td>
                        <td><code>{{ $r->model }}</code></td>
                        <td class="muted">{{ $r->base_url ?: '—' }}</td>
                        <td style="font-variant-numeric:tabular-nums">{{ $r->temperature }}</td>
                        <td>@if($r->enabled)<span class="pill good">ANSWERING</span>@else
                            <form method="post" action="{{ route('banimark.admin.providers.activate') }}">@csrf<input type="hidden" name="slug" value="{{ $r->slug }}"><button class="btn2 btn-sm">Use this</button></form>@endif
                        </td>
                        <td class="row" style="gap:4px">
                            <a class="btn-ghost btn-sm" href="{{ route('banimark.admin.providers', ['edit' => $r->slug]) }}#edit">Edit</a>
                            <form method="post" action="{{ route('banimark.admin.providers.delete') }}">@csrf
                                <input type="hidden" name="slug" value="{{ $r->slug }}">
                                <button class="btn-ghost btn-icon" data-confirm="Remove this provider?" title="Remove">{!! Icons::get('trash', 15) !!}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">{!! Chart::empty('No providers yet', 'The chat cannot answer until you add one.') !!}</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    @php $e = $editing ?? null; @endphp
    <div class="bm-card" id="edit">
        <div class="bm-sec-h">
            <div>
                <h2>{{ $e ? 'Edit provider: '.$e['slug'] : 'Add a provider' }}</h2>
                <div class="muted">Keys are stored server-side and never shown again. {{ $e ? 'Leave the key blank to keep the stored one.' : 'Only one provider answers the chat at a time — turning this one on turns the others off.' }}</div>
            </div>
            @if($e)<a class="btn-ghost btn-sm" href="{{ route('banimark.admin.providers') }}">Cancel — add a new one instead</a>@endif
        </div>
        <form method="post" action="{{ route('banimark.admin.providers.save') }}">
            @csrf
            <div class="grid2">
                <div><label>Slug <span class="muted">(its name in this list)</span></label><input type="text" name="slug" required placeholder="gemini" value="{{ $e['slug'] ?? '' }}" @readonly($e)></div>
                <div><label>Driver</label>
                    <select name="driver">
                        <option value="gemini" @selected(($e['driver'] ?? 'gemini') === 'gemini')>gemini</option>
                        <option value="openai-compat" @selected(($e['driver'] ?? '') === 'openai-compat')>openai-compat (OpenAI / DeepSeek / SiliconFlow / local)</option>
                        <option value="anthropic" @selected(($e['driver'] ?? '') === 'anthropic')>anthropic</option>
                    </select>
                </div>
                <div><label>Model</label><input type="text" name="model" required placeholder="gemini-2.5-flash" value="{{ $e['model'] ?? '' }}"></div>
                <div><label>Base URL (openai-compat only)</label><input type="text" name="base_url" placeholder="https://api.deepseek.com" value="{{ $e['base_url'] ?? '' }}"></div>
                <div><label>API key {!! $e ? '<span class="muted">('.($e['has_key'] ? 'a key is stored — blank keeps it' : 'none stored yet').')</span>' : '' !!}</label><input type="password" name="api_key" autocomplete="new-password" placeholder="{{ $e && $e['has_key'] ? '•••••••• (unchanged)' : 'paste your API key' }}"></div>
                <div><label>Temperature</label><input type="number" name="temperature" step="0.05" value="{{ $e['temperature'] ?? 0.4 }}"></div>
            </div>
            <div class="row" style="margin-top:14px;gap:20px">
                <label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="enabled" value="1" @checked($e ? $e['enabled'] : true)> This provider answers the chat <span class="muted">(switches the others off)</span></label>
            </div>
            <div style="margin-top:16px"><button type="submit">{{ $e ? 'Save changes' : 'Save provider' }}</button></div>
        </form>
    </div>
@endsection
