<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Exceptions\InvalidTaskTransitionException;
use App\Http\Requests\StoreTaskResultRequest;
use App\Jobs\ProcessTaskResult;
use App\Models\GlobalSetting;
use App\Models\Task;
use App\Services\CostCalculationService;
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

        // Merge the executor result with pre-existing metadata (question, intent)
        $existingMeta = $task->result ?? [];
        $executorResult = $validated['result'] ?? [];
        $task->result = array_merge($existingMeta, $executorResult);
        $task->tokens_used = $tokens['input'] + $tokens['output'] + $tokens['thinking'];
        $task->input_tokens = $tokens['input'];
        $task->output_tokens = $tokens['output'];
        $task->duration_seconds = $validated['duration_seconds'];
        $task->prompt_version = $validated['prompt_version'];

        // Calculate cost from token counts and configured prices
        $prices = GlobalSetting::get('ai_prices');
        $costService = new CostCalculationService(
            inputPricePerMTok: (float) ($prices['input'] ?? 5.0),
            outputPricePerMTok: (float) ($prices['output'] ?? 25.0),
        );
        $task->cost = $costService->calculate($tokens['input'], $tokens['output']);

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
        // Prefer error_message (descriptive) over error (short code) for user-facing display
        $errorReason = $validated['error_message'] ?? $validated['error'] ?? null;

        try {
            $task->transitionTo(TaskStatus::Failed, $errorReason);
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
