@extends('banimark::admin.layout')
@section('title', 'Notifications')
@section('sub', 'Working hours, handover alerts, outgoing email and visitor follow-ups')
@section('content')
    {{-- ONE body for both runtimes: Ui\Pages::notifications --}}
    {!! \Banimark\Ui\Pages::notifications($s, [
        'hours' => route('banimark.admin.escalation.hours'),
        'save' => route('banimark.admin.escalation.save'),
        'test' => route('banimark.admin.escalation.test'),
        'quick' => route('banimark.admin.quick.save'),
    ], csrf_field()->toHtml()) !!}
@endsection
