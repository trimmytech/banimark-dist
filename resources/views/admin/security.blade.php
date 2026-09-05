@php use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Security')
@section('sub', 'Two-factor authentication for your own account')
@section('content')
    <div class="bm-grid c2">
        <div class="bm-card">
            <div class="row" style="gap:10px"><span class="avatar">{!! Icons::get('shield', 16) !!}</span>
                <div><h2 style="margin:0">Two-factor authentication</h2>
                    <div class="muted">A code from your phone is needed alongside your password.</div></div>
                <div class="spacer"></div>
                <span class="pill {{ $enabled ? 'good' : 'closed' }}">{{ $enabled ? 'ON' : 'OFF' }}</span>
            </div>
            @if($required && !$enabled)
                <div class="flash-err" style="margin-top:14px">{!! Icons::get('escalation', 16) !!}<span>Your owner requires 2FA for everyone. Finish the setup on the right to keep using the panel.</span></div>
            @endif

            @if($enabled)
                <p style="margin-top:14px">2FA is protecting this account. To switch it off, confirm with a current code.</p>
                <form method="post" action="{{ route('banimark.admin.security.disable') }}" class="row" style="gap:8px">@csrf
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="123 456" autocomplete="one-time-code" style="width:140px;text-align:center;letter-spacing:.2em">
                    <button type="submit" class="btn-danger btn-sm" data-confirm="Turn off two-factor authentication for your account?">Turn off 2FA</button>
                </form>
            @elseif($pendingSecret === '')
                <p style="margin-top:14px">You will need an authenticator app: Google Authenticator, Authy, 1Password, Microsoft Authenticator - any of them works.</p>
                <form method="post" action="{{ route('banimark.admin.security.begin') }}">@csrf
                    <button type="submit">{!! Icons::get('shield', 15) !!} Set up 2FA</button>
                </form>
            @else
                <p style="margin-top:14px">Setup started - finish it on the right. Nothing changes until you confirm a code.</p>
                <form method="post" action="{{ route('banimark.admin.security.disable') }}">@csrf
                    <input type="hidden" name="code" value="">
                </form>
            @endif
        </div>

        @if(!$enabled && $pendingSecret !== '')
        <div class="bm-card">
            <h2>Finish setup</h2>
            <ol style="padding-left:18px;line-height:1.7">
                <li>Open your authenticator app and choose <b>Add account</b> → <b>Enter a setup key</b>.</li>
                <li>Type this key (account name: your email, type: time-based):</li>
            </ol>
            <div class="bm-secret">{{ trim(chunk_split($pendingSecret, 4, ' ')) }}</div>
            <div class="muted" style="margin:8px 0 14px">On this device? <a href="{{ $uri }}">Open in your authenticator app</a>.</div>
            <form method="post" action="{{ route('banimark.admin.security.confirm') }}">@csrf
                <label>3. Enter the 6-digit code the app shows now</label>
                <div class="row" style="gap:8px">
                    <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="123 456" autocomplete="one-time-code" autofocus style="width:160px;text-align:center;font-size:20px;letter-spacing:.25em">
                    <button type="submit">{!! Icons::get('check', 15) !!} Confirm &amp; turn on</button>
                </div>
            </form>
        </div>
        @else
        <div class="bm-card">
            <h2>How it works</h2>
            <ul style="padding-left:18px;line-height:1.7" class="muted">
                <li>Codes are generated on your phone and change every 30 seconds - nothing is sent by SMS or email.</li>
                <li>Owners can require 2FA for every staff member from <a href="{{ route('banimark.admin.agents') }}">Staff</a>.</li>
                <li>Lost your phone? An owner can reset your 2FA; you then enrol again with a new key.</li>
            </ul>
        </div>
        @endif
    </div>
@endsection
