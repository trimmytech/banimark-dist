@php use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'License')
@section('sub', 'Your Banimark subscription')
@section('content')
    @if($lock)
        <div class="flash-err">{!! Icons::get('shield', 16) !!}<span><b>Admin locked.</b> {{ $lock['message'] }}</span></div>
    @endif

    <div class="bm-card" style="max-width:660px">
        <div class="bm-sec-h">
            <div>
                <h2>License key</h2>
                <div class="muted">Checked once a day from this panel. The check sends only your key, this site’s URL and version numbers — never your data.</div>
            </div>
            <div class="spacer"></div>
            @if($status !== '')
                <span class="pill {{ $status === 'active' ? 'active' : ($status === 'expired' ? 'expired' : 'revoked') }}">{{ strtoupper($status) }}</span>
            @endif
        </div>

        @if(!empty($modules))
            <div class="row" style="gap:6px;flex-wrap:wrap;margin:10px 0 4px">
                <span class="muted">Modules on this licence:</span>
                @foreach($modules as $m)
                    <span class="pill active">{{ strtoupper(str_replace('-', ' ', $m)) }}</span>
                @endforeach
            </div>
        @endif

        @if($lastPing > 0)
            <div class="muted" style="margin-bottom:6px">Last checked {{ date('d M Y, H:i', $lastPing) }}</div>
        @endif

        <form method="post" action="{{ route('banimark.admin.license.save') }}">
            @csrf
            <label>License key</label>
            <input type="text" name="license_key" value="{{ $key }}" placeholder="BM-XXXX-XXXX-XXXX-XXXX" class="mono">
            <div style="margin-top:16px"><button type="submit">{!! Icons::get('check', 15) !!} Save &amp; check now</button></div>
        </form>

        <div class="divider"></div>
        <div class="row" style="align-items:flex-start;gap:9px">
            {!! Icons::get('widget', 16) !!}
            <div class="muted">Your chat widget keeps working no matter what your licence says. Only this admin panel is gated.</div>
        </div>
    </div>
@endsection
