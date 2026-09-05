@php use Banimark\Ui\Icons; use Banimark\Licensing\Master; @endphp
@extends('banimark::admin.layout')
@section('title', 'Changelog')
@section('sub', 'What is new in Banimark')
@section('content')

    {{-- ONE advisory, not one per release --}}
    @if($updates['outdated'])
        <div class="bm-card" style="border-color:color-mix(in srgb, var(--warn) 40%, transparent)">
            <div class="bm-sec-h">
                <div class="row" style="gap:10px;align-items:flex-start">
                    {!! Icons::get('bolt', 18) !!}
                    <div>
                        <h2 style="margin:0">Update available — {{ $updates['latest'] }}</h2>
                        <div class="muted">You are running {{ Master::PACKAGE_VERSION }}. Run this in your project, then re-run the installer once:</div>
                    </div>
                </div>
            </div>
            <textarea readonly rows="2" data-select-all>{{ $updates['update_command'] }}
php artisan banimark:install</textarea>
        </div>
    @else
        <div class="bm-card">
            <div class="row" style="gap:10px">
                {!! Icons::get('check', 17) !!}
                <div>
                    <b>You are up to date</b>
                    {{-- keep directives on their own lines: Blade will not compile
                         an @directive that is glued to the preceding word --}}
                    <div class="muted">
                        Running {{ Master::PACKAGE_VERSION }}
                        @if(!$updates['ok'])
                            — could not reach Banimark to check for newer releases
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="bm-card">
        <h2>Release notes</h2>
        @forelse($updates['releases'] as $r)
            <div style="border-top:1px solid var(--border);padding:14px 0 2px">
                <div class="row" style="gap:8px">
                    <b>{{ $r['version'] }}</b>
                    @if($r['version'] === Master::PACKAGE_VERSION)
                        <span class="pill active">INSTALLED</span>
                    @endif
                    <span class="muted">{{ $r['released_at'] }}</span>
                </div>
                <div class="muted" style="white-space:pre-wrap;margin-top:5px">{{ $r['notes'] }}</div>
            </div>
        @empty
            <div class="muted" style="margin-top:8px">No release notes available right now.</div>
        @endforelse
    </div>
@endsection
