<?php

namespace App\Services;

use App\Enums\ReviewStrategy;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostPlaceholderComment;
use App\Jobs\ProcessTaskResult;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * Task Dispatcher — picks tasks from the queue, selects a review strategy
 * based on changed files, and routes to the appropriate execution mode.
 *
 * Execution modes:
 * - Server: task is executed immediately inline (e.g., PRD creation via GitLab API).
 * - Runner: task is dispatched to a GitLab CI pipeline with task-scoped token (D127).
 *
 * @see §3.4 Task Dispatcher & Task Executor
 */
class TaskDispatcher
{
    public function __construct(
        private readonly GitLabClient $gitLabClient,
        private readonly StrategyResolver $strategyResolver,
        private readonly TaskTokenService $taskTokenService,
        private readonly VunnixTomlService $vunnixTomlService,
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

        $this->dispatchPlaceholder($task);

        // Dispatch to Result Processor immediately — server-side tasks
        // use the same lifecycle pipeline as runner tasks (D123/D134),
        // just without the CI pipeline step.
        ProcessTaskResult::dispatch($task->id);

        Log::info('TaskDispatcher: server-side task dispatched to Result Processor', [
            'task_id' => $task->id,
        ]);
    }

    /**
     * Handle runner execution (CI pipeline).
     *
     * Resolves the review strategy, generates a task-scoped token,
     * and triggers a GitLab CI pipeline with VUNNIX_* variables.
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

        // T92: Read optional .vunnix.toml from repo
        $fileConfig = $this->vunnixTomlService->read(
            $task->project->gitlab_project_id,
            $task->commit_sha ?? 'main',
        );

        if (! empty($fileConfig)) {
            $task->result = array_merge($task->result ?? [], [
                'file_config' => $fileConfig,
            ]);
        }

        // Look up the CI trigger token from project config
        $triggerToken = $task->project->projectConfig?->ci_trigger_token;

        if (empty($triggerToken)) {
            Log::error('TaskDispatcher: missing CI trigger token', [
                'task_id' => $task->id,
                'project_id' => $task->project_id,
            ]);

            $task->transitionTo(TaskStatus::Failed, 'missing_trigger_token');

            return;
        }

        $task->transitionTo(TaskStatus::Running);

        $this->dispatchPlaceholder($task);

        // Generate a task-scoped bearer token for executor authentication
        $taskToken = $this->taskTokenService->generate($task->id);

        try {
            $variables = [
                'VUNNIX_TASK_ID' => (string) $task->id,
                'VUNNIX_TASK_TYPE' => $task->type->value,
                'VUNNIX_INTENT' => $task->result['intent'] ?? $task->type->value,
                'VUNNIX_STRATEGY' => $strategy->value,
                'VUNNIX_SKILLS' => implode(',', $strategy->skills()),
                'VUNNIX_TOKEN' => $taskToken,
                'VUNNIX_API_URL' => config('vunnix.api_url'),
            ];

            // Pass the question text for ask_command tasks
            if (! empty($task->result['question'])) {
                $variables['VUNNIX_QUESTION'] = $task->result['question'];
            }

            // T43: Pass Issue IID for issue_discussion tasks
            if ($task->issue_iid !== null) {
                $variables['VUNNIX_ISSUE_IID'] = (string) $task->issue_iid;
            }

            // T72: Pass existing MR IID for designer iteration (push to same branch)
            if ($task->mr_iid !== null && in_array($task->type, [TaskType::FeatureDev, TaskType::UiAdjustment], true)) {
                $variables['VUNNIX_EXISTING_MR_IID'] = (string) $task->mr_iid;
                if (! empty($task->result['branch_name'])) {
                    $variables['VUNNIX_EXISTING_BRANCH'] = $task->result['branch_name'];
                }
            }

            $pipelineResult = $this->gitLabClient->triggerPipeline(
                projectId: $task->project->gitlab_project_id,
                ref: $this->resolvePipelineRef($task),
                triggerToken: $triggerToken,
                variables: $variables,
            );

            $task->pipeline_id = $pipelineResult['id'];
            $task->save();

            Log::info('TaskDispatcher: pipeline triggered', [
                'task_id' => $task->id,
                'pipeline_id' => $pipelineResult['id'],
            ]);
        } catch (\Throwable $e) {
            Log::error('TaskDispatcher: pipeline trigger failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            $task->transitionTo(TaskStatus::Failed, 'pipeline_trigger_failed');
        }
    }

    /**
     * Dispatch a placeholder comment on the MR if the task qualifies.
     *
     * Only MR-backed review tasks (CodeReview, SecurityAudit) get a placeholder.
     */
    private function dispatchPlaceholder(Task $task): void
    {
        if ($task->mr_iid === null) {
            return;
        }

        if (! in_array($task->type, [TaskType::CodeReview, TaskType::SecurityAudit], true)) {
            return;
        }

        PostPlaceholderComment::dispatch($task->id);
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
            TaskType::DeepAnalysis => ReviewStrategy::BackendReview,
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

    /**
     * Resolve the pipeline ref for triggering.
     *
     * The Pipeline Triggers API only accepts branch/tag names, not commit SHAs.
     * For MR-based tasks, fetch the source branch from GitLab.
     */
    private function resolvePipelineRef(Task $task): string
    {
        if ($task->mr_iid !== null) {
            try {
                $mr = $this->gitLabClient->getMergeRequest(
                    $task->project->gitlab_project_id,
                    $task->mr_iid,
                );

                return $mr['source_branch'] ?? 'main';
            } catch (\Throwable $e) {
                Log::warning('TaskDispatcher: failed to resolve MR source branch, falling back to main', [
                    'task_id' => $task->id,
                    'mr_iid' => $task->mr_iid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return 'main';
    }
}
