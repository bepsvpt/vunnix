<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProjectConfigRequest;
use App\Http\Resources\ProjectConfigResource;
use App\Models\Project;
use App\Services\AuditLogService;
use App\Services\ProjectConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class AdminProjectConfigController extends Controller
{
    public function __construct(
        private readonly ProjectConfigService $configService,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProjectAdmin($request, $project);

        $config = $project->projectConfig;
        if ($config === null) {
            $config = $project->projectConfig()->create(['settings' => []]);
        }

        return response()->json([
            'data' => new ProjectConfigResource($config),
        ]);
    }

    public function update(UpdateProjectConfigRequest $request, Project $project): JsonResponse
    {
        $this->authorizeProjectAdmin($request, $project);

        $oldSettings = $project->projectConfig->settings ?? [];
        $overrides = $request->validated()['settings'];

        $this->configService->bulkSet($project, $overrides);

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        foreach ($overrides as $key => $value) {
            $oldValue = Arr::get($oldSettings, $key);

            if ($oldValue !== $value) {
                try {
                    app(AuditLogService::class)->logConfigurationChange(
                        userId: $user->id,
                        key: $key,
                        oldValue: $oldValue,
                        newValue: $value,
                        projectId: $project->id,
                    );
                } catch (Throwable) {
                    // Audit logging should never break config update
                }
            }
        }

        $project->load('projectConfig');
        $config = $project->projectConfig;

        return response()->json([
            'success' => true,
            'data' => new ProjectConfigResource($config),
        ]);
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
