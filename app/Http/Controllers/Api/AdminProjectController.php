<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminProjectResource;
use App\Models\Project;
use App\Services\ProjectEnablementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProjectController extends Controller
{
    public function __construct(
        private readonly ProjectEnablementService $enablement,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeGlobalAdmin($request);

        $projects = Project::orderBy('name')->get();

        return response()->json([
            'data' => AdminProjectResource::collection($projects),
        ]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProjectAdmin($request, $project);

        return response()->json([
            'data' => new AdminProjectResource($project),
        ]);
    }

    public function enable(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProjectAdmin($request, $project);

        $result = $this->enablement->enable($project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error',
                'warnings' => $result['warnings'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'warnings' => $result['warnings'],
            'data' => new AdminProjectResource($project->fresh()),
        ]);
    }

    public function disable(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProjectAdmin($request, $project);

        $result = $this->enablement->disable($project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new AdminProjectResource($project->fresh()),
        ]);
    }

    private function authorizeGlobalAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        if (! $user->isGlobalAdmin()) {
            abort(403, 'Admin access required.');
        }
    }

    private function authorizeProjectAdmin(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        if (! $user->hasPermission('admin.global_config', $project)) {
            abort(403, 'Admin access required.');
        }
    }
}
