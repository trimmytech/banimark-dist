@php use Banimark\Ui\Icons; use Banimark\Licensing\Master; @endphp
@extends('banimark::admin.layout')
@section('title', 'Changelog')
@section('sub', 'What is new in Banimark')
@section('content')

    {{-- ONE advisory, not one per release. The card is shared with the
         standalone panel so both runtimes tell an owner the same story. --}}
    {!! \Banimark\Ui\Pages::updateCard($status, [
        'update' => route('banimark.admin.update'),
        'schema' => route('banimark.admin.update.db'),
        'rollback' => route('banimark.admin.update.rollback'),
        'recheck' => route('banimark.admin.update.check'),
        'switch' => route('banimark.admin.update.switch'),
        'fetch' => route('banimark.admin.update.fetch'),
        'apply' => route('banimark.admin.update.apply'),
    ], csrf_field()) !!}

    {!! \Banimark\Ui\Pages::releaseNotes($updates['releases'] ?? [], Master::PACKAGE_VERSION) !!}
@endsection
