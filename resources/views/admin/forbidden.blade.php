@php use Banimark\Ui\Icons; @endphp
@extends('banimark::admin.layout')
@section('title', 'No access')
@section('sub', 'This page is not part of your permissions')
@section('content')
    <div class="bm-card" style="max-width:560px">
        <div class="row" style="gap:10px"><span class="avatar">{!! Icons::get('shield', 16) !!}</span>
            <div><h2 style="margin:0">You don't have access to this</h2>
                <div class="muted">{{ $requirement === 'owner' ? 'Only an owner can open this page.' : 'An owner can grant it under Staff → Access.' }}</div></div>
        </div>
        <div class="row" style="margin-top:16px;gap:8px">
            <a class="btn2 btn-sm" href="{{ route('banimark.admin.inbox') }}">{!! Icons::get('inbox', 14) !!} Inbox</a>
            <a class="btn-ghost btn-sm" href="{{ route('banimark.admin.dashboard') }}">Dashboard</a>
        </div>
    </div>
@endsection
