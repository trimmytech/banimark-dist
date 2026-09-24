@php use Banimark\Ui\Layout; use Banimark\Ui\Icons; @endphp
<!doctype html>
<html lang="en">
<head>{!! Layout::head('Banimark — Locked') !!}</head>
<body>
<div class="bm-auth">{!! \Banimark\Ui\Layout::authArt() !!}<div class="auth-main"><div class="box"><div class="bm-card" style="text-align:center">
    <div class="bm-logo" style="margin:0 auto 12px"></div>
    <h2>This desk is locked</h2>
    <p class="muted">The licence needs attention. Ask the account owner to check it — staff cannot change licensing.</p>
    @if(!empty($supportEmail))
        <p class="muted">The owner can renew it by emailing <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
    @endif
    <form method="post" action="{{ route('banimark.admin.logout') }}">@csrf<button type="submit" class="btn2" style="margin-top:10px">Sign out</button></form>
</div></div></div></div>
{!! Layout::scripts() !!}
</body>
</html>
