<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Http\Requests\Admin\CreateRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\AdminRoleResource;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminRoleController extends Controller
{
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

    private function authorizeRoleAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $hasRoleAdmin = $user->projects()
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.roles', $project));

        if (! $hasRoleAdmin) {
            abort(403, 'Role management access required.');
        }
    }
}
