<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AdminApiKeyController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AdminProjectController;
use App\Http\Controllers\Api\AdminRoleController;
use App\Http\Controllers\Api\AdminProjectConfigController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\CostAlertController;
use App\Http\Controllers\Api\DeadLetterController;
use App\Http\Controllers\Api\InfrastructureAlertController;
use App\Http\Controllers\Api\ExternalActivityController;
use App\Http\Controllers\Api\ExternalMetricsController;
use App\Http\Controllers\Api\ExternalProjectController;
use App\Http\Controllers\Api\ExternalTaskController;
use App\Http\Controllers\Api\OverrelianceAlertController;
use App\Http\Controllers\Api\PrdTemplateController;
use App\Http\Controllers\Api\PromptVersionController;
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

        // Prompt versions for filter dropdown (T102)
        Route::get('/prompt-versions', PromptVersionController::class)
            ->name('api.prompt-versions');

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
        Route::put('/admin/settings', [AdminSettingsController::class, 'update'])
            ->name('api.admin.settings.update');
        Route::post('/admin/settings/test-webhook', [AdminSettingsController::class, 'testWebhook'])
            ->name('api.admin.settings.test-webhook');

        // Admin per-project config (T91)
        Route::get('/admin/projects/{project}/config', [AdminProjectConfigController::class, 'show'])
            ->name('api.admin.projects.config.show');
        Route::put('/admin/projects/{project}/config', [AdminProjectConfigController::class, 'update'])
            ->name('api.admin.projects.config.update');

        // Cost alert management (T94) — admin-only via RBAC
        Route::get('/dashboard/cost-alerts', [CostAlertController::class, 'index'])
            ->name('api.dashboard.cost-alerts.index');
        Route::patch('/dashboard/cost-alerts/{costAlert}/acknowledge', [CostAlertController::class, 'acknowledge'])
            ->name('api.dashboard.cost-alerts.acknowledge');

        // Over-reliance alert management (T95) — admin-only via RBAC
        Route::get('/dashboard/overreliance-alerts', [OverrelianceAlertController::class, 'index'])
            ->name('api.dashboard.overreliance-alerts.index');
        Route::patch('/dashboard/overreliance-alerts/{overrelianceAlert}/acknowledge', [OverrelianceAlertController::class, 'acknowledge'])
            ->name('api.dashboard.overreliance-alerts.acknowledge');

        // Infrastructure alert management (T104) — admin-only via RBAC
        Route::get('/dashboard/infrastructure-alerts', [InfrastructureAlertController::class, 'index'])
            ->name('api.dashboard.infrastructure-alerts.index');
        Route::patch('/dashboard/infrastructure-alerts/{alertEvent}/acknowledge', [InfrastructureAlertController::class, 'acknowledge'])
            ->name('api.dashboard.infrastructure-alerts.acknowledge');

        // Dead letter queue management (T97) — admin-only via RBAC
        Route::get('/admin/dead-letter', [DeadLetterController::class, 'index'])
            ->name('api.admin.dead-letter.index');
        Route::get('/admin/dead-letter/{deadLetterEntry}', [DeadLetterController::class, 'show'])
            ->name('api.admin.dead-letter.show');
        Route::post('/admin/dead-letter/{deadLetterEntry}/retry', [DeadLetterController::class, 'retry'])
            ->name('api.admin.dead-letter.retry');
        Route::post('/admin/dead-letter/{deadLetterEntry}/dismiss', [DeadLetterController::class, 'dismiss'])
            ->name('api.admin.dead-letter.dismiss');

        // PRD template management (T93)
        Route::get('/admin/projects/{project}/prd-template', [PrdTemplateController::class, 'showProject'])
            ->name('api.admin.projects.prd-template.show');
        Route::put('/admin/projects/{project}/prd-template', [PrdTemplateController::class, 'updateProject'])
            ->name('api.admin.projects.prd-template.update');
        Route::get('/admin/prd-template', [PrdTemplateController::class, 'showGlobal'])
            ->name('api.admin.prd-template.show');
        Route::put('/admin/prd-template', [PrdTemplateController::class, 'updateGlobal'])
            ->name('api.admin.prd-template.update');

        // Audit logs (T103) — admin-only via RBAC
        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->name('api.audit-logs.index');
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])
            ->name('api.audit-logs.show');

        // API key management (T100) — users manage their own keys
        Route::get('/api-keys', [ApiKeyController::class, 'index'])
            ->name('api.api-keys.index');
        Route::post('/api-keys', [ApiKeyController::class, 'store'])
            ->name('api.api-keys.store');
        Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])
            ->name('api.api-keys.destroy');

        // Admin API key management (T100) — list all, revoke any
        Route::get('/admin/api-keys', [AdminApiKeyController::class, 'index'])
            ->name('api.admin.api-keys.index');
        Route::delete('/admin/api-keys/{apiKey}', [AdminApiKeyController::class, 'destroy'])
            ->name('api.admin.api-keys.destroy');
    });

    // External API (T100) — accepts session auth OR API key
    // Rate-limited per API key (60 req/min). Session auth not rate-limited here.
    Route::prefix('ext')
        ->middleware(['auth.api_key_or_session', 'throttle:api_key'])
        ->group(function () {
            Route::get('/tasks', [ExternalTaskController::class, 'index'])
                ->name('api.ext.tasks.index');
            Route::get('/tasks/{task}', [ExternalTaskController::class, 'show'])
                ->name('api.ext.tasks.show');
            Route::post('/tasks/review', [ExternalTaskController::class, 'triggerReview'])
                ->name('api.ext.tasks.review');
            Route::get('/metrics/summary', [ExternalMetricsController::class, 'summary'])
                ->name('api.ext.metrics.summary');
            Route::get('/activity', [ExternalActivityController::class, 'index'])
                ->name('api.ext.activity.index');
            Route::get('/projects', [ExternalProjectController::class, 'index'])
                ->name('api.ext.projects.index');
        });

});
