<?php

use Banimark\Laravel\WidgetController;
use Illuminate\Support\Facades\Route;

Route::get('/banimark/widget.js', [WidgetController::class, 'script'])->name('banimark.widget');
Route::get('/banimark/chat-page', [WidgetController::class, 'page'])->name('banimark.chat.page');
// the header logo: public, typed by its bytes (Http\WidgetLogo)
Route::get('/banimark/widget/logo', [WidgetController::class, 'logo'])->name('banimark.widget.logo');
Route::get('/banimark/widget/appearance', [WidgetController::class, 'appearance'])->name('banimark.widget.appearance');
Route::get('/banimark/chat/poll', [WidgetController::class, 'poll'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat.poll');
Route::get('/banimark/chat/history', [WidgetController::class, 'history'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat.history');
// the visitor deletes their own conversation (soft - see Http\DeleteEndpoint)
Route::post('/banimark/chat/delete', [WidgetController::class, 'deleteChat'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat.delete');
Route::post('/banimark/upload', [WidgetController::class, 'upload'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.upload');
// the token in the path IS the permission, so an <img> works with no session
Route::get('/banimark/file/{token}', [WidgetController::class, 'file'])
    ->where('token', '[a-f0-9]{32}')
    ->name('banimark.file');

Route::post('/banimark/chat', [WidgetController::class, 'chat'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat');
