@php use Banimark\Ui\Chart; use Banimark\Ui\Icons; use Banimark\Files\Markers; @endphp
@extends('banimark::admin.layout')
@section('title', 'Inbox')
@section('sub', $counts['unread'] > 0 ? $counts['unread'].' waiting on you' : 'Everything is answered')
@section('content')
    <div class="bm-card pad0">
        <div class="bm-inbox-top">
            <div class="row" style="gap:6px;flex-wrap:wrap">
                @foreach([null => ['All', $counts['all']], 'agent' => ['Needs a human', $counts['agent']], 'ai' => ['AI handled', $counts['ai']], 'closed' => ['Closed', $counts['closed']]] as $k => $t)
                    <a class="bm-tab {{ $mode === ($k ?: null) ? 'on' : '' }}"
                       href="{{ route('banimark.admin.inbox') }}?{{ http_build_query(array_filter(['mode' => $k, 'q' => $q])) }}">
                        {{ $t[0] }} <span>{{ $t[1] }}</span>
                    </a>
                @endforeach
            </div>
            <div class="spacer"></div>
            <form method="get" action="{{ route('banimark.admin.inbox') }}" class="bm-search">
                @if($mode)<input type="hidden" name="mode" value="{{ $mode }}">@endif
                {!! Icons::get('inbox', 14) !!}
                <input type="text" name="q" value="{{ $q }}" placeholder="Search people and messages…" autocomplete="off">
                @if($q !== '')<a class="btn-ghost btn-sm" href="{{ route('banimark.admin.inbox') }}{{ $mode ? '?mode='.$mode : '' }}">Clear</a>@endif
            </form>
        </div>

        <div class="bm-threads">
            @forelse($rows as $r)
                @php
                    $unread = (int) $r['last_message_at'] > (int) ($r['staff_seen_at'] ?? 0) && $r['mode'] !== 'closed';
                    $preview = Markers::parse((string) $r['last_message'])['text'];
                    $online = (int) ($r['last_seen_at'] ?? 0) > time() - 45;
                @endphp
                <a class="bm-thread {{ $unread ? 'unread' : '' }}" href="{{ route('banimark.admin.conversation', $r['session_id']) }}">
                    <span class="bm-thread-av">
                        <span class="avatar">{{ strtoupper(substr($r['visitor_label'] ?: 'A', 0, 1)) }}</span>
                        @if($online)<i class="bm-online" title="In the chat now"></i>@endif
                    </span>
                    <span class="bm-thread-main">
                        <span class="bm-thread-head">
                            <b>{{ $r['visitor_label'] ?: 'Anonymous visitor' }}</b>
                            @if($r['mode'] === 'agent')<span class="pill agent">NEEDS A HUMAN</span>
                            @elseif($r['mode'] === 'closed')<span class="pill closed">CLOSED</span>@endif
                            @if((int) $r['file_count'] > 0)<span class="muted" title="{{ $r['file_count'] }} file(s)">📎 {{ $r['file_count'] }}</span>@endif
                            <span class="spacer"></span>
                            <span class="muted bm-when">{{ $r['last_message_at'] ? \Banimark\Ui\Chart::ago((int) $r['last_message_at']) : '—' }}</span>
                        </span>
                        <span class="bm-thread-line">
                            @if(($r['last_role'] ?? '') === 'agent')<span class="muted">{{ ($r['last_agent'] ?? '') !== '' && $r['last_agent'] !== $me ? $r['last_agent'] : 'You' }}:</span>
                            @elseif(($r['last_role'] ?? '') === 'assistant')<span class="muted">AI:</span>@endif
                            {{ \Illuminate\Support\Str::limit($preview !== '' ? $preview : '(a file)', 110) }}
                        </span>
                        @if($r['visitor_email'])<span class="bm-thread-sub muted">{{ $r['visitor_email'] }}</span>@endif
                    </span>
                    <span class="bm-thread-end">
                        @if($unread)<i class="bm-dot" title="New since you last looked"></i>@endif
                        <span class="muted">{{ $r['message_count'] }} msg</span>
                    </span>
                </a>
            @empty
                <div style="padding:8px">{!! Chart::empty($q !== '' ? 'Nothing matches “'.e($q).'”' : 'No conversations yet', $q !== '' ? 'Try a different word, or clear the search.' : 'Embed the widget on your site and say hello.') !!}</div>
            @endforelse
        </div>
    </div>
@endsection
