<?php

use Illuminate\Support\Facades\Route;

Route::get('/activity', [\App\Http\Controllers\Api\ActivityController::class, 'index'])
    ->name('api.activity.index');
