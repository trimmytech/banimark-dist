@php use Banimark\Ui\Chart; use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Inbox')
@section('sub', count($rows) . ' conversation' . (count($rows) === 1 ? '' : 's'))
@section('content')
    <div class="bm-card pad0">
        <div class="bm-sec-h" style="padding:16px 20px 12px;align-items:center">
            <div class="row" style="gap:6px;flex-wrap:wrap">
                @foreach([null => 'All', 'agent' => 'Needs a human', 'ai' => 'AI handled', 'closed' => 'Closed'] as $k => $lbl)
                    <a class="btn-sm {{ $mode === ($k ?: null) ? 'btn' : 'btn2' }}"
                       href="{{ route('banimark.admin.inbox') }}{{ $k ? '?mode='.$k : '' }}">{{ $lbl }}</a>
                @endforeach
            </div>
        </div>
        <div class="t-wrap">
            <table>
                <tr><th>Visitor</th><th>State</th><th>Messages</th><th>Last message</th><th>Activity</th><th></th></tr>
                @forelse($rows as $r)
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
                        <td class="muted">{{ \Illuminate\Support\Str::limit((string) $r['last_message'], 70) }}</td>
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
