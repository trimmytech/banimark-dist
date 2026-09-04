@php use Banimark\Ui\Layout; @endphp
<!doctype html>
<html lang="en">
<head>{!! Layout::head('Banimark — Sign in') !!}</head>
<body>
<div class="bm-auth">
    <div class="box">
        <div class="bm-card">
            <div class="head">
                <div class="bm-logo"></div>
                <h2>Welcome back</h2>
                <p class="muted">Sign in to your Banimark support desk.</p>
            </div>
            @if(session('bm_error'))<div class="flash-err"><span>{{ session('bm_error') }}</span></div>@endif
            <form method="post" action="{{ route('banimark.admin.login.post') }}">
                @csrf
                <label>Email</label>
                <input type="text" name="email" autofocus autocomplete="username" placeholder="you@company.com">
                <label>Password</label>
                <input type="password" name="password" autocomplete="current-password" placeholder="••••••••">
                <button type="submit" style="width:100%;margin-top:18px;">Sign in</button>
            </form>
        </div>
        <p class="muted" style="text-align:center">Banimark runs entirely on your server.</p>
    </div>
</div>
{!! Layout::scripts() !!}
</body>
</html>
