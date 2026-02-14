# T9: RBAC System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement role-based access control with Role and Permission models, per-project role assignment, authorization middleware, Gate/Policy integration, and a default roles seeder.

**Architecture:** Permissions are global (e.g., `chat.access`, `review.view`). Roles are project-scoped — a user can have different roles on different projects. The User model gains `hasRole()`, `hasPermission()`, and `projectAccess()` methods. A Gate registers all permissions dynamically, and a `CheckPermission` middleware protects routes. A seeder creates the 7 spec permissions and 3 default roles (Admin, Developer, Viewer).

**Tech Stack:** Laravel 11, Pest, SQLite :memory: for tests

---

### Task 1: Create the Permission model

**Files:**
- Create: `app/Models/Permission.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'group'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')
            ->withTimestamps();
    }
}
```

---

### Task 2: Create the Role model

**Files:**
- Create: `app/Models/Role.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'name', 'description', 'is_default'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->withPivot('project_id', 'assigned_by')
            ->withTimestamps();
    }

    public function hasPermission(string $permissionName): bool
    {
        return $this->permissions()->where('name', $permissionName)->exists();
    }
}
```

---

### Task 3: Add RBAC relationships and methods to User model

**Files:**
- Modify: `app/Models/User.php`

Add these relationships and methods after the existing `syncMemberships()` method:

```php
public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class, 'role_user')
        ->withPivot('project_id', 'assigned_by')
        ->withTimestamps();
}

public function projectRoles(Project $project): Collection
{
    return $this->roles()
        ->where('role_user.project_id', $project->id)
        ->get();
}

public function hasRole(string $roleName, Project $project): bool
{
    return $this->roles()
        ->where('role_user.project_id', $project->id)
        ->where('roles.name', $roleName)
        ->exists();
}

public function hasPermission(string $permissionName, Project $project): bool
{
    return $this->roles()
        ->where('role_user.project_id', $project->id)
        ->whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
        ->exists();
}

public function assignRole(Role $role, Project $project, ?User $assignedBy = null): void
{
    $this->roles()->attach($role->id, [
        'project_id' => $project->id,
        'assigned_by' => $assignedBy?->id,
    ]);
    $this->unsetRelation('roles');
}

public function removeRole(Role $role, Project $project): void
{
    $this->roles()
        ->wherePivot('project_id', $project->id)
        ->detach($role->id);
    $this->unsetRelation('roles');
}
```

Also add the required import for `Collection` (already imported) and `Role` at the top:
```php
use App\Models\Role;
```

---

### Task 4: Add roles relationship to Project model

**Files:**
- Modify: `app/Models/Project.php`

Add after the existing `users()` relationship:

```php
public function roles(): HasMany
{
    return $this->hasMany(Role::class);
}
```

Add import: `use Illuminate\Database\Eloquent\Relations\HasMany;`

---

### Task 5: Create the RoleFactory and PermissionFactory

**Files:**
- Create: `database/factories/RoleFactory.php`
- Create: `database/factories/PermissionFactory.php`

RoleFactory:
```php
<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->word(),
            'description' => fake()->sentence(),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
```

PermissionFactory:
```php
<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Permission> */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'group' => fake()->randomElement(['chat', 'review', 'config', 'admin']),
        ];
    }
}
```

---

### Task 6: Register Gates in AppServiceProvider

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Models\Permission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerGates();
    }

    private function registerGates(): void
    {
        // Don't query DB during migrations or when tables don't exist yet
        if (! $this->app->runningInConsole() || $this->app->runningUnitTests()) {
            try {
                if (Schema::hasTable('permissions')) {
                    $permissions = Permission::all();
                    foreach ($permissions as $permission) {
                        Gate::define($permission->name, function ($user, $project) use ($permission) {
                            return $user->hasPermission($permission->name, $project);
                        });
                    }
                }
            } catch (\Exception) {
                // Silently ignore during initial setup when DB is unavailable
            }
        }
    }
}
```

---

### Task 7: Create CheckPermission middleware

**Files:**
- Create: `app/Http/Middleware/CheckPermission.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $request->user()) {
            abort(401);
        }

        // Resolve project from route parameter or request
        $project = $request->route('project');

        if (! $project) {
            abort(403, 'No project context for permission check.');
        }

        if (! $request->user()->hasPermission($permission, $project)) {
            abort(403, 'You do not have the required permission.');
        }

        return $next($request);
    }
}
```

Register the middleware alias in `bootstrap/app.php` (or wherever middleware is registered — check Laravel 11 convention):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'permission' => \App\Http\Middleware\CheckPermission::class,
    ]);
})
```

---

### Task 8: Create the RbacSeeder with default roles and permissions

**Files:**
- Create: `database/seeders/RbacSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // Create all spec permissions (§3.6)
        $permissions = [
            ['name' => 'chat.access', 'description' => 'Can use the conversational chat UI', 'group' => 'chat'],
            ['name' => 'chat.dispatch_task', 'description' => 'Can trigger AI actions from chat', 'group' => 'chat'],
            ['name' => 'review.view', 'description' => 'Can view AI review results on the dashboard', 'group' => 'review'],
            ['name' => 'review.trigger', 'description' => 'Can trigger on-demand review via @ai in GitLab', 'group' => 'review'],
            ['name' => 'config.manage', 'description' => 'Can edit project-level Vunnix configuration', 'group' => 'config'],
            ['name' => 'admin.roles', 'description' => 'Can create/edit roles and assign permissions', 'group' => 'admin'],
            ['name' => 'admin.global_config', 'description' => 'Can edit global Vunnix settings', 'group' => 'admin'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm['name']], $perm);
        }
    }
}
```

Note: Default roles are project-scoped, so they cannot be seeded globally. Instead, provide a static helper method on the Role model for creating default roles when a project is enabled. We'll add this in the Role model.

---

### Task 9: Add default role creation helper on Role model

**Files:**
- Modify: `app/Models/Role.php`

Add a static method:

```php
public static function createDefaultsForProject(Project $project): void
{
    $allPermissions = Permission::pluck('id', 'name');

    // Admin — all permissions
    $admin = static::firstOrCreate(
        ['project_id' => $project->id, 'name' => 'Admin'],
        ['description' => 'Full access to all Vunnix features', 'is_default' => true],
    );
    $admin->permissions()->sync($allPermissions->values());

    // Developer — chat + review + trigger
    $developer = static::firstOrCreate(
        ['project_id' => $project->id, 'name' => 'Developer'],
        ['description' => 'Can chat, view and trigger reviews', 'is_default' => true],
    );
    $developer->permissions()->sync(
        $allPermissions->only([
            'chat.access',
            'chat.dispatch_task',
            'review.view',
            'review.trigger',
        ])->values()
    );

    // Viewer — read-only access
    $viewer = static::firstOrCreate(
        ['project_id' => $project->id, 'name' => 'Viewer'],
        ['description' => 'Can view review results only', 'is_default' => true],
    );
    $viewer->permissions()->sync(
        $allPermissions->only(['review.view'])->values()
    );
}
```

---

### Task 10: Write unit tests for Role and Permission models

**Files:**
- Create: `tests/Feature/Models/RoleTest.php`
- Create: `tests/Feature/Models/PermissionTest.php`

RoleTest covers:
- Role belongs to project
- Role has many permissions (many-to-many)
- Role has many users (many-to-many with project pivot)
- `hasPermission()` returns true when role has the permission
- `hasPermission()` returns false when role doesn't have the permission
- `createDefaultsForProject()` creates Admin, Developer, Viewer with correct permissions

PermissionTest covers:
- Permission has many roles (many-to-many)
- Permission group field is queryable

---

### Task 11: Write unit tests for User RBAC methods

**Files:**
- Create: `tests/Feature/Models/UserRbacTest.php`

Covers:
- `hasRole()` returns true for assigned role on correct project
- `hasRole()` returns false for assigned role on different project
- `hasPermission()` returns true when user's role on project has that permission
- `hasPermission()` returns false when user's role on project lacks that permission
- `hasPermission()` returns false for a permission on a different project
- `assignRole()` creates the role_user pivot record
- `removeRole()` removes the role_user pivot record
- `projectRoles()` returns only roles for the specified project

---

### Task 12: Write integration test for CheckPermission middleware

**Files:**
- Create: `tests/Feature/Middleware/CheckPermissionTest.php`

Set up a test route with `permission:review.view` middleware. Test:
- Authenticated user with `review.view` on project → 200
- Authenticated user without `review.view` on project → 403
- Unauthenticated user → 401 (redirect)
- Request without project context → 403

---

### Task 13: Write integration test for Gate authorization

**Files:**
- Create: `tests/Feature/AuthorizationGateTest.php`

Test:
- Gate::allows('review.view', [$project]) for user with permission → true
- Gate::denies('review.view', [$project]) for user without permission → true
- Gate::allows('admin.roles', [$project]) for admin role → true
- Gate::denies('admin.roles', [$project]) for viewer role → true

---

### Task 14: Run verification and commit

Run:
```bash
php artisan test
python3 verify/verify_m1.py
```

Both must pass. Then commit:
```bash
git add -A && git commit --no-gpg-sign -m "T9: Add RBAC system with roles, permissions, Gate/Policy, and middleware"
```
