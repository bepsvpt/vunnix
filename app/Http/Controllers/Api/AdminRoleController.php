<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\AdminRoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
