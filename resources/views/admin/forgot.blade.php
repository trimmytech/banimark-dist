@php use Banimark\Ui\Layout; @endphp
<!doctype html>
<html lang="en">
<head>{!! Layout::head('Banimark — Forgot your password') !!}</head>
<body>
<div class="bm-auth">{!! Layout::authArt() !!}<div class="auth-main">
    <div class="box">
        <div class="bm-card">
            <div class="head">
                <div class="bm-logo"></div>
                <h2>Forgot your password?</h2>
                <p class="muted">Type the email of your staff account and we send you a link to choose a new one. The link works for an hour.</p>
            </div>
            @if(session('bm_ok'))<div class="flash-ok" data-flash><span>{{ session('bm_ok') }}</span></div>@endif
            @if(session('bm_error'))<div class="flash-err" data-flash><span>{{ session('bm_error') }}</span></div>@endif
            @if(!session('bm_ok'))
            <form method="post" action="{{ route('banimark.admin.forgot.post') }}">
                @csrf
                <label>Email</label>
                <input type="text" name="email" autofocus autocomplete="username" placeholder="you@company.com" value="{{ old('email') }}">
                <button type="submit" style="width:100%;margin-top:18px;">Email me a link</button>
            </form>
            @endif
            <p class="muted" style="text-align:center;margin:14px 0 0"><a href="{{ route('banimark.admin.login') }}">Back to sign in</a></p>
        </div>
    </div>
</div></div>
{!! Layout::scripts() !!}
</body>
</html>
