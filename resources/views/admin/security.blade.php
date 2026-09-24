@extends('banimark::admin.layout')
@section('title', 'Security')
@section('sub', 'Two-factor authentication for your own account')
@section('content')
    {{-- ONE body for both runtimes: Ui\Pages::security --}}
    {!! \Banimark\Ui\Pages::security([
        'enabled' => $enabled,
        'pending' => $pendingSecret,
        'required' => $required,
        'uri' => $uri,
        'csrf' => csrf_field()->toHtml(),
        'urls' => [
            'begin' => route('banimark.admin.security.begin'),
            'confirm' => route('banimark.admin.security.confirm'),
            'disable' => route('banimark.admin.security.disable'),
            'staff' => route('banimark.admin.agents'),
        ],
    ]) !!}
@endsection
