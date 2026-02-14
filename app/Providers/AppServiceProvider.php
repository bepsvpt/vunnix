<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\Project;
use App\Models\User;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
