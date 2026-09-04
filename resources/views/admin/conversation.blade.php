@php use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Conversation')
@section('sub', 'Replying takes over — the AI stays silent until you hand it back')
@section('actions')
    <a class="btn2 btn-sm" href="{{ route('banimark.admin.inbox') }}">{!! Icons::get('back', 15) !!} Inbox</a>
    <form method="post" action="{{ route('banimark.admin.conversation.mode', $sessionId) }}" style="display:inline">@csrf
        <input type="hidden" name="mode" value="{{ $mode === 'agent' ? 'ai' : 'agent' }}">
        <button class="btn2 btn-sm">{{ $mode === 'agent' ? 'Hand back to AI' : 'Take over' }}</button>
    </form>
    <form method="post" action="{{ route('banimark.admin.conversation.mode', $sessionId) }}" style="display:inline">@csrf
        <input type="hidden" name="mode" value="closed">
        <button class="btn-danger btn-sm" onclick="return confirm('Close this conversation?')">Close</button>
    </form>
@endsection
@section('content')
    <div class="bm-card">
        <div class="bm-sec-h">
            <div class="row">
                <span class="avatar">{{ strtoupper(substr($sessionId, 0, 1)) }}</span>
                <div>
                    <h2 style="margin:0">Transcript <span class="pill {{ $mode }}">{{ strtoupper($mode) }}</span></h2>
                    <div class="muted mono" style="background:none;padding:0">{{ $sessionId }}</div>
                </div>
            </div>
        </div>

        <div class="msgs" data-autoscroll style="max-height:56vh;overflow-y:auto;padding-right:4px">
            @foreach($rows as $m)
                @php $payload = $m['payload'] ? (json_decode($m['payload'], true) ?: []) : []; @endphp
                @if($m['role'] === 'tool')
                    <div class="msg tool">{!! Icons::get('bolt', 12) !!} {{ $payload['for_call']['name'] ?? 'tool' }} → {{ \Illuminate\Support\Str::limit(json_encode($payload['tool_result'] ?? []), 200) }}</div>
                @elseif($m['role'] === 'assistant' && !empty($payload['tool_calls']))
                    <div class="msg tool">{!! Icons::get('bolt', 12) !!} AI called: {{ implode(', ', array_column($payload['tool_calls'], 'name')) }}</div>
                @else
                    <div class="msg {{ $m['role'] }}">{{ $m['content'] }}@if($m['role'] === 'agent')<div style="font-size:10px;opacity:.7;margin-top:4px">human agent</div>@endif</div>
                @endif
            @endforeach
        </div>

        <form method="post" action="{{ route('banimark.admin.conversation.reply', $sessionId) }}" style="display:flex;gap:8px;margin-top:16px">
            @csrf
            <input type="text" name="message" placeholder="Reply as a human agent…" autofocus autocomplete="off" style="flex:1">
            <button type="submit">{!! Icons::get('send', 15) !!} Send</button>
        </form>
    </div>
@endsection
