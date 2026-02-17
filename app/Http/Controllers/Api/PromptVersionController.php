<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromptVersionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $projectIds = $user->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        $driver = DB::connection()->getDriverName();

        // Extract distinct prompt_version->skill values from completed tasks
        if ($driver === 'pgsql') {
            $query = Task::whereIn('project_id', $projectIds)
                ->where('status', TaskStatus::Completed)
                ->whereNotNull('prompt_version')
                ->selectRaw("DISTINCT prompt_version->>'skill' as skill, prompt_version->>'claude_md' as claude_md, prompt_version->>'schema' as schema")
                ->orderByRaw("prompt_version->>'skill'");
        } else {
            // SQLite fallback for tests
            $query = Task::whereIn('project_id', $projectIds)
                ->where('status', TaskStatus::Completed)
                ->whereNotNull('prompt_version')
                ->selectRaw("DISTINCT json_extract(prompt_version, '$.skill') as skill, json_extract(prompt_version, '$.claude_md') as claude_md, json_extract(prompt_version, '$.schema') as schema")
                ->orderByRaw("json_extract(prompt_version, '$.skill')");
        }

        /** @var \Illuminate\Support\Collection<int, object{skill: string|null, claude_md: string|null, schema: string|null}> $versions */
        $versions = $query->get();

        return response()->json([
            'data' => $versions->map(fn ($v): array => [
                'skill' => $v->skill,
                'claude_md' => $v->claude_md,
                'schema' => $v->schema,
            ])->values(),
        ]);
    }
}
