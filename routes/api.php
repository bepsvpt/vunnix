<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AdminProjectController;
use App\Http\Controllers\Api\AdminRoleController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DashboardOverviewController;
use App\Http\Controllers\Api\DashboardDesignerActivityController;
use App\Http\Controllers\Api\DashboardAdoptionController;
use App\Http\Controllers\Api\DashboardCostController;
use App\Http\Controllers\Api\DashboardEfficiencyController;
use App\Http\Controllers\Api\DashboardPMActivityController;
use App\Http\Controllers\Api\DashboardQualityController;
use App\Http\Controllers\Api\TaskResultViewController;
use App\Http\Controllers\TaskResultController;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Runner ↔ Vunnix Interface (T29)
    // Authenticated via task-scoped HMAC bearer token — no session or CSRF needed
    Route::post('/tasks/{task}/result', TaskResultController::class)
        ->middleware('task.token')
        ->name('api.tasks.result');

    // Auth state endpoint (T62)
    // Returns authenticated user's profile, projects, roles, and permissions
    Route::middleware('auth')->group(function () {
        Route::get('/user', function () {
            return new UserResource(request()->user());
        })->name('api.user');
    });

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

        // SSE streaming endpoint (T48)
        // Sends user message and streams AI response as Server-Sent Events
        Route::post('/conversations/{conversation}/stream', [ConversationController::class, 'stream'])
            ->name('api.conversations.stream');

        // Cross-project support (T64, D28)
        Route::post('/conversations/{conversation}/projects', [ConversationController::class, 'addProject'])
            ->name('api.conversations.projects.store');

        Route::patch('/conversations/{conversation}/archive', [ConversationController::class, 'archive'])
            ->name('api.conversations.archive');

        // Task result view endpoint (T70)
        // Returns full task result data for rendering result cards on page reload
        Route::get('/tasks/{task}/view', TaskResultViewController::class)
            ->name('api.tasks.view');

        // Dashboard activity feed (T75)
        Route::get('/activity', [ActivityController::class, 'index'])
            ->name('api.activity.index');

        // Dashboard overview stats (T76)
        Route::get('/dashboard/overview', DashboardOverviewController::class)
            ->name('api.dashboard.overview');

        // Dashboard quality metrics (T77)
        Route::get('/dashboard/quality', DashboardQualityController::class)
            ->name('api.dashboard.quality');

        // Dashboard PM activity metrics (T78)
        Route::get('/dashboard/pm-activity', DashboardPMActivityController::class)
            ->name('api.dashboard.pm-activity');

        // Dashboard designer activity metrics (T79)
        Route::get('/dashboard/designer-activity', DashboardDesignerActivityController::class)
            ->name('api.dashboard.designer-activity');

        // Dashboard efficiency metrics (T80)
        Route::get('/dashboard/efficiency', DashboardEfficiencyController::class)
            ->name('api.dashboard.efficiency');

        // Dashboard cost metrics (T81) — admin-only via RBAC (D29)
        Route::get('/dashboard/cost', DashboardCostController::class)
            ->name('api.dashboard.cost');

        // Dashboard adoption metrics (T82)
        Route::get('/dashboard/adoption', DashboardAdoptionController::class)
            ->name('api.dashboard.adoption');

        // Admin project management (T88)
        Route::get('/admin/projects', [AdminProjectController::class, 'index'])
            ->name('api.admin.projects.index');
        Route::get('/admin/projects/{project}', [AdminProjectController::class, 'show'])
            ->name('api.admin.projects.show');
        Route::post('/admin/projects/{project}/enable', [AdminProjectController::class, 'enable'])
            ->name('api.admin.projects.enable');
        Route::post('/admin/projects/{project}/disable', [AdminProjectController::class, 'disable'])
            ->name('api.admin.projects.disable');

        // Admin role management (T89)
        Route::get('/admin/roles', [AdminRoleController::class, 'index'])
            ->name('api.admin.roles.index');
        Route::get('/admin/permissions', [AdminRoleController::class, 'permissions'])
            ->name('api.admin.permissions.index');
        Route::post('/admin/roles', [AdminRoleController::class, 'store'])
            ->name('api.admin.roles.store');
        Route::put('/admin/roles/{role}', [AdminRoleController::class, 'update'])
            ->name('api.admin.roles.update');
        Route::delete('/admin/roles/{role}', [AdminRoleController::class, 'destroy'])
            ->name('api.admin.roles.destroy');
        Route::get('/admin/role-assignments', [AdminRoleController::class, 'assignments'])
            ->name('api.admin.role-assignments.index');
        Route::post('/admin/role-assignments', [AdminRoleController::class, 'assign'])
            ->name('api.admin.role-assignments.store');
        Route::delete('/admin/role-assignments', [AdminRoleController::class, 'revoke'])
            ->name('api.admin.role-assignments.destroy');
        Route::get('/admin/users', [AdminRoleController::class, 'users'])
            ->name('api.admin.users.index');

        // Admin global settings (T90)
        Route::get('/admin/settings', [AdminSettingsController::class, 'index'])
            ->name('api.admin.settings.index');
    });

});
