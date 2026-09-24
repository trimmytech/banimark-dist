@extends('banimark::admin.layout')
@section('title', 'AI providers')
@section('sub', 'Bring your own key — it never leaves your server')
@section('content')
    {{-- ONE body for both runtimes: Ui\Pages::providers (Gemini only for now -
         the offer and the tested models live in Ai\ProviderPresets) --}}
    {!! $body !!}
@endsection
