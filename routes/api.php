<?php

use App\Http\Controllers\TaskResultController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {

    // Runner ↔ Vunnix Interface (T29)
    // Authenticated via task-scoped HMAC bearer token — no session or CSRF needed
    Route::post('/tasks/{task}/result', TaskResultController::class)
        ->middleware('task.token')
        ->name('api.tasks.result');

    Route::middleware(['auth', 'revalidate'])->group(function (): void {
        require __DIR__.'/api/chat.php';
        require __DIR__.'/api/activity.php';
        require __DIR__.'/api/dashboard.php';
        require __DIR__.'/api/admin.php';
    });

    require __DIR__.'/api/external.php';
});
