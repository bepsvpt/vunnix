<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePrdTemplateRequest;
use App\Models\GlobalSetting;
use App\Models\Project;
use App\Services\ProjectConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class PrdTemplateController extends Controller
{
    public function __construct(
        private readonly ProjectConfigService $configService,
    ) {}

    /**
     * GET /admin/projects/{project}/prd-template
     * Returns the effective PRD template for a project with source indicator.
     */
    public function showProject(Request $request, Project $project): JsonResponse
    {
        $this->authorizeConfigManage($request, $project);

        return response()->json([
            'data' => $this->resolveTemplate($project),
        ]);
    }

    /**
     * PUT /admin/projects/{project}/prd-template
     * Set or remove the project-level PRD template override.
     */
    public function updateProject(UpdatePrdTemplateRequest $request, Project $project): JsonResponse
    {
        $this->authorizeConfigManage($request, $project);

        $this->configService->set($project, 'prd_template', $request->validated('template'));

        // Reload project config to reflect the change in resolveTemplate()
        $project->load('projectConfig');

        return response()->json([
            'success' => true,
            'data' => $this->resolveTemplate($project),
        ]);
    }

    /**
     * GET /admin/prd-template
     * Returns the global PRD template (override or default).
     */
    public function showGlobal(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $globalOverride = GlobalSetting::get('prd_template');

        return response()->json([
            'data' => [
                'template' => $globalOverride ?? GlobalSetting::defaultPrdTemplate(),
                'source' => $globalOverride !== null ? 'global' : 'default',
            ],
        ]);
    }

    /**
     * PUT /admin/prd-template
     * Set or remove the global PRD template override.
     */
    public function updateGlobal(UpdatePrdTemplateRequest $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $template = $request->validated('template');

        if ($template === null) {
            GlobalSetting::where('key', 'prd_template')->delete();
        } else {
            GlobalSetting::set('prd_template', $template, 'string', 'PRD output template');
        }

        $globalOverride = GlobalSetting::get('prd_template');

        return response()->json([
            'success' => true,
            'data' => [
                'template' => $globalOverride ?? GlobalSetting::defaultPrdTemplate(),
                'source' => $globalOverride !== null ? 'global' : 'default',
            ],
        ]);
    }

    /**
     * Resolve the effective template for a project: project → global → default.
     * Checks each layer individually to determine the source.
     *
     * @return array<string, mixed>
     */
    private function resolveTemplate(Project $project): array
    {
        // Check project-level override directly (don't use cascading get())
        $projectSettings = $project->projectConfig->settings ?? [];
        $projectTemplate = Arr::get($projectSettings, 'prd_template');
        if ($projectTemplate !== null) {
            return ['template' => $projectTemplate, 'source' => 'project'];
        }

        // Check global override
        $globalOverride = GlobalSetting::get('prd_template');
        if ($globalOverride !== null) {
            return ['template' => $globalOverride, 'source' => 'global'];
        }

        return ['template' => GlobalSetting::defaultPrdTemplate(), 'source' => 'default'];
    }

    /**
     * Authorize project-level template access via config.manage permission.
     */
    private function authorizeConfigManage(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        if (! $user->hasPermission('config.manage', $project)) {
            abort(403, 'You need the config.manage permission to manage PRD templates.');
        }
    }

    /**
     * Authorize global template access via admin.global_config permission.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        if (! $user->isGlobalAdmin()) {
            abort(403, 'Admin access required.');
        }
    }
}
