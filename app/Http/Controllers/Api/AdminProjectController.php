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
        $this->authorizeAdmin($request);

        $projects = Project::orderBy('name')->get();

        return response()->json([
            'data' => AdminProjectResource::collection($projects),
        ]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'data' => new AdminProjectResource($project),
        ]);
    }

    public function enable(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAdmin($request);

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
        $this->authorizeAdmin($request);

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

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $hasAdmin = $user->projects()
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Admin access required.');
        }
    }
}
