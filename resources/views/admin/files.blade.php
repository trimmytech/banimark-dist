@extends('banimark::admin.layout')
@section('title', 'Files')
@section('sub', 'Where files shared in a chat are kept')
@section('content')
    {{-- ONE body for both runtimes: Ui\Pages::files --}}
    {!! \Banimark\Ui\Pages::files($s, [
        'problem' => $problem,
        'default_dir' => $defaultDir,
        'stats' => $stats,
        's3_lock' => $locks['s3'],
        'upgrade_url' => $upgradeUrl,
        'csrf' => csrf_field()->toHtml(),
        'test' => session('bm_files_test') ? ['ok' => (bool) session('bm_files_ok'), 'message' => (string) session('bm_files_test')] : null,
        'urls' => ['save' => route('banimark.admin.files.save'), 'test' => route('banimark.admin.files.test')],
    ]) !!}
@endsection
