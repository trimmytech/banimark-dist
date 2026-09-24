@php use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'Dashboard')
@section('sub', 'How your AI desk is performing')
@section('actions')
    <a class="btn btn-sm" href="{{ route('banimark.admin.inbox') }}">{!! Icons::get('inbox', 15) !!} Open inbox</a>
@endsection
@section('content')
    {{-- ONE body for both runtimes: Ui\Pages::dashboard --}}
    {!! $body !!}
@endsection
