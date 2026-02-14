<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Exceptions\InvalidTaskTransitionException;
use App\Http\Requests\StoreTaskResultRequest;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Accept execution results from the GitLab Runner (Task Executor).
 *
 * POST /api/v1/tasks/{task}/result
 *
 * The AuthenticateTaskToken middleware validates the task-scoped bearer
 * token before this controller is reached. The task is guaranteed to
 * exist and the token matches the task ID.
 *
 * @see §20.4 Runner Result API
 */
class TaskResultController extends Controller
{
    public function __invoke(StoreTaskResultRequest $request, Task $task): JsonResponse
    {
        // Only accept results for tasks that are currently running
        if ($task->status !== TaskStatus::Running) {
            Log::warning('Task result received for non-running task', [
                'task_id' => $task->id,
                'current_status' => $task->status->value,
            ]);

            return response()->json([
                'error' => "Task is not in running state (current: {$task->status->value}).",
            ], 409);
        }

        $validated = $request->validated();
        $targetStatus = $validated['status'] === 'completed'
            ? TaskStatus::Completed
            : TaskStatus::Failed;

        try {
            // Store the structured result and token breakdown
            $tokens = $validated['tokens'];
            $task->result = $validated['result'] ?? null;
            $task->tokens_used = $tokens['input'] + $tokens['output'] + $tokens['thinking'];
            $task->prompt_version = $validated['prompt_version'];

            // transitionTo() handles error_reason for Failed status and calls save()
            $task->transitionTo($targetStatus, $validated['error'] ?? null);
        } catch (InvalidTaskTransitionException $e) {
            Log::error('Task result transition failed', [
                'task_id' => $task->id,
                'from' => $e->from->value,
                'to' => $e->to->value,
            ]);

            return response()->json([
                'error' => 'Task state transition failed.',
            ], 409);
        }

        Log::info('Task result accepted', [
            'task_id' => $task->id,
            'status' => $targetStatus->value,
            'duration_seconds' => $validated['duration_seconds'],
            'tokens' => $validated['tokens'],
        ]);

        return response()->json([
            'status' => 'accepted',
            'task_id' => $task->id,
            'task_status' => $targetStatus->value,
        ]);
    }
}
