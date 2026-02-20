<?php

use Illuminate\Support\Facades\Route;

Route::get('/admin/projects', [\App\Http\Controllers\Api\AdminProjectController::class, 'index'])
    ->name('api.admin.projects.index');
Route::get('/admin/projects/{project}', [\App\Http\Controllers\Api\AdminProjectController::class, 'show'])
    ->name('api.admin.projects.show');
Route::post('/admin/projects/{project}/enable', [\App\Http\Controllers\Api\AdminProjectController::class, 'enable'])
    ->name('api.admin.projects.enable');
Route::post('/admin/projects/{project}/disable', [\App\Http\Controllers\Api\AdminProjectController::class, 'disable'])
    ->name('api.admin.projects.disable');

Route::get('/admin/roles', [\App\Http\Controllers\Api\AdminRoleController::class, 'index'])
    ->name('api.admin.roles.index');
Route::get('/admin/permissions', [\App\Http\Controllers\Api\AdminRoleController::class, 'permissions'])
    ->name('api.admin.permissions.index');
Route::post('/admin/roles', [\App\Http\Controllers\Api\AdminRoleController::class, 'store'])
    ->name('api.admin.roles.store');
Route::put('/admin/roles/{role}', [\App\Http\Controllers\Api\AdminRoleController::class, 'update'])
    ->name('api.admin.roles.update');
Route::delete('/admin/roles/{role}', [\App\Http\Controllers\Api\AdminRoleController::class, 'destroy'])
    ->name('api.admin.roles.destroy');
Route::get('/admin/role-assignments', [\App\Http\Controllers\Api\AdminRoleController::class, 'assignments'])
    ->name('api.admin.role-assignments.index');
Route::post('/admin/role-assignments', [\App\Http\Controllers\Api\AdminRoleController::class, 'assign'])
    ->name('api.admin.role-assignments.store');
Route::delete('/admin/role-assignments', [\App\Http\Controllers\Api\AdminRoleController::class, 'revoke'])
    ->name('api.admin.role-assignments.destroy');
Route::get('/admin/users', [\App\Http\Controllers\Api\AdminRoleController::class, 'users'])
    ->name('api.admin.users.index');

Route::get('/admin/settings', [\App\Http\Controllers\Api\AdminSettingsController::class, 'index'])
    ->name('api.admin.settings.index');
Route::put('/admin/settings', [\App\Http\Controllers\Api\AdminSettingsController::class, 'update'])
    ->name('api.admin.settings.update');
Route::post('/admin/settings/test-webhook', [\App\Http\Controllers\Api\AdminSettingsController::class, 'testWebhook'])
    ->name('api.admin.settings.test-webhook');

Route::get('/admin/projects/{project}/config', [\App\Http\Controllers\Api\AdminProjectConfigController::class, 'show'])
    ->name('api.admin.projects.config.show');
Route::put('/admin/projects/{project}/config', [\App\Http\Controllers\Api\AdminProjectConfigController::class, 'update'])
    ->name('api.admin.projects.config.update');

Route::get('/admin/dead-letter', [\App\Http\Controllers\Api\DeadLetterController::class, 'index'])
    ->name('api.admin.dead-letter.index');
Route::get('/admin/dead-letter/{deadLetterEntry}', [\App\Http\Controllers\Api\DeadLetterController::class, 'show'])
    ->name('api.admin.dead-letter.show');
Route::post('/admin/dead-letter/{deadLetterEntry}/retry', [\App\Http\Controllers\Api\DeadLetterController::class, 'retry'])
    ->name('api.admin.dead-letter.retry');
Route::post('/admin/dead-letter/{deadLetterEntry}/dismiss', [\App\Http\Controllers\Api\DeadLetterController::class, 'dismiss'])
    ->name('api.admin.dead-letter.dismiss');

Route::get('/admin/projects/{project}/prd-template', [\App\Http\Controllers\Api\PrdTemplateController::class, 'showProject'])
    ->name('api.admin.projects.prd-template.show');
Route::put('/admin/projects/{project}/prd-template', [\App\Http\Controllers\Api\PrdTemplateController::class, 'updateProject'])
    ->name('api.admin.projects.prd-template.update');
Route::get('/admin/prd-template', [\App\Http\Controllers\Api\PrdTemplateController::class, 'showGlobal'])
    ->name('api.admin.prd-template.show');
Route::put('/admin/prd-template', [\App\Http\Controllers\Api\PrdTemplateController::class, 'updateGlobal'])
    ->name('api.admin.prd-template.update');

Route::get('/audit-logs', [\App\Http\Controllers\Api\AuditLogController::class, 'index'])
    ->name('api.audit-logs.index');
Route::get('/audit-logs/{auditLog}', [\App\Http\Controllers\Api\AuditLogController::class, 'show'])
    ->name('api.audit-logs.show');

Route::get('/api-keys', [\App\Http\Controllers\Api\ApiKeyController::class, 'index'])
    ->name('api.api-keys.index');
Route::post('/api-keys', [\App\Http\Controllers\Api\ApiKeyController::class, 'store'])
    ->name('api.api-keys.store');
Route::delete('/api-keys/{apiKey}', [\App\Http\Controllers\Api\ApiKeyController::class, 'destroy'])
    ->name('api.api-keys.destroy');
Route::get('/admin/api-keys', [\App\Http\Controllers\Api\AdminApiKeyController::class, 'index'])
    ->name('api.admin.api-keys.index');
Route::delete('/admin/api-keys/{apiKey}', [\App\Http\Controllers\Api\AdminApiKeyController::class, 'destroy'])
    ->name('api.admin.api-keys.destroy');
