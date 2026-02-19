<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromptVersionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $projectIds = $user->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        $versions = Task::whereIn('project_id', $projectIds)
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('prompt_version')
            ->get()
            ->map(function (Task $task): ?array {
                $promptVersion = $task->prompt_version;
                if (! is_array($promptVersion)) {
                    return null;
                }

                return [
                    'skill' => isset($promptVersion['skill']) ? (string) $promptVersion['skill'] : null,
                    'claude_md' => isset($promptVersion['claude_md']) ? (string) $promptVersion['claude_md'] : null,
                    'schema' => isset($promptVersion['schema']) ? (string) $promptVersion['schema'] : null,
                ];
            })
            ->filter(fn (?array $version): bool => $version !== null)
            ->unique(fn (array $version): string => implode('|', [
                $version['skill'] ?? '',
                $version['claude_md'] ?? '',
                $version['schema'] ?? '',
            ]))
            ->sortBy(fn (array $version): string => $version['skill'] ?? '')
            ->values();

        return response()->json([
            'data' => $versions,
        ]);
    }
}
