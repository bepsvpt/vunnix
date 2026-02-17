<?php

namespace App\Agents\Tools;

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAccessChecker;
use App\Services\TaskDispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI SDK Tool: Dispatch an action from conversation.
 *
 * Creates a Task in the Task Queue when a user confirms an action
 * during a conversation. Validates project access and chat.dispatch_task
 * permission before creating the task.
 *
 * Supported action types: create_issue, implement_feature, ui_adjustment,
 * create_mr, deep_analysis (D132).
 *
 * @see §3.2 — Action dispatch from conversation
 * @see §4.3 — Action Dispatch UX
 */
class DispatchAction implements Tool
{
    /**
     * Map from action_type strings to TaskType enum values.
     */
    public const ACTION_TYPE_MAP = [
        'create_issue' => TaskType::PrdCreation,
        'implement_feature' => TaskType::FeatureDev,
        'ui_adjustment' => TaskType::UiAdjustment,
        'create_mr' => TaskType::FeatureDev,
        'deep_analysis' => TaskType::DeepAnalysis,
    ];

    public function __construct(
        protected ProjectAccessChecker $accessChecker,
        protected TaskDispatcher $taskDispatcher,
    ) {}

    public function description(): string
    {
        return 'Dispatch an action (create Issue, implement feature, UI adjustment, create MR, or deep analysis) to the task queue. Only call this after presenting a preview and receiving explicit user confirmation.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action_type' => $schema
                ->string()
                ->description('The type of action: create_issue, implement_feature, ui_adjustment, create_mr, or deep_analysis.')
                ->required(),
            'project_id' => $schema
                ->integer()
                ->description('The GitLab project ID to dispatch the action against.')
                ->required(),
            'title' => $schema
                ->string()
                ->description('A short title for the action (e.g., Issue title, feature name).')
                ->required(),
            'description' => $schema
                ->string()
                ->description('Detailed description of what the action should accomplish.')
                ->required(),
            'branch_name' => $schema
                ->string()
                ->description('Target branch name for feature/UI/MR actions (e.g., "ai/payment-feature"). Not used for create_issue or deep_analysis.'),
            'target_branch' => $schema
                ->string()
                ->description('Base branch to target (defaults to "main"). Not used for create_issue or deep_analysis.'),
            'assignee_id' => $schema
                ->integer()
                ->description('GitLab user ID to assign (for create_issue actions).'),
            'labels' => $schema
                ->string()
                ->description('Comma-separated labels to apply (e.g., "feature,ai::created").'),
            'existing_mr_iid' => $schema
                ->integer()
                ->description('Existing MR IID to push corrections to (for designer iteration flow). When set, the executor pushes to the same branch and updates the existing MR instead of creating a new one.'),
        ];
    }

    public function handle(Request $request): string
    {
        $actionType = (string) $request->string('action_type');
        $projectId = $request->integer('project_id');

        // 1. Validate project access
        $rejection = $this->accessChecker->check($projectId);
        if ($rejection !== null) {
            return $rejection;
        }

        // 2. Validate action type
        if (! isset(self::ACTION_TYPE_MAP[$actionType])) {
            return "Invalid action type: \"{$actionType}\". Supported types: "
                .implode(', ', array_keys(self::ACTION_TYPE_MAP)).'.';
        }

        // 3. Resolve the internal project and authenticated user
        $project = Project::where('gitlab_project_id', $projectId)->first();
        if ($project === null) {
            return 'Error: project not found in Vunnix registry.';
        }

        // Resolve user from the authenticated session — never trust the AI's user_id parameter
        $user = Auth::user();
        if (! $user instanceof User) {
            return 'Error: not authenticated. Please log in to dispatch actions.';
        }

        // 4. Check chat.dispatch_task permission
        if (! $user->hasPermission('chat.dispatch_task', $project)) {
            return 'You do not have permission to dispatch actions on this project. '
                ."The 'chat.dispatch_task' permission is required. Contact your project admin to request access.";
        }

        // 5. Create the task
        $taskType = self::ACTION_TYPE_MAP[$actionType];
        $title = (string) $request->string('title');

        // Resolve conversation ID from server-side Context — never trust the AI's value
        // (the AI doesn't know the real UUID and fabricates placeholders like "conv_123456")
        $conversationId = (string) Context::get('vunnix_conversation_id', '');

        $taskData = [
            'type' => $taskType,
            'origin' => TaskOrigin::Conversation,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'status' => TaskStatus::Received,
            'conversation_id' => $conversationId,
            'result' => $this->buildResultMetadata($request, $actionType),
        ];

        // Set branch info for feature/UI/MR actions
        if (in_array($actionType, ['implement_feature', 'ui_adjustment', 'create_mr'], true)) {
            $targetBranch = (string) $request->string('target_branch');
            $taskData['commit_sha'] = $targetBranch !== '' ? $targetBranch : 'main';
        }

        // T72: Set existing MR reference for designer iteration flow
        $existingMrIid = $request->integer('existing_mr_iid');
        if ($existingMrIid > 0) {
            $taskData['mr_iid'] = $existingMrIid;
        }

        $task = Task::create($taskData);

        Log::info('DispatchAction: task created from conversation', [
            'task_id' => $task->id,
            'action_type' => $actionType,
            'project_id' => $project->id,
            'conversation_id' => $conversationId,
        ]);

        // 6. Transition to Queued (required before TaskDispatcher can move to Running)
        $task->transitionTo(TaskStatus::Queued);

        // 7. Dispatch the task
        try {
            $this->taskDispatcher->dispatch($task);
        } catch (Throwable $e) {
            Log::error('DispatchAction: dispatch failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return "Task #{$task->id} was created but dispatch failed: {$e->getMessage()}. "
                .'The task will be retried automatically.';
        }

        return $this->buildSuccessMessage($task, $actionType, $title);
    }

    /**
     * Build the result metadata stored on the Task record.
     *
     * @return array<string, mixed>
     */
    private function buildResultMetadata(Request $request, string $actionType): array
    {
        $meta = [
            'action_type' => $actionType,
            'title' => (string) $request->string('title'),
            'description' => (string) $request->string('description'),
            'dispatched_from' => 'conversation',
        ];

        $branchName = (string) $request->string('branch_name');
        if ($branchName !== '') {
            $meta['branch_name'] = $branchName;
        }

        $targetBranch = (string) $request->string('target_branch');
        if ($targetBranch !== '') {
            $meta['target_branch'] = $targetBranch;
        }

        $existingMrIid = $request->integer('existing_mr_iid');
        if ($existingMrIid > 0) {
            $meta['existing_mr_iid'] = $existingMrIid;
        }

        $assigneeId = $request->integer('assignee_id');
        if ($assigneeId > 0) {
            $meta['assignee_id'] = $assigneeId;
        }

        $labels = (string) $request->string('labels');
        if ($labels !== '') {
            $meta['labels'] = array_map('trim', explode(',', $labels));
        }

        return $meta;
    }

    /**
     * Build a human-readable success message for the chat stream.
     */
    private function buildSuccessMessage(Task $task, string $actionType, string $title): string
    {
        $typeLabel = match ($actionType) {
            'create_issue' => 'Issue creation',
            'implement_feature' => 'Feature implementation',
            'ui_adjustment' => 'UI adjustment',
            'create_mr' => 'Merge request creation',
            'deep_analysis' => 'Deep analysis',
            default => Str::ucfirst(Str::replace('_', ' ', $actionType)),
        };

        $message = "[System: Task dispatched] {$typeLabel} \"{$title}\" has been dispatched as Task #{$task->id}.";

        if ($actionType === 'deep_analysis') {
            $message .= ' The analysis results will be fed back into this conversation when complete.';
        } else {
            $message .= ' You can track its progress in the pinned task bar.';
        }

        return $message;
    }
}
