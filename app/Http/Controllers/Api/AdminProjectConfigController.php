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

class AdminProjectConfigController extends Controller
{
    public function __construct(
        private readonly ProjectConfigService $configService,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAdmin($request);

        $config = $project->projectConfig;
        if (! $config) {
            $config = $project->projectConfig()->create(['settings' => []]);
        }

        return response()->json([
            'data' => new ProjectConfigResource($config),
        ]);
    }

    public function update(UpdateProjectConfigRequest $request, Project $project): JsonResponse
    {
        $this->authorizeAdmin($request);

        $oldSettings = $project->projectConfig?->settings ?? [];
        $overrides = $request->validated()['settings'];

        $this->configService->bulkSet($project, $overrides);

        foreach ($overrides as $key => $value) {
            $oldValue = Arr::get($oldSettings, $key);
            if ($oldValue !== $value) {
                try {
                    app(AuditLogService::class)->logConfigurationChange(
                        userId: $request->user()->id,
                        key: $key,
                        oldValue: $oldValue,
                        newValue: $value,
                        projectId: $project->id,
                    );
                } catch (\Throwable) {
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

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        $hasAdmin = $user->projects()
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Admin access required.');
        }
    }
}
