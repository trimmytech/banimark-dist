@php use Banimark\Ui\Layout; @endphp
<!doctype html>
<html lang="en">
<head>{!! Layout::head('Banimark — Verify') !!}</head>
<body>
<div class="bm-auth">
    <div class="box">
        <div class="bm-card">
            <div class="head">
                <div class="bm-logo"></div>
                <h2>One more step</h2>
                <p class="muted">Enter the 6-digit code from your authenticator app.</p>
            </div>
            @if(session('bm_error'))<div class="flash-err"><span>{{ session('bm_error') }}</span></div>@endif
            <form method="post" action="{{ route('banimark.admin.login.2fa.post') }}">
                @csrf
                <label>Code</label>
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" autofocus placeholder="123 456" style="font-size:22px;letter-spacing:.3em;text-align:center">
                <button type="submit" style="width:100%;margin-top:18px;">Verify</button>
            </form>
            <form method="post" action="{{ route('banimark.admin.logout') }}" style="margin-top:10px">@csrf
                <button type="submit" class="btn-ghost" style="width:100%">Use a different account</button>
            </form>
        </div>
        <p class="muted" style="text-align:center">Lost your device? An owner can reset your 2FA from Staff.</p>
    </div>
</div>
{!! Layout::scripts() !!}
</body>
</html>
