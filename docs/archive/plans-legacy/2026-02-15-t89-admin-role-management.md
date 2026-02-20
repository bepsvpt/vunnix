# T89: Admin Page — Role Management Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build CRUD for roles with permission sets, user role assignment per project, and a cross-project role assignments view — all behind the `admin.roles` permission gate.

**Architecture:** New `AdminRoleController` with 7 endpoints (list roles, create, update, delete, list assignments, assign user, revoke user). The controller uses an `authorizeRoleAdmin()` helper that checks `admin.roles` permission (not `admin.global_config` like the project admin). Frontend adds a "Roles" tab to `AdminPage.vue` with two sub-components: `AdminRoleList` (CRUD roles + permission checkboxes) and `AdminRoleAssignments` (assign/revoke users per project). New Pinia state in the existing `admin.js` store.

**Tech Stack:** Laravel 11 (Controller, FormRequest, API Resource), Pest tests, Vue 3 `<script setup>`, Pinia, Vitest

**Key existing patterns:**
- Admin auth: `AdminProjectController` uses `authorizeAdmin()` checking `admin.global_config` on any project
- API routes: all under `Route::middleware('auth')` inside `Route::prefix('v1')`
- Response format: `{ "data": [...] }` for reads, `{ "success": true/false, ... }` for mutations
- Models: `Role` (project-scoped, `fillable: project_id, name, description, is_default`), `Permission` (global, `fillable: name, description, group`), pivot `role_permission`, pivot `role_user` (with `project_id`, `assigned_by`)
- Vue: `AdminPage.vue` has tab array + conditional component rendering, `admin.js` store uses composition API

---

### Task 1: Create AdminRoleController with authorization

**Files:**
- Create: `app/Http/Controllers/Api/AdminRoleController.php`

**Step 1: Create the controller skeleton with authorization helper**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminRoleController extends Controller
{
    private function authorizeRoleAdmin(Request $request): void
    {
        $user = $request->user();

        $hasRoleAdmin = $user->projects()
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.roles', $project));

        if (! $hasRoleAdmin) {
            abort(403, 'Role management access required.');
        }
    }
}
```

> **Why `admin.roles` not `admin.global_config`?** The spec defines separate permissions for role management vs global settings. This follows the principle of least privilege — a user could manage roles without having global config access.

**Step 2: Commit**

```bash
git add app/Http/Controllers/Api/AdminRoleController.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.1: Create AdminRoleController skeleton with authorization

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Add role CRUD API endpoints — list and create

**Files:**
- Modify: `app/Http/Controllers/Api/AdminRoleController.php`
- Create: `app/Http/Resources/AdminRoleResource.php`
- Create: `app/Http/Requests/Admin/CreateRoleRequest.php`
- Modify: `routes/api.php`

**Step 1: Create AdminRoleResource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'name' => $this->name,
            'description' => $this->description,
            'is_default' => $this->is_default,
            'permissions' => $this->permissions->pluck('name')->values(),
            'user_count' => $this->users_count ?? $this->users()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

**Step 2: Create CreateRoleRequest**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['boolean'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }
}
```

> **Note:** `permissions` uses `present` not `required` — a role with zero permissions is valid (e.g., a "viewer" role that only grants implicit access).

**Step 3: Add index and store methods to AdminRoleController**

```php
use App\Http\Requests\Admin\CreateRoleRequest;
use App\Http\Resources\AdminRoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

// Add to AdminRoleController:

public function index(Request $request): JsonResponse
{
    $this->authorizeRoleAdmin($request);

    $query = Role::with(['project', 'permissions'])->withCount('users');

    if ($request->has('project_id')) {
        $query->where('project_id', $request->integer('project_id'));
    }

    $roles = $query->orderBy('project_id')->orderBy('name')->get();

    return response()->json([
        'data' => AdminRoleResource::collection($roles),
    ]);
}

public function permissions(Request $request): JsonResponse
{
    $this->authorizeRoleAdmin($request);

    $permissions = Permission::orderBy('group')->orderBy('name')->get();

    return response()->json([
        'data' => $permissions->map(fn ($p) => [
            'name' => $p->name,
            'description' => $p->description,
            'group' => $p->group,
        ]),
    ]);
}

public function store(CreateRoleRequest $request): JsonResponse
{
    $this->authorizeRoleAdmin($request);

    $role = Role::create($request->only(['project_id', 'name', 'description', 'is_default']));

    if ($request->has('permissions')) {
        $permissionIds = Permission::whereIn('name', $request->input('permissions'))->pluck('id');
        $role->permissions()->sync($permissionIds);
    }

    $role->load(['project', 'permissions']);
    $role->loadCount('users');

    return response()->json([
        'success' => true,
        'data' => new AdminRoleResource($role),
    ], 201);
}
```

**Step 4: Add routes**

Add to `routes/api.php` inside the `Route::middleware('auth')` group, after the admin projects block:

```php
use App\Http\Controllers\Api\AdminRoleController;

// Admin role management (T89)
Route::get('/admin/roles', [AdminRoleController::class, 'index'])
    ->name('api.admin.roles.index');
Route::get('/admin/permissions', [AdminRoleController::class, 'permissions'])
    ->name('api.admin.permissions.index');
Route::post('/admin/roles', [AdminRoleController::class, 'store'])
    ->name('api.admin.roles.store');
```

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/AdminRoleController.php \
       app/Http/Resources/AdminRoleResource.php \
       app/Http/Requests/Admin/CreateRoleRequest.php \
       routes/api.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.2: Add role list, permissions list, and create role endpoints

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Add role CRUD API endpoints — update and delete

**Files:**
- Modify: `app/Http/Controllers/Api/AdminRoleController.php`
- Create: `app/Http/Requests/Admin/UpdateRoleRequest.php`
- Modify: `routes/api.php`

**Step 1: Create UpdateRoleRequest**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }
}
```

**Step 2: Add update and destroy methods to AdminRoleController**

```php
use App\Http\Requests\Admin\UpdateRoleRequest;

public function update(UpdateRoleRequest $request, Role $role): JsonResponse
{
    $this->authorizeRoleAdmin($request);

    $role->update($request->only(['name', 'description', 'is_default']));

    if ($request->has('permissions')) {
        $permissionIds = Permission::whereIn('name', $request->input('permissions'))->pluck('id');
        $role->permissions()->sync($permissionIds);
    }

    $role->load(['project', 'permissions']);
    $role->loadCount('users');

    return response()->json([
        'success' => true,
        'data' => new AdminRoleResource($role),
    ]);
}

public function destroy(Request $request, Role $role): JsonResponse
{
    $this->authorizeRoleAdmin($request);

    $userCount = $role->users()->count();

    if ($userCount > 0) {
        return response()->json([
            'success' => false,
            'error' => "Cannot delete role '{$role->name}' — it is assigned to {$userCount} user(s). Remove all assignments first.",
        ], 422);
    }

    $role->permissions()->detach();
    $role->delete();

    return response()->json([
        'success' => true,
    ]);
}
```

> **Why prevent deleting roles with users?** This avoids silently removing user permissions. The admin must explicitly reassign users first — a safety guardrail for a system where permission changes affect real access.

**Step 3: Add routes**

Add to `routes/api.php` after the role store route:

```php
Route::put('/admin/roles/{role}', [AdminRoleController::class, 'update'])
    ->name('api.admin.roles.update');
Route::delete('/admin/roles/{role}', [AdminRoleController::class, 'destroy'])
    ->name('api.admin.roles.destroy');
```

**Step 4: Commit**

```bash
git add app/Http/Controllers/Api/AdminRoleController.php \
       app/Http/Requests/Admin/UpdateRoleRequest.php \
       routes/api.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.3: Add role update and delete endpoints

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Add role assignment endpoints — list, assign, revoke

**Files:**
- Modify: `app/Http/Controllers/Api/AdminRoleController.php`
- Create: `app/Http/Requests/Admin/AssignRoleRequest.php`
- Create: `app/Http/Resources/RoleAssignmentResource.php`
- Modify: `routes/api.php`

**Step 1: Create AssignRoleRequest**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ];
    }
}
```

**Step 2: Create RoleAssignmentResource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->id,
            'user_name' => $this->name,
            'user_email' => $this->email,
            'username' => $this->username,
            'role_id' => $this->pivot->role_id,
            'role_name' => $this->role_name,
            'project_id' => $this->pivot->project_id,
            'project_name' => $this->project_name,
            'assigned_by' => $this->pivot->assigned_by,
            'assigned_at' => $this->pivot->created_at?->toIso8601String(),
        ];
    }
}
```

**Step 3: Add assignment methods to AdminRoleController**

```php
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Http\Resources\RoleAssignmentResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

public function assignments(Request $request): JsonResponse
{
    $this->authorizeRoleAdmin($request);

    $query = DB::table('role_user')
        ->join('users', 'role_user.user_id', '=', 'users.id')
        ->join('roles', 'role_user.role_id', '=', 'roles.id')
        ->join('projects', 'role_user.project_id', '=', 'projects.id')
        ->select([
            'role_user.id',
            'users.id as user_id',
            'users.name as user_name',
            'users.email as user_email',
            'users.username',
            'roles.id as role_id',
            'roles.name as role_name',
            'projects.id as project_id',
            'projects.name as project_name',
            'role_user.assigned_by',
            'role_user.created_at as assigned_at',
        ]);

    if ($request->has('project_id')) {
        $query->where('role_user.project_id', $request->integer('project_id'));
    }

    if ($request->has('user_id')) {
        $query->where('role_user.user_id', $request->integer('user_id'));
    }

    $assignments = $query->orderBy('projects.name')
        ->orderBy('users.name')
        ->orderBy('roles.name')
        ->get();

    return response()->json([
        'data' => $assignments,
    ]);
}

public function assign(AssignRoleRequest $request): JsonResponse
{
    $this->authorizeRoleAdmin($request);

    $user = User::findOrFail($request->integer('user_id'));
    $role = Role::findOrFail($request->integer('role_id'));
    $project = Project::findOrFail($request->integer('project_id'));

    // Verify role belongs to the target project
    if ($role->project_id !== $project->id) {
        return response()->json([
            'success' => false,
            'error' => 'Role does not belong to the specified project.',
        ], 422);
    }

    // Check if assignment already exists
    $exists = DB::table('role_user')
        ->where('user_id', $user->id)
        ->where('role_id', $role->id)
        ->where('project_id', $project->id)
        ->exists();

    if ($exists) {
        return response()->json([
            'success' => false,
            'error' => "User '{$user->name}' already has role '{$role->name}' on project '{$project->name}'.",
        ], 422);
    }

    $user->assignRole($role, $project, $request->user());

    return response()->json([
        'success' => true,
    ], 201);
}

public function revoke(Request $request): JsonResponse
{
    $this->authorizeRoleAdmin($request);

    $request->validate([
        'user_id' => ['required', 'integer', 'exists:users,id'],
        'role_id' => ['required', 'integer', 'exists:roles,id'],
        'project_id' => ['required', 'integer', 'exists:projects,id'],
    ]);

    $user = User::findOrFail($request->integer('user_id'));
    $role = Role::findOrFail($request->integer('role_id'));
    $project = Project::findOrFail($request->integer('project_id'));

    $user->removeRole($role, $project);

    return response()->json([
        'success' => true,
    ]);
}

public function users(Request $request): JsonResponse
{
    $this->authorizeRoleAdmin($request);

    $users = User::orderBy('name')
        ->get(['id', 'name', 'email', 'username']);

    return response()->json([
        'data' => $users,
    ]);
}
```

**Step 4: Add routes**

Add to `routes/api.php` after the role destroy route:

```php
Route::get('/admin/role-assignments', [AdminRoleController::class, 'assignments'])
    ->name('api.admin.role-assignments.index');
Route::post('/admin/role-assignments', [AdminRoleController::class, 'assign'])
    ->name('api.admin.role-assignments.store');
Route::delete('/admin/role-assignments', [AdminRoleController::class, 'revoke'])
    ->name('api.admin.role-assignments.destroy');
Route::get('/admin/users', [AdminRoleController::class, 'users'])
    ->name('api.admin.users.index');
```

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/AdminRoleController.php \
       app/Http/Requests/Admin/AssignRoleRequest.php \
       app/Http/Resources/RoleAssignmentResource.php \
       routes/api.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.4: Add role assignment list, assign, revoke, and users list endpoints

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Write backend API tests

**Files:**
- Create: `tests/Feature/AdminRoleApiTest.php`

**Step 1: Write the test file**

Test structure mirrors `AdminProjectApiTest.php`. Include a `createRoleAdmin` helper that grants `admin.roles` permission. Test:

1. **List roles** — returns roles with permissions and user counts, filterable by project_id
2. **List permissions** — returns all permission definitions
3. **Create role** — creates role with permissions, returns 201
4. **Create role — duplicate name** — same project + name → 422 (DB unique constraint)
5. **Update role** — changes name, syncs permissions
6. **Delete role** — deletes role with no users
7. **Delete role with users** — returns 422 with error message
8. **List assignments** — returns all assignments, filterable by project_id and user_id
9. **Assign role** — assigns user to role on project, returns 201
10. **Assign role — duplicate** — already assigned → 422
11. **Assign role — wrong project** — role doesn't belong to project → 422
12. **Revoke role** — removes assignment
13. **List users** — returns user list for assignment dropdown
14. **Authorization** — non-admin users get 403, unauthenticated get 401

```php
<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createRoleAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.roles'],
        ['description' => 'Can create/edit roles and assign permissions', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

function createNonRoleAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $perm = Permission::firstOrCreate(
        ['name' => 'chat.access'],
        ['description' => 'Chat access', 'group' => 'chat']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

// ─── List Roles ─────────────────────────────────────────────────

it('returns role list for role admin', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    // Create an additional role with permissions
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'reviewer']);
    $perm = Permission::firstOrCreate(['name' => 'review.view'], ['description' => 'View reviews', 'group' => 'review']);
    $role->permissions()->attach($perm);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/roles')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'project_id', 'project_name', 'name', 'description', 'is_default', 'permissions', 'user_count']],
        ]);
});

it('filters roles by project_id', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $admin = createRoleAdmin($projectA);

    Role::factory()->create(['project_id' => $projectA->id, 'name' => 'viewer']);
    Role::factory()->create(['project_id' => $projectB->id, 'name' => 'viewer']);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/admin/roles?project_id={$projectA->id}")
        ->assertOk();

    // Should only contain roles from projectA (admin + viewer = 2)
    $data = $response->json('data');
    foreach ($data as $role) {
        expect($role['project_id'])->toBe($projectA->id);
    }
});

it('rejects role list for non-role-admin', function () {
    $project = Project::factory()->create();
    $user = createNonRoleAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/roles')
        ->assertForbidden();
});

it('rejects role list for unauthenticated users', function () {
    $this->getJson('/api/v1/admin/roles')
        ->assertUnauthorized();
});

// ─── List Permissions ───────────────────────────────────────────

it('returns all permissions for role admin', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/permissions')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['name', 'description', 'group']],
        ]);
});

// ─── Create Role ────────────────────────────────────────────────

it('creates a role with permissions', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    Permission::firstOrCreate(['name' => 'chat.access'], ['description' => 'Chat access', 'group' => 'chat']);
    Permission::firstOrCreate(['name' => 'review.view'], ['description' => 'View reviews', 'group' => 'review']);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/roles', [
            'project_id' => $project->id,
            'name' => 'custom-role',
            'description' => 'A custom role',
            'permissions' => ['chat.access', 'review.view'],
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'custom-role')
        ->assertJsonPath('data.project_id', $project->id);

    $role = Role::where('name', 'custom-role')->first();
    expect($role)->not->toBeNull()
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['chat.access', 'review.view']);
});

it('creates a role with empty permissions', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/roles', [
            'project_id' => $project->id,
            'name' => 'viewer',
            'permissions' => [],
        ])
        ->assertCreated()
        ->assertJsonPath('data.permissions', []);
});

it('rejects creating role with invalid project', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/roles', [
            'project_id' => 99999,
            'name' => 'test',
            'permissions' => [],
        ])
        ->assertUnprocessable();
});

// ─── Update Role ────────────────────────────────────────────────

it('updates a role name and permissions', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'old-name']);

    Permission::firstOrCreate(['name' => 'chat.access'], ['description' => 'Chat access', 'group' => 'chat']);

    $this->actingAs($admin)
        ->putJson("/api/v1/admin/roles/{$role->id}", [
            'name' => 'new-name',
            'permissions' => ['chat.access'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'new-name');

    $role->refresh();
    expect($role->name)->toBe('new-name')
        ->and($role->permissions->pluck('name')->all())->toBe(['chat.access']);
});

// ─── Delete Role ────────────────────────────────────────────────

it('deletes a role with no users', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'to-delete']);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/roles/{$role->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Role::find($role->id))->toBeNull();
});

it('rejects deleting a role with assigned users', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'in-use']);
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $user->assignRole($role, $project);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/roles/{$role->id}")
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

// ─── Assignments ────────────────────────────────────────────────

it('lists role assignments across all projects', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/role-assignments')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['user_id', 'user_name', 'role_id', 'role_name', 'project_id', 'project_name']],
        ]);
});

it('filters assignments by project_id', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $admin = createRoleAdmin($projectA);

    // Admin also has a role on projectA — verify filtering works
    $response = $this->actingAs($admin)
        ->getJson("/api/v1/admin/role-assignments?project_id={$projectA->id}")
        ->assertOk();

    $data = $response->json('data');
    foreach ($data as $assignment) {
        expect($assignment['project_id'])->toBe($projectA->id);
    }
});

it('assigns a role to a user', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/role-assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'project_id' => $project->id,
        ])
        ->assertCreated()
        ->assertJsonPath('success', true);

    expect($user->hasRole('developer', $project))->toBeTrue();
});

it('rejects duplicate role assignment', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $user->assignRole($role, $project);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/role-assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'project_id' => $project->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('rejects assigning role from wrong project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $admin = createRoleAdmin($projectA);
    $role = Role::factory()->create(['project_id' => $projectA->id, 'name' => 'developer']);
    $user = User::factory()->create();
    $projectB->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/role-assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'project_id' => $projectB->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('revokes a role assignment', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $user->assignRole($role, $project);

    $this->actingAs($admin)
        ->deleteJson('/api/v1/admin/role-assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'project_id' => $project->id,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($user->hasRole('developer', $project))->toBeFalse();
});

// ─── Users List ─────────────────────────────────────────────────

it('returns user list for assignment dropdowns', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/users')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'email', 'username']],
        ]);
});

// ─── Integration: Assign + Access ───────────────────────────────

it('grants access after role assignment and denies after revocation', function () {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    // Create a role with chat.access permission
    $chatPerm = Permission::firstOrCreate(['name' => 'chat.access'], ['description' => 'Chat access', 'group' => 'chat']);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'chatter']);
    $role->permissions()->attach($chatPerm);

    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // Before assignment — no permission
    expect($user->hasPermission('chat.access', $project))->toBeFalse();

    // Assign role
    $user->assignRole($role, $project, $admin);
    expect($user->hasPermission('chat.access', $project))->toBeTrue();

    // Revoke role
    $user->removeRole($role, $project);
    expect($user->hasPermission('chat.access', $project))->toBeFalse();
});
```

**Step 2: Run tests**

```bash
php artisan test tests/Feature/AdminRoleApiTest.php
```

Expected: All tests pass.

**Step 3: Commit**

```bash
git add tests/Feature/AdminRoleApiTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.5: Add comprehensive API tests for role management

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Build AdminRoleList Vue component

**Files:**
- Create: `resources/js/components/AdminRoleList.vue`
- Modify: `resources/js/stores/admin.js`

**Step 1: Add role management state and actions to admin store**

Add to the existing `admin.js` store (after existing project state):

```js
// ─── Role management state ─────────────────────────────────────
const roles = ref([]);
const permissions = ref([]);
const roleAssignments = ref([]);
const users = ref([]);
const rolesLoading = ref(false);
const rolesError = ref(null);

async function fetchRoles(projectId = null) {
    rolesLoading.value = true;
    rolesError.value = null;
    try {
        const params = projectId ? { project_id: projectId } : {};
        const { data } = await axios.get('/api/v1/admin/roles', { params });
        roles.value = data.data;
    } catch (e) {
        rolesError.value = 'Failed to load roles.';
    } finally {
        rolesLoading.value = false;
    }
}

async function fetchPermissions() {
    try {
        const { data } = await axios.get('/api/v1/admin/permissions');
        permissions.value = data.data;
    } catch (e) {
        // Permissions are supplementary — don't block on error
    }
}

async function createRole(payload) {
    try {
        const { data } = await axios.post('/api/v1/admin/roles', payload);
        if (data.success) {
            roles.value.push(data.data);
        }
        return { success: true };
    } catch (e) {
        return { success: false, error: e.response?.data?.error || 'Failed to create role.' };
    }
}

async function updateRole(roleId, payload) {
    try {
        const { data } = await axios.put(`/api/v1/admin/roles/${roleId}`, payload);
        if (data.success) {
            const idx = roles.value.findIndex((r) => r.id === roleId);
            if (idx !== -1) roles.value[idx] = data.data;
        }
        return { success: true };
    } catch (e) {
        return { success: false, error: e.response?.data?.error || 'Failed to update role.' };
    }
}

async function deleteRole(roleId) {
    try {
        const { data } = await axios.delete(`/api/v1/admin/roles/${roleId}`);
        if (data.success) {
            roles.value = roles.value.filter((r) => r.id !== roleId);
        }
        return { success: true };
    } catch (e) {
        return { success: false, error: e.response?.data?.error || 'Failed to delete role.' };
    }
}

async function fetchAssignments(projectId = null) {
    try {
        const params = projectId ? { project_id: projectId } : {};
        const { data } = await axios.get('/api/v1/admin/role-assignments', { params });
        roleAssignments.value = data.data;
    } catch (e) {
        rolesError.value = 'Failed to load role assignments.';
    }
}

async function assignRole(payload) {
    try {
        await axios.post('/api/v1/admin/role-assignments', payload);
        return { success: true };
    } catch (e) {
        return { success: false, error: e.response?.data?.error || 'Failed to assign role.' };
    }
}

async function revokeRole(payload) {
    try {
        await axios.delete('/api/v1/admin/role-assignments', { data: payload });
        return { success: true };
    } catch (e) {
        return { success: false, error: e.response?.data?.error || 'Failed to revoke role.' };
    }
}

async function fetchUsers() {
    try {
        const { data } = await axios.get('/api/v1/admin/users');
        users.value = data.data;
    } catch (e) {
        // Users list is supplementary
    }
}
```

Return the new state/actions from the store's return statement.

**Step 2: Create AdminRoleList component**

```vue
<script setup>
import { ref, onMounted, computed } from 'vue';
import { useAdminStore } from '@/stores/admin';

const admin = useAdminStore();
const actionError = ref(null);
const showCreateForm = ref(false);

// Create form state
const newRole = ref({ project_id: null, name: '', description: '', permissions: [] });

// Edit state
const editingRole = ref(null);
const editForm = ref({ name: '', description: '', permissions: [] });

const projectOptions = computed(() => admin.projects);

const permissionsByGroup = computed(() => {
    const groups = {};
    for (const p of admin.permissions) {
        const group = p.group || 'other';
        if (!groups[group]) groups[group] = [];
        groups[group].push(p);
    }
    return groups;
});

onMounted(() => {
    admin.fetchRoles();
    admin.fetchPermissions();
    if (admin.projects.length === 0) admin.fetchProjects();
});

function startCreate() {
    newRole.value = { project_id: projectOptions.value[0]?.id || null, name: '', description: '', permissions: [] };
    showCreateForm.value = true;
    actionError.value = null;
}

async function submitCreate() {
    actionError.value = null;
    const result = await admin.createRole(newRole.value);
    if (!result.success) {
        actionError.value = result.error;
        return;
    }
    showCreateForm.value = false;
}

function startEdit(role) {
    editingRole.value = role.id;
    editForm.value = {
        name: role.name,
        description: role.description || '',
        permissions: [...role.permissions],
    };
    actionError.value = null;
}

async function submitEdit(roleId) {
    actionError.value = null;
    const result = await admin.updateRole(roleId, editForm.value);
    if (!result.success) {
        actionError.value = result.error;
        return;
    }
    editingRole.value = null;
}

function cancelEdit() {
    editingRole.value = null;
}

async function handleDelete(role) {
    if (!confirm(`Delete role "${role.name}"? This cannot be undone.`)) return;
    actionError.value = null;
    const result = await admin.deleteRole(role.id);
    if (!result.success) {
        actionError.value = result.error;
    }
}

function togglePermission(list, permName) {
    const idx = list.indexOf(permName);
    if (idx === -1) {
        list.push(permName);
    } else {
        list.splice(idx, 1);
    }
}
</script>

<template>
  <div>
    <!-- Error banner -->
    <div v-if="actionError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="role-action-error">
      {{ actionError }}
    </div>

    <!-- Header with create button -->
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-medium">Roles</h2>
      <button
        v-if="!showCreateForm"
        class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
        data-testid="create-role-btn"
        @click="startCreate"
      >
        Create Role
      </button>
    </div>

    <!-- Create form -->
    <div v-if="showCreateForm" class="mb-6 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-800 dark:bg-blue-900/10" data-testid="create-role-form">
      <h3 class="text-sm font-medium mb-3">New Role</h3>
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Project</label>
          <select v-model="newRole.project_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="create-role-project">
            <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Name</label>
          <input v-model="newRole.name" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="create-role-name" placeholder="e.g. developer, reviewer" />
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Description</label>
          <input v-model="newRole.description" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="create-role-description" placeholder="Optional description" />
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-2">Permissions</label>
          <div v-for="(perms, group) in permissionsByGroup" :key="group" class="mb-2">
            <div class="text-xs font-semibold text-zinc-500 uppercase mb-1">{{ group }}</div>
            <label v-for="p in perms" :key="p.name" class="flex items-center gap-2 text-sm py-0.5">
              <input
                type="checkbox"
                :checked="newRole.permissions.includes(p.name)"
                @change="togglePermission(newRole.permissions, p.name)"
                class="rounded"
              />
              <span>{{ p.name }}</span>
              <span class="text-xs text-zinc-400">{{ p.description }}</span>
            </label>
          </div>
        </div>
        <div class="flex gap-2">
          <button class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" data-testid="create-role-submit" @click="submitCreate">Create</button>
          <button class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300" @click="showCreateForm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="admin.rolesLoading" class="py-8 text-center text-zinc-500">
      Loading roles...
    </div>

    <!-- Empty state -->
    <div v-else-if="admin.roles.length === 0" class="py-8 text-center text-zinc-500">
      No roles defined. Create a role to get started.
    </div>

    <!-- Role list -->
    <div v-else class="space-y-3">
      <div
        v-for="role in admin.roles"
        :key="role.id"
        class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
        :data-testid="`role-row-${role.id}`"
      >
        <!-- View mode -->
        <template v-if="editingRole !== role.id">
          <div class="flex items-center justify-between">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-3">
                <h3 class="text-sm font-medium">{{ role.name }}</h3>
                <span class="text-xs text-zinc-400">{{ role.project_name }}</span>
                <span v-if="role.is_default" class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">
                  Default
                </span>
              </div>
              <p v-if="role.description" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ role.description }}</p>
              <div class="mt-2 flex flex-wrap gap-1.5">
                <span v-for="perm in role.permissions" :key="perm" class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                  {{ perm }}
                </span>
                <span v-if="role.permissions.length === 0" class="text-xs text-zinc-400 italic">No permissions</span>
              </div>
              <p class="mt-1 text-xs text-zinc-400">{{ role.user_count }} user(s)</p>
            </div>
            <div class="ml-4 flex-shrink-0 flex gap-2">
              <button class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800" :data-testid="`edit-role-btn-${role.id}`" @click="startEdit(role)">Edit</button>
              <button class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20" :data-testid="`delete-role-btn-${role.id}`" @click="handleDelete(role)">Delete</button>
            </div>
          </div>
        </template>

        <!-- Edit mode -->
        <template v-else>
          <div class="space-y-3" :data-testid="`edit-role-form-${role.id}`">
            <div>
              <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Name</label>
              <input v-model="editForm.name" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
            </div>
            <div>
              <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Description</label>
              <input v-model="editForm.description" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
            </div>
            <div>
              <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-2">Permissions</label>
              <div v-for="(perms, group) in permissionsByGroup" :key="group" class="mb-2">
                <div class="text-xs font-semibold text-zinc-500 uppercase mb-1">{{ group }}</div>
                <label v-for="p in perms" :key="p.name" class="flex items-center gap-2 text-sm py-0.5">
                  <input
                    type="checkbox"
                    :checked="editForm.permissions.includes(p.name)"
                    @change="togglePermission(editForm.permissions, p.name)"
                    class="rounded"
                  />
                  <span>{{ p.name }}</span>
                </label>
              </div>
            </div>
            <div class="flex gap-2">
              <button class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" :data-testid="`save-role-btn-${role.id}`" @click="submitEdit(role.id)">Save</button>
              <button class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300" @click="cancelEdit">Cancel</button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
```

**Step 3: Commit**

```bash
git add resources/js/components/AdminRoleList.vue resources/js/stores/admin.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.6: Build AdminRoleList component and extend admin Pinia store

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Build AdminRoleAssignments Vue component

**Files:**
- Create: `resources/js/components/AdminRoleAssignments.vue`

**Step 1: Create the assignments component**

This component shows current role-user-project assignments across all projects and provides assign/revoke controls.

```vue
<script setup>
import { ref, onMounted } from 'vue';
import { useAdminStore } from '@/stores/admin';

const admin = useAdminStore();
const actionError = ref(null);
const showAssignForm = ref(false);

const assignForm = ref({ user_id: null, role_id: null, project_id: null });

const filterProjectId = ref(null);

onMounted(() => {
    admin.fetchAssignments();
    admin.fetchUsers();
    if (admin.roles.length === 0) admin.fetchRoles();
    if (admin.projects.length === 0) admin.fetchProjects();
});

function rolesForProject(projectId) {
    return admin.roles.filter((r) => r.project_id === projectId);
}

function startAssign() {
    const firstProject = admin.projects[0];
    assignForm.value = {
        user_id: admin.users[0]?.id || null,
        role_id: rolesForProject(firstProject?.id)?.[0]?.id || null,
        project_id: firstProject?.id || null,
    };
    showAssignForm.value = true;
    actionError.value = null;
}

function onProjectChange() {
    const projectRoles = rolesForProject(assignForm.value.project_id);
    assignForm.value.role_id = projectRoles[0]?.id || null;
}

async function submitAssign() {
    actionError.value = null;
    const result = await admin.assignRole(assignForm.value);
    if (!result.success) {
        actionError.value = result.error;
        return;
    }
    showAssignForm.value = false;
    admin.fetchAssignments(filterProjectId.value);
    admin.fetchRoles(); // Refresh user counts
}

async function handleRevoke(assignment) {
    if (!confirm(`Revoke role "${assignment.role_name}" from ${assignment.user_name} on ${assignment.project_name}?`)) return;
    actionError.value = null;
    const result = await admin.revokeRole({
        user_id: assignment.user_id,
        role_id: assignment.role_id,
        project_id: assignment.project_id,
    });
    if (!result.success) {
        actionError.value = result.error;
        return;
    }
    admin.fetchAssignments(filterProjectId.value);
    admin.fetchRoles(); // Refresh user counts
}

function applyFilter() {
    admin.fetchAssignments(filterProjectId.value);
}
</script>

<template>
  <div>
    <!-- Error banner -->
    <div v-if="actionError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="assignment-action-error">
      {{ actionError }}
    </div>

    <!-- Header with assign button -->
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-medium">Role Assignments</h2>
      <button
        v-if="!showAssignForm"
        class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
        data-testid="assign-role-btn"
        @click="startAssign"
      >
        Assign Role
      </button>
    </div>

    <!-- Filter -->
    <div class="mb-4 flex items-center gap-2">
      <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Filter by project:</label>
      <select v-model="filterProjectId" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="filter-project" @change="applyFilter">
        <option :value="null">All projects</option>
        <option v-for="p in admin.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
    </div>

    <!-- Assign form -->
    <div v-if="showAssignForm" class="mb-6 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-800 dark:bg-blue-900/10" data-testid="assign-role-form">
      <h3 class="text-sm font-medium mb-3">Assign Role to User</h3>
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Project</label>
          <select v-model="assignForm.project_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="assign-project" @change="onProjectChange">
            <option v-for="p in admin.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">User</label>
          <select v-model="assignForm.user_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="assign-user">
            <option v-for="u in admin.users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.username }})</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Role</label>
          <select v-model="assignForm.role_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="assign-role">
            <option v-for="r in rolesForProject(assignForm.project_id)" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
          <p v-if="rolesForProject(assignForm.project_id).length === 0" class="mt-1 text-xs text-zinc-400">No roles defined for this project. Create one in the Roles tab first.</p>
        </div>
        <div class="flex gap-2">
          <button class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" data-testid="assign-submit" @click="submitAssign">Assign</button>
          <button class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300" @click="showAssignForm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-if="admin.roleAssignments.length === 0" class="py-8 text-center text-zinc-500">
      No role assignments found. Assign a role to a user to get started.
    </div>

    <!-- Assignment list -->
    <div v-else class="space-y-2">
      <div
        v-for="(assignment, i) in admin.roleAssignments"
        :key="i"
        class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700"
        :data-testid="`assignment-row-${i}`"
      >
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ assignment.user_name }}</span>
            <span class="text-xs text-zinc-400">@{{ assignment.username }}</span>
          </div>
          <div class="mt-0.5 flex items-center gap-2 text-xs text-zinc-500">
            <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 dark:bg-zinc-800">{{ assignment.role_name }}</span>
            <span>on</span>
            <span class="font-medium">{{ assignment.project_name }}</span>
          </div>
        </div>
        <button
          class="ml-4 rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20"
          :data-testid="`revoke-btn-${i}`"
          @click="handleRevoke(assignment)"
        >
          Revoke
        </button>
      </div>
    </div>
  </div>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/components/AdminRoleAssignments.vue
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.7: Build AdminRoleAssignments component for user-role-project management

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Wire Roles tab into AdminPage

**Files:**
- Modify: `resources/js/pages/AdminPage.vue`

**Step 1: Add Roles and Assignments tabs**

Update the tabs array and add conditional rendering:

```vue
<script setup>
import { ref, onMounted } from 'vue';
import { useAdminStore } from '@/stores/admin';
import AdminProjectList from '@/components/AdminProjectList.vue';
import AdminRoleList from '@/components/AdminRoleList.vue';
import AdminRoleAssignments from '@/components/AdminRoleAssignments.vue';

const admin = useAdminStore();

const activeTab = ref('projects');

const tabs = [
    { key: 'projects', label: 'Projects' },
    { key: 'roles', label: 'Roles' },
    { key: 'assignments', label: 'Assignments' },
];

onMounted(() => {
    admin.fetchProjects();
});
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold mb-6">Admin</h1>

    <!-- Tabs -->
    <div class="flex items-center gap-2 mb-6">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        :data-testid="`admin-tab-${tab.key}`"
        class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors"
        :class="activeTab === tab.key
          ? 'border-zinc-500 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300'
          : 'border-zinc-300 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Tab content -->
    <AdminProjectList v-if="activeTab === 'projects'" />
    <AdminRoleList v-else-if="activeTab === 'roles'" />
    <AdminRoleAssignments v-else-if="activeTab === 'assignments'" />
  </div>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/AdminPage.vue
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.8: Wire Roles and Assignments tabs into AdminPage

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Write Vue component tests

**Files:**
- Create: `resources/js/components/AdminRoleList.test.js`
- Create: `resources/js/components/AdminRoleAssignments.test.js`

**Step 1: Write AdminRoleList tests**

Follow patterns from existing component tests. Use `vi.mock('axios')`, `setActivePinia(createPinia())`, and mount with pinia plugin.

Key tests:
1. Renders role list from store
2. Shows create form when "Create Role" button clicked
3. Shows edit form when "Edit" button clicked
4. Shows empty state when no roles
5. Shows loading state
6. Renders permission badges on role cards
7. Shows user count per role

**Step 2: Write AdminRoleAssignments tests**

Key tests:
1. Renders assignment list from store
2. Shows assign form when "Assign Role" button clicked
3. Shows project filter dropdown
4. Shows empty state when no assignments
5. Shows revoke button for each assignment

**Step 3: Run tests**

```bash
npx vitest run resources/js/components/AdminRoleList.test.js resources/js/components/AdminRoleAssignments.test.js
```

**Step 4: Commit**

```bash
git add resources/js/components/AdminRoleList.test.js \
       resources/js/components/AdminRoleAssignments.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.9: Add Vue component tests for role management UI

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Update AdminPage test for new tabs

**Files:**
- Modify: `resources/js/pages/AdminPage.test.js` (if exists) or existing App.test.js

**Step 1: Ensure AdminPage test mocks the new admin store properties**

Add `roles`, `rolesLoading`, `rolesError`, `permissions`, `roleAssignments`, `users` to any admin store mocks in existing tests. Ensure `axios.get` mock handles `/api/v1/admin/roles`, `/api/v1/admin/permissions`, `/api/v1/admin/role-assignments`, and `/api/v1/admin/users` URLs.

**Step 2: Run the full Vue test suite**

```bash
npx vitest run
```

Fix any cascading test failures from the new `onMounted` API calls.

**Step 3: Commit (if changes needed)**

```bash
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89.10: Fix cascading test failures from admin role management additions

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Run verification and finalize

**Step 1: Run Laravel tests**

```bash
php artisan test --parallel
```

Expected: All pass (including the new `AdminRoleApiTest.php`).

**Step 2: Run Vue tests**

```bash
npx vitest run
```

Expected: All pass.

**Step 3: Run milestone structural checks**

```bash
python3 verify/verify_m5.py
```

If `verify_m5.py` doesn't exist yet (it may be created later in M5), skip this step.

**Step 4: Update progress.md**

Mark T89 `[x]`, bold the next task (T90), update milestone count to 2/18, update summary.

**Step 5: Clear handoff.md**

Reset to empty template.

**Step 6: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T89: Complete admin page — role management

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```
