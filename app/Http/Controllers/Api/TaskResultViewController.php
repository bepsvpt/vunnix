<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskResultViewController extends Controller
{
    public function __invoke(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        // Authorization: user must have access to the task's project
        if (! $user->projects()->where('projects.id', $task->project_id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $result = $task->result ?? [];

        return response()->json([
            'data' => [
                'task_id' => $task->id,
                'status' => $task->status->value,
                'type' => $task->type->value,
                'title' => $result['title'] ?? $result['mr_title'] ?? null,
                'mr_iid' => $task->mr_iid,
                'issue_iid' => $task->issue_iid,
                'project_id' => $task->project_id,
                'pipeline_id' => $task->pipeline_id,
                'pipeline_status' => $task->pipeline_status,
                'started_at' => $task->started_at?->toIso8601String(),
                'conversation_id' => $task->conversation_id,
                'gitlab_url' => $task->project->gitlabWebUrl(),
                'error_reason' => $task->error_reason,
                'result' => $task->result,
            ],
        ]);
    }
}
