@extends('banimark::admin.layout')
@section('title', 'Widget')
@section('sub', 'How the chat looks and greets on your site, in a link and in your app')
@section('content')
    {{-- ONE body for both runtimes: Ui\Pages::widget --}}
    {!! $body !!}
@endsection
