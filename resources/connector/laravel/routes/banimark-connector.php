<?php

/*
 * Banimark connector routes
 * -------------------------
 * Require this file from routes/api.php (NOT routes/web.php: the API routes
 * have no CSRF check and no session, which is what a signed machine-to-machine
 * call needs). Add this one line at the bottom of routes/api.php:
 *
 *     require __DIR__.'/banimark-connector.php';
 *
 * Laravel prefixes API routes with /api, so the connector's address is
 *
 *     https://your-site.example/api/banimark-connector
 *
 * Open it in a browser once: it shows the exact address to paste into
 * Banimark -> Tools. (On Laravel 11+ run `php artisan install:api` first if
 * routes/api.php does not exist yet.)
 */

use Illuminate\Support\Facades\Route;

Route::get('/banimark-connector', [\App\Http\Controllers\BanimarkConnectorController::class, 'status']);
Route::post('/banimark-connector', [\App\Http\Controllers\BanimarkConnectorController::class, 'handle']);
