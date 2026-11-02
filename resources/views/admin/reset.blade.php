@php use Banimark\Ui\Layout; @endphp
<!doctype html>
<html lang="en">
<head>{!! Layout::head('Banimark — New password') !!}</head>
<body>
<div class="bm-auth">{!! Layout::authArt() !!}<div class="auth-main">
    <div class="box">
        <div class="bm-card">
            <div class="head">
                <div class="bm-logo"></div>
                @if($agent)
                    <h2>Choose a new password</h2>
                    <p class="muted">For {{ $agent['email'] }}.</p>
                @else
                    <h2>This link has expired</h2>
                    <p class="muted">Password links work for an hour and once only. <a href="{{ route('banimark.admin.forgot') }}">Ask for a new one</a>.</p>
                @endif
            </div>
            @if(session('bm_error'))<div class="flash-err" data-flash><span>{{ session('bm_error') }}</span></div>@endif
            @if($agent)
            <form method="post" action="{{ route('banimark.admin.reset.post', $token) }}">
                @csrf
                <label>New password <span class="muted">(at least 8 characters)</span></label>
                <input type="password" name="password" autocomplete="new-password" autofocus>
                <label>Password again</label>
                <input type="password" name="password_confirmation" autocomplete="new-password">
                <button type="submit" style="width:100%;margin-top:18px;">Save and sign in</button>
            </form>
            @else
                <a class="btn-ghost" href="{{ route('banimark.admin.login') }}" style="display:block;text-align:center">Back to sign in</a>
            @endif
        </div>
    </div>
</div></div>
{!! Layout::scripts() !!}
</body>
</html>
