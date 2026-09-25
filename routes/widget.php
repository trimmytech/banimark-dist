<?php

use Banimark\Laravel\WidgetController;
use Illuminate\Support\Facades\Route;

// every visitor-facing address lives under ONE prefix the owner may change
// (BANIMARK_WIDGET_PATH, default "banimark"): widget.js, the chat API, the
// chat link and file links. Route NAMES never change - use route('banimark.chat').
Route::prefix(\Banimark\Laravel\Urls::widget())->group(function () {

Route::get('/widget.js', [WidgetController::class, 'script'])->name('banimark.widget');
Route::get('/chat-page', [WidgetController::class, 'page'])->name('banimark.chat.page');
// the header logo: public, typed by its bytes (Http\WidgetLogo)
Route::get('/widget/logo', [WidgetController::class, 'logo'])->name('banimark.widget.logo');
Route::get('/widget/appearance', [WidgetController::class, 'appearance'])->name('banimark.widget.appearance');
Route::get('/chat/poll', [WidgetController::class, 'poll'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat.poll');
Route::get('/chat/history', [WidgetController::class, 'history'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat.history');
// the visitor deletes their own conversation (soft - see Http\DeleteEndpoint)
Route::post('/chat/delete', [WidgetController::class, 'deleteChat'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat.delete');
Route::post('/upload', [WidgetController::class, 'upload'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.upload');
// the token in the path IS the permission, so an <img> works with no session
Route::get('/file/{token}', [WidgetController::class, 'file'])
    ->where('token', '[a-f0-9]{32}')
    ->name('banimark.file');

Route::post('/chat', [WidgetController::class, 'chat'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat');
});
