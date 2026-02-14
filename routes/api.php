<?php

use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\TaskResultController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Runner ↔ Vunnix Interface (T29)
    // Authenticated via task-scoped HMAC bearer token — no session or CSRF needed
    Route::post('/tasks/{task}/result', TaskResultController::class)
        ->middleware('task.token')
        ->name('api.tasks.result');

    // Chat API (T47)
    // Session-authenticated routes for conversation management
    Route::middleware('auth')->group(function () {
        Route::get('/conversations', [ConversationController::class, 'index'])
            ->name('api.conversations.index');
        Route::post('/conversations', [ConversationController::class, 'store'])
            ->name('api.conversations.store');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
            ->name('api.conversations.show');
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage'])
            ->name('api.conversations.messages.store');
        Route::patch('/conversations/{conversation}/archive', [ConversationController::class, 'archive'])
            ->name('api.conversations.archive');
    });

});
