<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', HealthCheckController::class);

// GitLab OAuth routes (T7)
Route::get('/auth/redirect', [AuthController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/gitlab/callback', [AuthController::class, 'callback'])->name('auth.callback');
Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('auth.logout');

// GitLab webhook endpoint (T12)
// CSRF excluded in bootstrap/app.php — authenticated via X-Gitlab-Token middleware
Route::post('/webhook', WebhookController::class)
    ->middleware('webhook.verify')
    ->name('webhook');
