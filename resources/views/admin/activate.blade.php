@php use Banimark\Ui\Layout; @endphp
<!doctype html>
<html lang="en">
<head>{!! Layout::head('Banimark — Activate your account') !!}</head>
<body>
<div class="bm-auth">
    <div class="box">
        <div class="bm-card">
            <div class="head">
                <div class="bm-logo"></div>
                @if($agent)
                    <h2>Welcome, {{ $agent['name'] }}</h2>
                    <p class="muted">Choose a password to activate your support desk account ({{ $agent['email'] }}).</p>
                @else
                    <h2>This link has expired</h2>
                    <p class="muted">Invitation links work for 7 days and once only. Ask an owner to resend yours.</p>
                @endif
            </div>
            @if(session('bm_error'))<div class="flash-err"><span>{{ session('bm_error') }}</span></div>@endif
            @if($agent)
                <form method="post" action="{{ route('banimark.admin.activate.post', $token) }}">
                    @csrf
                    <label>Your name</label>
                    <input type="text" name="name" value="{{ $agent['name'] }}" autocomplete="name">
                    <label>Password <span class="muted">(at least 8 characters)</span></label>
                    <input type="password" name="password" autocomplete="new-password" autofocus>
                    <label>Password again</label>
                    <input type="password" name="password_confirmation" autocomplete="new-password">
                    <button type="submit" style="width:100%;margin-top:18px;">Activate &amp; sign in</button>
                </form>
            @else
                <a class="btn-ghost" href="{{ route('banimark.admin.login') }}" style="display:block;text-align:center">Back to sign in</a>
            @endif
        </div>
    </div>
</div>
{!! Layout::scripts() !!}
</body>
</html>
