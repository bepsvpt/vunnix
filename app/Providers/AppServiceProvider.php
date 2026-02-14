<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Observers\TaskObserver;
use App\Services\TaskTokenService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TaskTokenService::class, function ($app) {
            $appKey = $app['config']['app.key'];

            // Strip the base64: prefix if present
            if (str_starts_with($appKey, 'base64:')) {
                $appKey = base64_decode(substr($appKey, 7));
            }

            return new TaskTokenService(
                appKey: $appKey,
                budgetMinutes: (int) $app['config']['vunnix.task_budget_minutes'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Task::observe(TaskObserver::class);

        $this->registerPermissionGates();
    }

    /**
     * Register a Gate for each permission in the database.
     *
     * Each gate receives a User and a Project, then checks whether the user
     * holds that permission on the given project (resolved through roles).
     */
    private function registerPermissionGates(): void
    {
        try {
            if (! Schema::hasTable('permissions')) {
                return;
            }

            $permissions = Permission::all();
        } catch (\Throwable) {
            // Database not available (e.g., during tests, migrations, or before DB setup)
            return;
        }

        foreach ($permissions as $permission) {
            Gate::define($permission->name, function (User $user, Project $project) use ($permission) {
                return $user->hasPermission($permission->name, $project);
            });
        }
    }
}
