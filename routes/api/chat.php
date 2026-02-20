<?php

use Illuminate\Support\Facades\Route;

Route::get('/user', function (): \App\Http\Resources\UserResource {
    return new \App\Http\Resources\UserResource(request()->user());
})->name('api.user');

Route::get('/conversations', [\App\Http\Controllers\Api\ConversationController::class, 'index'])
    ->name('api.conversations.index');
Route::post('/conversations', [\App\Http\Controllers\Api\ConversationController::class, 'store'])
    ->name('api.conversations.store');
Route::get('/conversations/{conversation}', [\App\Http\Controllers\Api\ConversationController::class, 'show'])
    ->name('api.conversations.show');
Route::post('/conversations/{conversation}/messages', [\App\Http\Controllers\Api\ConversationController::class, 'sendMessage'])
    ->name('api.conversations.messages.store');
Route::post('/conversations/{conversation}/stream', [\App\Http\Controllers\Api\ConversationController::class, 'stream'])
    ->name('api.conversations.stream');
Route::post('/conversations/{conversation}/projects', [\App\Http\Controllers\Api\ConversationController::class, 'addProject'])
    ->name('api.conversations.projects.store');
Route::patch('/conversations/{conversation}/archive', [\App\Http\Controllers\Api\ConversationController::class, 'archive'])
    ->name('api.conversations.archive');

Route::get('/tasks/{task}/view', \App\Http\Controllers\Api\TaskResultViewController::class)
    ->name('api.tasks.view');
