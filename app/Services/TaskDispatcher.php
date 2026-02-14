<?php

namespace App\Services;

use App\Enums\ReviewStrategy;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * Task Dispatcher — picks tasks from the queue, selects a review strategy
 * based on changed files, and routes to the appropriate execution mode.
 *
 * Execution modes:
 * - Server: task is executed immediately inline (e.g., PRD creation via GitLab API).
 * - Runner: task is dispatched to a GitLab CI pipeline (T18 adds pipeline trigger).
 *
 * @see §3.4 Task Dispatcher & Task Executor
 */
class TaskDispatcher
{
    public function __construct(
        private readonly GitLabClient $gitLabClient,
        private readonly StrategyResolver $strategyResolver,
    ) {}

    /**
     * Dispatch a task for execution.
     *
     * Determines the execution mode and review strategy, then routes accordingly.
     */
    public function dispatch(Task $task): void
    {
        $executionMode = $task->type->executionMode();

        Log::info('TaskDispatcher: dispatching task', [
            'task_id' => $task->id,
            'type' => $task->type->value,
            'execution_mode' => $executionMode,
        ]);

        if ($executionMode === 'server') {
            $this->dispatchServerSide($task);
        } else {
            $this->dispatchToRunner($task);
        }
    }

    /**
     * Handle server-side execution (immediate, no CI pipeline).
     *
     * Server-side tasks bypass the CI pipeline entirely. The structured data
     * is passed directly to the Result Processor (T32), which calls the GitLab API.
     */
    private function dispatchServerSide(Task $task): void
    {
        Log::info('TaskDispatcher: server-side execution', [
            'task_id' => $task->id,
            'type' => $task->type->value,
        ]);

        $task->transitionTo(TaskStatus::Running);

        // T32 (Result Processor) will handle the actual GitLab API calls.
        // For now, mark the transition so the pipeline is ready for T32 to plug into.
        Log::info('TaskDispatcher: server-side task ready for Result Processor', [
            'task_id' => $task->id,
        ]);
    }

    /**
     * Handle runner execution (CI pipeline).
     *
     * Resolves the review strategy from changed files, stores it on the task,
     * and transitions to Running. T18 will add the actual pipeline trigger call.
     */
    private function dispatchToRunner(Task $task): void
    {
        $strategy = $this->resolveStrategy($task);

        Log::info('TaskDispatcher: runner execution', [
            'task_id' => $task->id,
            'type' => $task->type->value,
            'strategy' => $strategy->value,
            'skills' => $strategy->skills(),
        ]);

        // Store the resolved strategy in the task result metadata
        $task->result = array_merge($task->result ?? [], [
            'strategy' => $strategy->value,
        ]);

        $task->transitionTo(TaskStatus::Running);

        // T18 will add: pipeline trigger via GitLabClient::triggerPipeline()
        // passing task ID, type, strategy, and skill names as pipeline variables.
        Log::info('TaskDispatcher: runner task ready for pipeline trigger (T18)', [
            'task_id' => $task->id,
            'strategy' => $strategy->value,
        ]);
    }

    /**
     * Resolve the review strategy for a task.
     *
     * For code review tasks with an MR, analyzes changed files.
     * For other task types, returns a fixed strategy matching the type.
     */
    private function resolveStrategy(Task $task): ReviewStrategy
    {
        // SecurityAudit tasks always use security-audit strategy
        if ($task->type === TaskType::SecurityAudit) {
            return ReviewStrategy::SecurityAudit;
        }

        // Code review tasks: analyze changed files from the MR diff
        if ($task->type === TaskType::CodeReview && $task->mr_iid !== null) {
            return $this->resolveFromMergeRequest($task);
        }

        // Default strategies for non-review task types
        return match ($task->type) {
            TaskType::FeatureDev => ReviewStrategy::BackendReview,
            TaskType::UiAdjustment => ReviewStrategy::FrontendReview,
            TaskType::IssueDiscussion => ReviewStrategy::BackendReview,
            default => ReviewStrategy::BackendReview,
        };
    }

    /**
     * Fetch MR changed files from GitLab and resolve strategy.
     */
    private function resolveFromMergeRequest(Task $task): ReviewStrategy
    {
        try {
            $changes = $this->gitLabClient->getMergeRequestChanges(
                $task->project->gitlab_project_id,
                $task->mr_iid,
            );

            $filePaths = array_map(
                fn (array $change): string => $change['new_path'] ?? $change['old_path'] ?? '',
                $changes['changes'] ?? [],
            );

            $filePaths = array_filter($filePaths);

            return $this->strategyResolver->resolve($filePaths);
        } catch (\Throwable $e) {
            Log::warning('TaskDispatcher: failed to fetch MR changes, defaulting to mixed-review', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            // Safe fallback: mixed-review covers both frontend and backend
            return ReviewStrategy::MixedReview;
        }
    }
}
