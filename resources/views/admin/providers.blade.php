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
                    <tr>
                        <td>
                            <div class="row">{!! Icons::get('providers', 15) !!}
                                <b>{{ $r->slug }}</b>
                                @if($r->is_default)<span class="pill ai">DEFAULT</span>@endif
                            </div>
                        </td>
                        <td class="muted">{{ $r->driver }}</td>
                        <td><code>{{ $r->model }}</code></td>
                        <td class="muted">{{ $r->base_url ?: '—' }}</td>
                        <td style="font-variant-numeric:tabular-nums">{{ $r->temperature }}</td>
                        <td><span class="pill {{ $r->enabled ? 'good' : 'closed' }}">{{ $r->enabled ? 'ENABLED' : 'DISABLED' }}</span></td>
                        <td>
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

    <div class="bm-card">
        <h2>Add or update a provider</h2>
        <div class="muted">Keys are stored server-side and never shown again. Leave the key blank to keep the existing one.</div>
        <form method="post" action="{{ route('banimark.admin.providers.save') }}">
            @csrf
            <div class="grid2">
                <div><label>Slug</label><input type="text" name="slug" required placeholder="gemini"></div>
                <div><label>Driver</label>
                    <select name="driver">
                        <option value="gemini">gemini</option>
                        <option value="openai-compat">openai-compat (OpenAI / DeepSeek / SiliconFlow / local)</option>
                        <option value="anthropic">anthropic</option>
                    </select>
                </div>
                <div><label>Model</label><input type="text" name="model" required placeholder="gemini-2.5-flash"></div>
                <div><label>Base URL (openai-compat only)</label><input type="text" name="base_url" placeholder="https://api.deepseek.com"></div>
                <div><label>API key</label><input type="password" name="api_key" autocomplete="new-password" placeholder="••••••••"></div>
                <div><label>Temperature</label><input type="number" name="temperature" step="0.05" value="0.4"></div>
            </div>
            <div class="row" style="margin-top:14px;gap:20px">
                <label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
                <label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="is_default" value="1"> Make default</label>
            </div>
            <div style="margin-top:16px"><button type="submit">Save provider</button></div>
        </form>
    </div>
@endsection
