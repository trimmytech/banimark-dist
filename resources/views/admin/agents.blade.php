@extends('banimark::admin.layout')
@section('title', 'Staff')
@section('sub', 'Invite colleagues, decide what each of them can do')
@section('content')
    @unless($isOwner)
        <div class="bm-card"><div class="empty"><b>Owners only</b><div>Only an owner can manage staff accounts.</div></div></div>
    @else
        {{-- ONE body for both runtimes: Ui\Pages::staff --}}
        {!! \Banimark\Ui\Pages::staff($rows, [
            'me_id' => $meId,
            'require_2fa' => $require2fa,
            'seats' => $seats,
            'upgrade_url' => $upgradeUrl,
            'csrf' => csrf_field()->toHtml(),
            'urls' => [
                'save' => route('banimark.admin.agents.save'),
                'delete' => route('banimark.admin.agents.delete'),
                'reinvite' => route('banimark.admin.agents.reinvite'),
                'permissions' => route('banimark.admin.agents.permissions'),
                'totp_reset' => route('banimark.admin.agents.totp.reset'),
                'totp_require' => route('banimark.admin.agents.totp.require'),
                'security' => route('banimark.admin.security'),
            ],
        ]) !!}
    @endunless
@endsection
