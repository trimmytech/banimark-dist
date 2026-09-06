@php use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'License')
@section('sub', $active ? 'Your Banimark licence' : 'Activate your Banimark licence')
@section('content')
    @if($lock)
        <div class="flash-err">{!! Icons::get('shield', 16) !!}<span><b>Admin locked.</b> {{ $lock['message'] }}
            @if($supportUrl !== '') <a href="{{ $supportUrl }}" target="_blank" rel="noopener">Buy or renew a licence</a>.@endif
            @if($supportEmail !== '') Need help? <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>@endif
        </span></div>
    @endif

    @if($active)
        {{-- ============ ACTIVE: show the licence, not a form ============ --}}
        <div class="bm-grid c2">
            <div class="bm-card">
                <div class="bm-sec-h">
                    <div class="row" style="gap:10px"><span class="avatar">{!! Icons::get('license', 16) !!}</span>
                        <div><h2 style="margin:0">{{ $isTrial ? 'Free trial' : ucfirst($details['plan'] ?? 'Licence') }} <span class="pill active">ACTIVE</span></h2>
                            <div class="muted">{{ $details['customer'] ?? '' }}</div></div>
                    </div>
                </div>
                @if($isTrial && $daysLeft !== null)
                    <div style="margin:14px 0 6px">
                        <div class="row" style="justify-content:space-between"><b>{{ max(0, $daysLeft) }} day{{ $daysLeft === 1 ? '' : 's' }} left</b><span class="muted">ends {{ date('j M Y', strtotime($expiresAt)) }}</span></div>
                        <div class="hbar" style="margin-top:6px"><span class="fill" style="width:{{ min(100, max(4, (int) round(100 * max(0, $daysLeft) / max(1, (int) ceil((strtotime($expiresAt.' 23:59:59') - strtotime($details['issued_at'] ?? 'now')) / 86400))))) }}%;display:block"></span></div>
                    </div>
                    <div class="muted">When the trial ends the admin panel locks until you enter a purchased key. Your chat widget keeps working.</div>
                    @if($supportUrl !== '')<div style="margin-top:12px"><a class="btn" href="{{ $supportUrl }}" target="_blank" rel="noopener">{!! Icons::get('key', 15) !!} Buy a licence</a></div>@endif
                @endif
                <dl class="bm-dl" style="margin-top:14px">
                    <dt>Key</dt><dd class="mono">{{ $maskedKey }}</dd>
                    <dt>Site</dt><dd>{{ $details['domain'] ?? request()->getHost() }}</dd>
                    <dt>Modules</dt><dd>@foreach($modules as $m)<span class="pill active">{{ strtoupper(str_replace('-', ' ', $m)) }}</span> @endforeach</dd>
                    <dt>Issued</dt><dd>{{ !empty($details['issued_at']) ? date('j M Y', strtotime($details['issued_at'])) : '—' }}</dd>
                    <dt>Expires</dt><dd>{{ $expiresAt !== '' ? date('j M Y', strtotime($expiresAt)).($daysLeft !== null ? ' · '.max(0, $daysLeft).' days' : '') : 'Never — renewals keep updates flowing' }}</dd>
                    <dt>Last verified</dt><dd>{{ $lastPing > 0 ? date('j M Y, H:i', $lastPing) : '—' }}
                        <span class="muted">· re-checked {{ $checkInterval >= 86400 ? 'every '.round($checkInterval / 86400).' day'.($checkInterval >= 172800 ? 's' : '') : ($checkInterval >= 3600 ? 'every '.round($checkInterval / 3600).' hour'.($checkInterval >= 7200 ? 's' : '') : 'every '.round($checkInterval / 60).' minutes') }}</span></dd>
                    @if($supportEmail !== '')<dt>Support</dt><dd><a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></dd>@endif
                </dl>
                <form method="post" action="{{ route('banimark.admin.license.recheck') }}" style="margin-top:12px">@csrf
                    <button type="submit" class="btn2 btn-sm">Re-check with HQ now</button>
                </form>
            </div>

            <div class="bm-card">
                @if($isTrial)
                    <h2>Have a licence key?</h2>
                    <div class="muted">Enter your purchased key to replace the trial. Everything you have set up stays.</div>
                    <form method="post" action="{{ route('banimark.admin.license.save') }}" style="margin-top:10px">@csrf
                        <label>License key</label>
                        <input type="text" name="license_key" value="" placeholder="BM-XXXX-XXXX-XXXX-XXXX" class="mono">
                        <div style="margin-top:14px"><button type="submit">{!! Icons::get('check', 15) !!} Activate key</button></div>
                    </form>
                @else
                    <h2>Your key is locked</h2>
                    <div class="muted">An active licence is bound to this site, so the key cannot be changed here — that is what stops a key walking to another install. It becomes editable if the licence expires or is revoked. Moving servers? {{ $supportEmail !== '' ? 'Email '.$supportEmail : 'Contact support' }} and we release it.</div>
                @endif
                <div class="divider"></div>
                <div class="row" style="align-items:flex-start;gap:9px">
                    {!! Icons::get('widget', 16) !!}
                    <div class="muted">Your chat widget keeps working no matter what your licence says. Only this admin panel is gated.</div>
                </div>
            </div>
        </div>
    @else
        {{-- ============ NOT ACTIVE: trial or key ============ --}}
        <div class="bm-grid c2">
            @if($canTrial)
            <div class="bm-card">
                <div class="row" style="gap:10px"><span class="avatar">{!! Icons::get('bolt', 16) !!}</span>
                    <div><h2 style="margin:0">Start your free trial</h2><div class="muted">Full access, no card. Your vendor sets the length.</div></div></div>
                <p style="margin:12px 0">One trial per site. When it ends, the panel locks until you enter a purchased key — the chat widget keeps working throughout.</p>
                <form method="post" action="{{ route('banimark.admin.license.trial') }}">@csrf
                    <button type="submit">{!! Icons::get('bolt', 15) !!} Start free trial</button>
                </form>
            </div>
            @endif
            <div class="bm-card">
                <div class="bm-sec-h">
                    <div><h2>{{ $canTrial ? 'Or enter a licence key' : 'Licence key' }}</h2>
                        <div class="muted">Checked once a day from this panel. The check sends only your key, this site's URL and version numbers — never your data.</div></div>
                    <div class="spacer"></div>
                    @if($status !== '')<span class="pill {{ $status === 'expired' ? 'expired' : 'revoked' }}">{{ strtoupper($status) }}</span>@endif
                </div>
                @if($status === 'expired' && $isTrial)
                    <div class="flash-warn" style="margin-top:10px">{!! Icons::get('escalation', 16) !!}<span>Your free trial ended {{ $expiresAt !== '' ? 'on '.date('j M Y', strtotime($expiresAt)) : '' }}. Enter a purchased key to continue.{!! $supportUrl !== '' ? ' <a href="'.e($supportUrl).'" target="_blank" rel="noopener">Buy a licence</a>.' : '' !!}</span></div>
                @endif
                <form method="post" action="{{ route('banimark.admin.license.save') }}">@csrf
                    <label>License key</label>
                    <input type="text" name="license_key" value="{{ $key }}" placeholder="BM-XXXX-XXXX-XXXX-XXXX" class="mono">
                    <div style="margin-top:16px"><button type="submit">{!! Icons::get('check', 15) !!} Save &amp; check now</button></div>
                </form>
                @if($lastPing > 0)<div class="muted" style="margin-top:8px">Last checked {{ date('d M Y, H:i', $lastPing) }}</div>@endif
                <div class="divider"></div>
                <div class="row" style="align-items:flex-start;gap:9px">{!! Icons::get('widget', 16) !!}<div class="muted">Your chat widget keeps working no matter what your licence says. Only this admin panel is gated.</div></div>
            </div>
        </div>
    @endif
@endsection
