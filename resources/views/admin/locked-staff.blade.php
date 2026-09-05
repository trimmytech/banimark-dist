@php use Banimark\Ui\Layout; use Banimark\Ui\Icons; @endphp
<!doctype html>
<html lang="en">
<head>{!! Layout::head('Banimark — Locked') !!}</head>
<body>
<div class="bm-auth"><div class="box"><div class="bm-card" style="max-width:420px;margin:0 auto;text-align:center">
    <div class="bm-logo" style="margin:0 auto 12px"></div>
    <h2>This desk is locked</h2>
    <p class="muted">The licence needs attention. Ask the account owner to check it — staff cannot change licensing.</p>
    <form method="post" action="{{ route('banimark.admin.logout') }}">@csrf<button type="submit" class="btn2" style="margin-top:10px">Sign out</button></form>
</div></div></div>
{!! Layout::scripts() !!}
</body>
</html>
