@php use Banimark\Ui\Icons; use Banimark\Ui\Layout; @endphp
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
    <div class="bm-card" data-live-chat data-session="{{ $sessionId }}" data-mode="{{ $mode }}" data-after="{{ $lastId }}"
         data-messages-url="{{ route('banimark.admin.conversation.messages', $sessionId) }}"
         data-reply-url="{{ route('banimark.admin.conversation.reply', $sessionId) }}"
         data-csrf-name="_token" data-csrf="{{ csrf_token() }}">
        <div class="bm-sec-h">
            <div class="row">
                <span class="avatar">{{ strtoupper(substr($presence['visitor_label'] ?? '', 0, 1) ?: 'V') }}</span>
                <div>
                    <h2 style="margin:0">{{ $presence['visitor_label'] ?: 'Visitor' }} <span class="pill {{ $mode }}" data-mode-pill>{{ strtoupper($mode) }}</span></h2>
                    <span class="bm-presence {{ ($presence['last_seen_at'] ?? 0) > time() - 45 ? 'on' : 'off' }}" data-presence>{{ $presence['visitor_label'] ?: 'Visitor' }}{{ ($presence['last_seen_at'] ?? 0) > time() - 45 ? ' · online now' : (($presence['last_seen_at'] ?? 0) ? ' · left the chat' : '') }}</span>
                    @if(!empty($presence['visitor_email']))<span class="muted" style="margin-left:8px">{{ $presence['visitor_email'] }}</span>@endif
                </div>
            </div>
        </div>

        <div class="msgs" data-thread data-autoscroll style="max-height:56vh;overflow-y:auto;padding-right:4px">
            @foreach($rows as $m)
                @if($m['role'] === 'tool')
                    <div class="msg tool" data-id="{{ $m['id'] }}">{!! Icons::get('bolt', 12) !!} {{ $m['text'] }}</div>
                @else
                    <div class="msg {{ $m['role'] }}" data-id="{{ $m['id'] }}">{{ $m['text'] }}<div class="msg-meta">{{ $m['role'] === 'agent' ? 'human agent · ' : ($m['role'] === 'assistant' ? 'AI · ' : '') }}{{ $m['at'] ? date('H:i', $m['at']) : '' }}</div></div>
                @endif
            @endforeach
        </div>
        <div class="bm-typing" data-typing hidden><i></i><i></i><i></i></div>
        <div class="flash-ok" data-flash hidden></div>

        <div class="bm-quick">
            @foreach($quick as $q)<button type="button" data-quick="{{ $q }}">{{ \Illuminate\Support\Str::limit($q, 42) }}</button>@endforeach
        </div>
        <form method="post" action="{{ route('banimark.admin.conversation.reply', $sessionId) }}" class="bm-compose" data-reply>
            @csrf
            <textarea name="message" rows="1" placeholder="Reply as a human agent… (Enter to send, Shift+Enter for a new line)" autofocus autocomplete="off"></textarea>
            <button type="submit">{!! Icons::get('send', 15) !!} Send</button>
        </form>
    </div>
    {!! Layout::chatScript() !!}
@endsection
