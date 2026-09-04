<?php

use Banimark\Laravel\Admin\PanelController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [PanelController::class, 'login'])->name('banimark.admin.login');
Route::post('/login', [PanelController::class, 'doLogin'])->name('banimark.admin.login.post');
Route::post('/logout', [PanelController::class, 'logout'])->name('banimark.admin.logout');
Route::get('/', [PanelController::class, 'dashboard'])->name('banimark.admin.dashboard');
Route::get('/inbox', [PanelController::class, 'inbox'])->name('banimark.admin.inbox');
Route::get('/staff', [PanelController::class, 'agents'])->name('banimark.admin.agents');
Route::post('/staff', [PanelController::class, 'saveAgent'])->name('banimark.admin.agents.save');
Route::post('/staff/delete', [PanelController::class, 'deleteAgent'])->name('banimark.admin.agents.delete');
Route::get('/escalation', [PanelController::class, 'escalation'])->name('banimark.admin.escalation');
Route::post('/escalation', [PanelController::class, 'saveEscalation'])->name('banimark.admin.escalation.save');
Route::post('/escalation/test', [PanelController::class, 'testEmail'])->name('banimark.admin.escalation.test');
Route::get('/conversation/{sessionId}', [PanelController::class, 'conversation'])->name('banimark.admin.conversation');
Route::post('/conversation/{sessionId}/reply', [PanelController::class, 'reply'])->name('banimark.admin.conversation.reply');
Route::post('/conversation/{sessionId}/mode', [PanelController::class, 'setMode'])->name('banimark.admin.conversation.mode');
Route::get('/providers', [PanelController::class, 'providers'])->name('banimark.admin.providers');
Route::post('/providers', [PanelController::class, 'saveProvider'])->name('banimark.admin.providers.save');
Route::post('/providers/delete', [PanelController::class, 'deleteProvider'])->name('banimark.admin.providers.delete');
Route::get('/rules', [PanelController::class, 'rules'])->name('banimark.admin.rules');
Route::post('/rules', [PanelController::class, 'saveRule'])->name('banimark.admin.rules.save');
Route::post('/rules/delete', [PanelController::class, 'deleteRule'])->name('banimark.admin.rules.delete');
Route::get('/tools', [PanelController::class, 'tools'])->name('banimark.admin.tools');
Route::post('/tools', [PanelController::class, 'saveTool'])->name('banimark.admin.tools.save');
Route::post('/tools/delete', [PanelController::class, 'deleteTool'])->name('banimark.admin.tools.delete');
Route::get('/widget', [PanelController::class, 'widget'])->name('banimark.admin.widget');
Route::get('/license', [PanelController::class, 'license'])->name('banimark.admin.license');
Route::post('/license', [PanelController::class, 'saveLicense'])->name('banimark.admin.license.save');
Route::post('/widget', [PanelController::class, 'saveWidget'])->name('banimark.admin.widget.save');
