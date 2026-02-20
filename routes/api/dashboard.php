<?php

use Illuminate\Support\Facades\Route;

Route::get('/dashboard/overview', \App\Http\Controllers\Api\DashboardOverviewController::class)
    ->name('api.dashboard.overview');
Route::get('/dashboard/quality', \App\Http\Controllers\Api\DashboardQualityController::class)
    ->name('api.dashboard.quality');
Route::get('/prompt-versions', \App\Http\Controllers\Api\PromptVersionController::class)
    ->name('api.prompt-versions');
Route::get('/dashboard/pm-activity', \App\Http\Controllers\Api\DashboardPMActivityController::class)
    ->name('api.dashboard.pm-activity');
Route::get('/dashboard/designer-activity', \App\Http\Controllers\Api\DashboardDesignerActivityController::class)
    ->name('api.dashboard.designer-activity');
Route::get('/dashboard/efficiency', \App\Http\Controllers\Api\DashboardEfficiencyController::class)
    ->name('api.dashboard.efficiency');
Route::get('/dashboard/cost', \App\Http\Controllers\Api\DashboardCostController::class)
    ->name('api.dashboard.cost');
Route::get('/dashboard/adoption', \App\Http\Controllers\Api\DashboardAdoptionController::class)
    ->name('api.dashboard.adoption');

Route::get('/dashboard/cost-alerts', [\App\Http\Controllers\Api\CostAlertController::class, 'index'])
    ->name('api.dashboard.cost-alerts.index');
Route::patch('/dashboard/cost-alerts/{costAlert}/acknowledge', [\App\Http\Controllers\Api\CostAlertController::class, 'acknowledge'])
    ->name('api.dashboard.cost-alerts.acknowledge');
Route::get('/dashboard/overreliance-alerts', [\App\Http\Controllers\Api\OverrelianceAlertController::class, 'index'])
    ->name('api.dashboard.overreliance-alerts.index');
Route::patch('/dashboard/overreliance-alerts/{overrelianceAlert}/acknowledge', [\App\Http\Controllers\Api\OverrelianceAlertController::class, 'acknowledge'])
    ->name('api.dashboard.overreliance-alerts.acknowledge');
Route::get('/dashboard/infrastructure-alerts', [\App\Http\Controllers\Api\InfrastructureAlertController::class, 'index'])
    ->name('api.dashboard.infrastructure-alerts.index');
Route::patch('/dashboard/infrastructure-alerts/{alertEvent}/acknowledge', [\App\Http\Controllers\Api\InfrastructureAlertController::class, 'acknowledge'])
    ->name('api.dashboard.infrastructure-alerts.acknowledge');

Route::get('/projects/{project}/memory', [\App\Http\Controllers\Api\ProjectMemoryController::class, 'index'])
    ->name('api.projects.memory.index');
Route::get('/projects/{project}/memory/stats', [\App\Http\Controllers\Api\ProjectMemoryController::class, 'stats'])
    ->name('api.projects.memory.stats');
Route::delete('/projects/{project}/memory/{memoryEntry}', [\App\Http\Controllers\Api\ProjectMemoryController::class, 'destroy'])
    ->name('api.projects.memory.destroy');

Route::prefix('/projects/{project}/health')->group(function (): void {
    Route::get('/trends', [\App\Http\Controllers\Api\DashboardHealthController::class, 'trends'])
        ->name('api.projects.health.trends');
    Route::get('/summary', [\App\Http\Controllers\Api\DashboardHealthController::class, 'summary'])
        ->name('api.projects.health.summary');
    Route::get('/alerts', [\App\Http\Controllers\Api\DashboardHealthController::class, 'alerts'])
        ->name('api.projects.health.alerts');
});
