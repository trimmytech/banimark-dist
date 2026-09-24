@extends('banimark::admin.layout')
@section('title', 'Tools')
@section('sub', 'Let the assistant look things up in your own data — safely, read-only')
@section('content')
    {{-- ONE body for both runtimes: Ui\Pages::tools --}}
    {!! $body !!}
@endsection
