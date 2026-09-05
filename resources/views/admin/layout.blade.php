@php use Banimark\Ui\Layout; use Banimark\Ui\Icons;
Layout::configure(['events' => route('banimark.admin.events'), 'conversation' => route('banimark.admin.conversation', '__SID__')]); @endphp
<!doctype html>
<html lang="en">
<head>{!! Layout::head('Banimark — '.trim($__env->yieldContent('title', 'Admin'))) !!}</head>
<body>
<div class="bm-app">
    <aside class="bm-side">
        <div class="bm-brand">{!! Layout::logo('Banimark', 'Support desk') !!}</div>
        <nav class="bm-nav">
            <span class="lbl">Support Desk</span>
            {!! Layout::navLink(['href' => route('banimark.admin.dashboard'), 'icon' => 'dashboard', 'label' => 'Dashboard', 'on' => request()->routeIs('banimark.admin.dashboard')]) !!}
            {!! Layout::navLink(['href' => route('banimark.admin.inbox'), 'icon' => 'inbox', 'label' => 'Inbox', 'on' => request()->routeIs('banimark.admin.inbox') || request()->routeIs('banimark.admin.conversation')]) !!}

            {!! Layout::navLink(['href' => route('banimark.admin.tools'), 'icon' => 'tools', 'label' => 'Tools', 'on' => request()->routeIs('banimark.admin.tools')]) !!}
            {!! Layout::navLink(['href' => route('banimark.admin.rules'), 'icon' => 'rules', 'label' => 'Rules', 'on' => request()->routeIs('banimark.admin.rules')]) !!}
            {!! Layout::navLink(['href' => route('banimark.admin.providers'), 'icon' => 'providers', 'label' => 'AI providers', 'on' => request()->routeIs('banimark.admin.providers')]) !!}
            {!! Layout::navLink(['href' => route('banimark.admin.widget'), 'icon' => 'widget', 'label' => 'Widget', 'on' => request()->routeIs('banimark.admin.widget')]) !!}
            <span class="lbl">Account</span>
            {!! Layout::navLink(['href' => route('banimark.admin.escalation'), 'icon' => 'escalation', 'label' => 'Notifications', 'on' => request()->routeIs('banimark.admin.escalation')]) !!}
            {!! Layout::navLink(['href' => route('banimark.admin.agents'), 'icon' => 'staff', 'label' => 'Staff', 'on' => request()->routeIs('banimark.admin.agents')]) !!}
            {!! Layout::navLink(['href' => route('banimark.admin.security'), 'icon' => 'shield', 'label' => 'Security', 'on' => request()->routeIs('banimark.admin.security')]) !!}
            {!! Layout::navLink(['href' => route('banimark.admin.license'), 'icon' => 'license', 'label' => 'License', 'on' => request()->routeIs('banimark.admin.license')]) !!}
            @if(app(\Banimark\Auth\AgentAuth::class)->isOwner())
                {!! Layout::navLink(['href' => route('banimark.admin.changelog'), 'icon' => 'bolt', 'label' => 'Changelog', 'on' => request()->routeIs('banimark.admin.changelog')]) !!}
            @endif
        </nav>
        <div class="bm-side-foot">
            <form method="post" action="{{ route('banimark.admin.logout') }}">@csrf
                <button type="submit" class="btn-ghost" style="width:100%;justify-content:flex-start;">{!! Icons::get('logout', 16) !!} Sign out</button>
            </form>
        </div>
    </aside>
    <div class="bm-scrim"></div>

    <main class="bm-main">
        <header class="bm-top">
            {!! Layout::burger() !!}
            <div>
                <h1>@yield('title', 'Admin')</h1>
                @hasSection('sub')<div class="sub">@yield('sub')</div>@endif
            </div>
            <div class="spacer"></div>
            @yield('actions')
            {!! Layout::soundButton() !!}
            {!! Layout::themeButton() !!}
        </header>
        <div class="bm-wrap">
            @if(session('bm_ok'))<div class="flash-ok">{!! Icons::get('check', 16) !!}<span>{{ session('bm_ok') }}</span></div>@endif
            @if(session('bm_error'))<div class="flash-err">{!! Icons::get('escalation', 16) !!}<span>{{ session('bm_error') }}</span></div>@endif
            @yield('content')
        </div>
    </main>
</div>
{!! Layout::scripts() !!}
</body>
</html>
