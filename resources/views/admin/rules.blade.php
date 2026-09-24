@extends('banimark::admin.layout')
@section('title', 'Rules')
@section('sub', 'How your assistant behaves — organised in folders, applied in order')
@section('content')
    {{-- ONE body for both runtimes: Ui\Pages::rules --}}
    {!! \Banimark\Ui\Pages::rules($folders, [
        'csrf' => csrf_field()->toHtml(),
        'urls' => [
            'folder' => route('banimark.admin.rules.folder'),
            'folder_move' => route('banimark.admin.rules.folder.move'),
            'folder_delete' => route('banimark.admin.rules.folder.delete'),
            'save' => route('banimark.admin.rules.save'),
            'move' => route('banimark.admin.rules.move'),
            'delete' => route('banimark.admin.rules.delete'),
        ],
    ]) !!}
@endsection
