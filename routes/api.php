<?php

use App\Http\Controllers\TaskResultController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Runner ↔ Vunnix Interface (T29)
    // Authenticated via task-scoped HMAC bearer token — no session or CSRF needed
    Route::post('/tasks/{task}/result', TaskResultController::class)
        ->middleware('task.token')
        ->name('api.tasks.result');

});
