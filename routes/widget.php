<?php

use Banimark\Laravel\WidgetController;
use Illuminate\Support\Facades\Route;

Route::get('/banimark/widget.js', [WidgetController::class, 'script'])->name('banimark.widget');
Route::get('/banimark/chat-page', [WidgetController::class, 'page'])->name('banimark.chat.page');
Route::get('/banimark/widget/appearance', [WidgetController::class, 'appearance'])->name('banimark.widget.appearance');
Route::get('/banimark/chat/poll', [WidgetController::class, 'poll'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat.poll');
Route::get('/banimark/chat/history', [WidgetController::class, 'history'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat.history');
Route::post('/banimark/chat', [WidgetController::class, 'chat'])
    ->middleware('throttle:banimark-chat')
    ->name('banimark.chat');
