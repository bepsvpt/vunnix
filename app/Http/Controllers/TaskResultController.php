<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Exceptions\InvalidTaskTransitionException;
use App\Http\Requests\StoreTaskResultRequest;
use App\Jobs\ProcessTaskResult;
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
 * For completed results, the task stays in Running state while the
 * Result Processor validates the schema asynchronously. The RP then
 * transitions the task to Completed or Failed based on validation.
 *
 * For failed results (executor error), the task transitions to Failed
 * immediately — no Result Processor validation needed.
 *
 * @see §20.4 Runner Result API
 * @see §3.5 Result Processor
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
        $tokens = $validated['tokens'];
        $isCompleted = $validated['status'] === 'completed';

        // Store the raw result and token breakdown on the task
        $task->result = $validated['result'] ?? null;
        $task->tokens_used = $tokens['input'] + $tokens['output'] + $tokens['thinking'];
        $task->prompt_version = $validated['prompt_version'];

        if ($isCompleted) {
            // Completed results stay in Running while the Result Processor
            // validates the schema asynchronously. The RP transitions the
            // task to Completed or Failed based on validation outcome.
            $task->save();

            ProcessTaskResult::dispatch($task->id);

            Log::info('Task result accepted, dispatched to Result Processor', [
                'task_id' => $task->id,
                'duration_seconds' => $validated['duration_seconds'],
                'tokens' => $validated['tokens'],
            ]);

            return response()->json([
                'status' => 'accepted',
                'task_id' => $task->id,
                'task_status' => 'processing',
            ]);
        }

        // Failed results transition immediately — no RP validation needed
        try {
            $task->transitionTo(TaskStatus::Failed, $validated['error'] ?? null);
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

        Log::info('Task result accepted (failed)', [
            'task_id' => $task->id,
            'duration_seconds' => $validated['duration_seconds'],
            'tokens' => $validated['tokens'],
            'error' => $validated['error'] ?? null,
        ]);

        return response()->json([
            'status' => 'accepted',
            'task_id' => $task->id,
            'task_status' => TaskStatus::Failed->value,
        ]);
    }
}
