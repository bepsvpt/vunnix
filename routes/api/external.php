<?php

use Illuminate\Support\Facades\Route;

Route::prefix('ext')
    ->middleware(['auth.api_key_or_session', 'revalidate', 'throttle:api_key'])
    ->group(function (): void {
        Route::get('/tasks', [\App\Http\Controllers\Api\ExternalTaskController::class, 'index'])
            ->name('api.ext.tasks.index');
        Route::get('/tasks/{task}', [\App\Http\Controllers\Api\ExternalTaskController::class, 'show'])
            ->name('api.ext.tasks.show');
        Route::post('/tasks/review', [\App\Http\Controllers\Api\ExternalTaskController::class, 'triggerReview'])
            ->name('api.ext.tasks.review');
        Route::get('/metrics/summary', [\App\Http\Controllers\Api\ExternalMetricsController::class, 'summary'])
            ->name('api.ext.metrics.summary');
        Route::get('/activity', [\App\Http\Controllers\Api\ExternalActivityController::class, 'index'])
            ->name('api.ext.activity.index');
        Route::get('/projects', [\App\Http\Controllers\Api\ExternalProjectController::class, 'index'])
            ->name('api.ext.projects.index');
    });
