<?php

namespace App\Services;

use App\Enums\TaskOrigin;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\MergeRequestMerged;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\MergeRequestUpdated;
use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\NoteOnMR;
use App\Events\Webhook\PushToMRBranch;
use App\Events\Webhook\WebhookEvent;
use App\Jobs\ProcessTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class TaskDispatchService
{
    /**
     * Intent → TaskType mapping.
     *
     * Intents not listed here are non-dispatchable (e.g., help_response
     * is handled inline by PostHelpResponse, acceptance_tracking is T86).
     */
    private const INTENT_TO_TYPE = [
        'auto_review' => TaskType::CodeReview,
        'on_demand_review' => TaskType::CodeReview,
        'incremental_review' => TaskType::CodeReview,
        'improve' => TaskType::CodeReview,
        'ask_command' => TaskType::IssueDiscussion,
        'issue_discussion' => TaskType::IssueDiscussion,
        'feature_dev' => TaskType::FeatureDev,
    ];

    /**
     * Dispatch a routing result as a queued task.
     *
     * Returns the created Task, or null if the intent is not dispatchable.
     */
    public function dispatch(RoutingResult $routingResult): ?Task
    {
        $taskType = self::INTENT_TO_TYPE[$routingResult->intent] ?? null;

        if ($taskType === null) {
            Log::debug('TaskDispatchService: non-dispatchable intent, skipping', [
                'intent' => $routingResult->intent,
            ]);

            return null;
        }

        $event = $routingResult->event;
        $priority = TaskPriority::from($routingResult->priority);

        $userId = $this->resolveUserId($event);

        $task = Task::create([
            'type' => $taskType,
            'origin' => TaskOrigin::Webhook,
            'user_id' => $userId,
            'project_id' => $event->projectId,
            'priority' => $priority,
            'status' => TaskStatus::Received,
            'mr_iid' => $this->extractMrIid($event),
            'issue_iid' => $this->extractIssueIid($event),
            'commit_sha' => $this->extractCommitSha($event),
        ]);

        $task->transitionTo(TaskStatus::Queued);

        $job = new ProcessTask($task->id);
        $job->resolveQueue($task);
        dispatch($job);

        Log::info('TaskDispatchService: dispatched task', [
            'task_id' => $task->id,
            'type' => $taskType->value,
            'priority' => $priority->value,
            'queue' => $job->queue,
            'intent' => $routingResult->intent,
        ]);

        return $task;
    }

    private function resolveUserId(WebhookEvent $event): ?int
    {
        $gitlabId = $this->extractAuthorId($event);

        if ($gitlabId === null) {
            return null;
        }

        return User::where('gitlab_id', $gitlabId)->value('id');
    }

    private function extractAuthorId(WebhookEvent $event): ?int
    {
        return match (true) {
            $event instanceof MergeRequestOpened,
            $event instanceof MergeRequestUpdated,
            $event instanceof MergeRequestMerged => $event->authorId,
            $event instanceof NoteOnMR => $event->authorId,
            $event instanceof NoteOnIssue => $event->authorId,
            $event instanceof IssueLabelChanged => $event->authorId,
            $event instanceof PushToMRBranch => $event->userId,
            default => null,
        };
    }

    private function extractMrIid(WebhookEvent $event): ?int
    {
        return match (true) {
            $event instanceof MergeRequestOpened,
            $event instanceof MergeRequestUpdated,
            $event instanceof MergeRequestMerged => $event->mergeRequestIid,
            $event instanceof NoteOnMR => $event->mergeRequestIid,
            default => null,
        };
    }

    private function extractIssueIid(WebhookEvent $event): ?int
    {
        return match (true) {
            $event instanceof NoteOnIssue => $event->issueIid,
            $event instanceof IssueLabelChanged => $event->issueIid,
            default => null,
        };
    }

    private function extractCommitSha(WebhookEvent $event): ?string
    {
        return match (true) {
            $event instanceof MergeRequestOpened,
            $event instanceof MergeRequestUpdated,
            $event instanceof MergeRequestMerged => $event->lastCommitSha,
            $event instanceof PushToMRBranch => $event->afterSha,
            default => null,
        };
    }
}
