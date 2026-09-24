@php use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Conversation')
@section('sub', 'Replying takes over — the AI stays silent until you hand it back')
@section('actions')
    <a class="btn2 btn-sm" href="{{ route('banimark.admin.inbox') }}">{!! Icons::get('back', 15) !!} Inbox</a>
@endsection
@section('content')
    {{-- ONE body for both runtimes: Ui\Pages::conversation --}}
    {!! $body !!}
@endsection
