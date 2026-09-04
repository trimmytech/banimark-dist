@php use Banimark\Ui\Chart; use Banimark\Ui\Layout; use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Dashboard')
@section('sub', 'How your AI desk is performing')
@section('actions')
    <a class="btn btn-sm" href="{{ route('banimark.admin.inbox') }}">{!! Icons::get('inbox', 15) !!} Open inbox</a>
@endsection
@section('content')

    <div class="bm-grid c4" style="margin-bottom:16px">
        {!! Layout::stat('Conversations', number_format($a['conversations']), 'chat', $a['week_delta'],
              $a['week_delta'] === null ? 'all time' : 'vs last week',
              Chart::spark(array_column($a['series'], 'conversations'), 'var(--s1)')) !!}
        {!! Layout::stat('Today', number_format($a['conversations_today']), 'clock', null, 'new conversations') !!}
        {!! Layout::stat('Avg messages', (string) $a['avg_messages'], 'inbox', null, 'per conversation') !!}
        {!! Layout::stat('Escalation rate', $a['escalation_rate'].'%', 'escalation', null, 'handed to a human') !!}
    </div>

    <div class="bm-grid main">
        <div class="bm-card">
            <div class="bm-sec-h">
                <div>
                    <h2>Activity</h2>
                    <div class="muted">Conversations started and messages exchanged, last 14 days</div>
                </div>
            </div>
            {!! Chart::area(
                array_column($a['series'], 'label'),
                [
                    ['name' => 'Conversations', 'color' => 'var(--s1)', 'values' => array_column($a['series'], 'conversations')],
                    ['name' => 'Messages',      'color' => 'var(--s2)', 'values' => array_column($a['series'], 'messages')],
                ]
            ) !!}
        </div>

        <div>
            <div class="bm-card">
                <h2>Who is handling chats</h2>
                <div class="muted">Current state of every conversation</div>
                {!! Chart::stack([
                    ['name' => 'AI',       'value' => $a['modes']['ai'],     'color' => 'var(--s1)'],
                    ['name' => 'Human',    'value' => $a['modes']['agent'],  'color' => 'var(--s2)'],
                    ['name' => 'Closed',   'value' => $a['modes']['closed'], 'color' => 'var(--surface-3)'],
                ]) !!}
            </div>
            <div class="bm-card">
                <h2>Tool usage</h2>
                <div class="muted">{{ number_format($a['tool_calls']) }} lookups run against your data</div>
                <div style="margin-top:14px">
                    {!! Chart::hbars($a['tools'], 'var(--s3)', 'No tools called yet') !!}
                </div>
            </div>
        </div>
    </div>

    <div class="bm-card pad0">
        <div class="bm-sec-h" style="padding:18px 20px 0">
            <div>
                <h2>Latest conversations</h2>
                <div class="muted">Newest first</div>
            </div>
            <div class="spacer"></div>
            <a class="btn2 btn-sm" href="{{ route('banimark.admin.inbox') }}">View all</a>
        </div>
        <div class="t-wrap">
            <table>
                <tr><th>Visitor</th><th>State</th><th>Messages</th><th>Last message</th><th>Activity</th><th></th></tr>
                @forelse($recent as $r)
                    <tr>
                        <td>
                            <div class="row">
                                <span class="avatar">{{ strtoupper(substr($r['visitor_label'] ?: 'A', 0, 1)) }}</span>
                                <span>
                                    <b>{{ $r['visitor_label'] ?: 'Anonymous' }}</b>
                                    <div class="muted mono" style="background:none;padding:0">{{ substr($r['session_id'], 0, 8) }}</div>
                                </span>
                            </div>
                        </td>
                        <td><span class="pill {{ $r['mode'] }}">{{ strtoupper($r['mode']) }}</span></td>
                        <td style="font-variant-numeric:tabular-nums">{{ $r['message_count'] }}</td>
                        <td class="muted">{{ \Illuminate\Support\Str::limit((string) $r['last_message'], 58) }}</td>
                        <td class="muted">{{ $r['last_message_at'] ? date('d M H:i', (int) $r['last_message_at']) : '—' }}</td>
                        <td><a class="btn2 btn-sm" href="{{ route('banimark.admin.conversation', $r['session_id']) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6">{!! Chart::empty('No conversations yet', 'Embed the widget on your site and say hello.') !!}</td></tr>
                @endforelse
            </table>
        </div>
    </div>
@endsection
