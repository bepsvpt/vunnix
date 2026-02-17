<?php

namespace App\Services;

use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\MergeRequestUpdated;
use App\Events\Webhook\NoteOnMR;
use App\Events\Webhook\PushToMRBranch;
use App\Events\Webhook\WebhookEvent;
use App\Models\WebhookEventLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prevents duplicate event processing and implements latest-wins superseding (D140).
 *
 * Two deduplication strategies:
 *
 * 1. **Event UUID dedup** — Every GitLab webhook includes an X-Gitlab-Event-UUID header.
 *    We log each UUID in the webhook_events table and reject any UUID we've already seen.
 *    This covers all event types, especially Note and Issue events that lack commit SHA.
 *
 * 2. **Commit SHA dedup** — For MR events that carry a commit SHA, we check if a task
 *    already exists for the same project + MR + commit SHA. This prevents creating
 *    duplicate review tasks for the same code state.
 *
 * Latest-wins superseding (D140):
 *
 * When a new push/update arrives for an MR that already has queued or running tasks,
 * those older tasks are superseded — the new commit represents the current code state.
 * - Queued tasks → status `superseded`
 * - Running tasks → status `superseded` (CI pipeline cancellation delegated to TaskDispatcher T17)
 * - Results from superseded tasks are silently discarded by the Result Processor (T32)
 */
class EventDeduplicator
{
    /**
     * Result of a deduplication check.
     */
    public const ACCEPT = 'accept';

    public const DUPLICATE_UUID = 'duplicate_uuid';

    public const DUPLICATE_COMMIT = 'duplicate_commit';

    /**
     * Process an incoming event for deduplication and superseding.
     *
     * Returns a DeduplicationResult indicating whether the event should be processed.
     *
     * @param  string|null  $eventUuid  The X-Gitlab-Event-UUID header value
     * @param  RoutingResult  $routingResult  The routing decision from EventRouter
     * @return DeduplicationResult Whether to accept or reject the event
     */
    public function process(?string $eventUuid, RoutingResult $routingResult): DeduplicationResult
    {
        $event = $routingResult->event;

        // Step 1: Check event UUID uniqueness
        if ($eventUuid !== null) {
            $isDuplicate = $this->isDuplicateUuid($eventUuid, $event->projectId);

            if ($isDuplicate) {
                Log::info('EventDeduplicator: rejecting duplicate event UUID', [
                    'uuid' => $eventUuid,
                    'project_id' => $event->projectId,
                    'event_type' => $event->type(),
                ]);

                return new DeduplicationResult(self::DUPLICATE_UUID, supersededCount: 0);
            }
        }

        // Step 2: Check commit SHA uniqueness for MR-related events
        $commitSha = $this->extractCommitSha($event);
        $mrIid = $this->extractMrIid($event);

        // For push events, resolve the MR IID from the branch name via GitLab API
        if ($mrIid === null && $event instanceof PushToMRBranch) {
            $mrIid = $this->resolveMrIidFromBranch($event);
        }

        if ($commitSha !== null && $mrIid !== null && $this->isDuplicateCommit($event->projectId, $mrIid, $commitSha)) {
            Log::info('EventDeduplicator: rejecting duplicate commit SHA', [
                'commit_sha' => $commitSha,
                'project_id' => $event->projectId,
                'mr_iid' => $mrIid,
            ]);

            return new DeduplicationResult(self::DUPLICATE_COMMIT, supersededCount: 0);
        }

        // Step 3: Latest-wins superseding (D140) — supersede older tasks for same MR
        $supersededCount = 0;
        if ($mrIid !== null && $this->isSupersedingEvent($event)) {
            $supersededCount = $this->supersedeActiveTasks(
                $event->projectId,
                $event->gitlabProjectId,
                $mrIid,
            );

            if ($supersededCount > 0) {
                Log::info('EventDeduplicator: superseded active tasks (D140)', [
                    'project_id' => $event->projectId,
                    'mr_iid' => $mrIid,
                    'superseded_count' => $supersededCount,
                ]);
            }
        }

        // Step 4: Log the accepted event UUID
        if ($eventUuid !== null) {
            $this->logEvent($eventUuid, $event, $routingResult->intent, $mrIid, $commitSha);
        }

        return new DeduplicationResult(self::ACCEPT, supersededCount: $supersededCount);
    }

    /**
     * Check if an event UUID has already been processed for this project.
     */
    private function isDuplicateUuid(string $uuid, int $projectId): bool
    {
        return WebhookEventLog::where('project_id', $projectId)
            ->where('gitlab_event_uuid', $uuid)
            ->exists();
    }

    /**
     * Check if a task already exists for the same project + MR + commit SHA.
     *
     * Only checks non-terminal states (received, queued, running) — a completed
     * or failed task for the same commit doesn't prevent re-processing.
     */
    private function isDuplicateCommit(int $projectId, int $mrIid, string $commitSha): bool
    {
        return DB::table('tasks')
            ->where('project_id', $projectId)
            ->where('mr_iid', $mrIid)
            ->where('commit_sha', $commitSha)
            ->whereIn('status', ['received', 'queued', 'running'])
            ->exists();
    }

    /**
     * Determine if this event type triggers superseding of older tasks.
     *
     * Only push and MR update events represent new code state that makes
     * older reviews stale. Note events (@ai review) don't supersede.
     */
    private function isSupersedingEvent(WebhookEvent $event): bool
    {
        return $event instanceof PushToMRBranch
            || $event instanceof MergeRequestUpdated
            || $event instanceof MergeRequestOpened;
    }

    /**
     * Mark all queued/running tasks for the same MR as superseded (D140).
     *
     * Also cancels any running GitLab CI pipelines to avoid wasting CI minutes.
     *
     * Returns the number of tasks that were superseded.
     *
     * @param  int  $projectId  Internal Vunnix project ID
     * @param  int  $gitlabProjectId  GitLab project ID for API calls
     * @param  int  $mrIid  Merge request IID
     */
    private function supersedeActiveTasks(int $projectId, int $gitlabProjectId, int $mrIid): int
    {
        // Get pipeline IDs before updating status
        $pipelineIds = DB::table('tasks')
            ->where('project_id', $projectId)
            ->where('mr_iid', $mrIid)
            ->whereIn('status', ['queued', 'running'])
            ->whereNotNull('pipeline_id')
            ->pluck('pipeline_id')
            ->all();

        $count = DB::table('tasks')
            ->where('project_id', $projectId)
            ->where('mr_iid', $mrIid)
            ->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'superseded']);

        // Cancel the pipelines to stop wasted CI execution
        if (count($pipelineIds) > 0) {
            $gitLab = app(GitLabClient::class);

            foreach ($pipelineIds as $pipelineId) {
                try {
                    $gitLab->cancelPipeline($gitlabProjectId, $pipelineId);

                    Log::info('EventDeduplicator: canceled superseded pipeline', [
                        'project_id' => $projectId,
                        'gitlab_project_id' => $gitlabProjectId,
                        'pipeline_id' => $pipelineId,
                    ]);
                } catch (Throwable $e) {
                    // Best-effort: log failure but don't block the superseding
                    Log::warning('EventDeduplicator: failed to cancel pipeline', [
                        'project_id' => $projectId,
                        'gitlab_project_id' => $gitlabProjectId,
                        'pipeline_id' => $pipelineId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * Log an accepted event to the webhook_events table.
     */
    private function logEvent(
        string $uuid,
        WebhookEvent $event,
        ?string $intent,
        ?int $mrIid,
        ?string $commitSha,
    ): void {
        try {
            WebhookEventLog::create([
                'gitlab_event_uuid' => $uuid,
                'project_id' => $event->projectId,
                'event_type' => $event->type(),
                'intent' => $intent,
                'mr_iid' => $mrIid,
                'commit_sha' => $commitSha,
            ]);
        } catch (QueryException $e) {
            // Race condition: another request inserted the same UUID between
            // our exists() check and this insert. The unique constraint protects
            // us — log and treat as duplicate.
            Log::warning('EventDeduplicator: race condition on UUID insert', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract the commit SHA from an event, if available.
     */
    private function extractCommitSha(WebhookEvent $event): ?string
    {
        if ($event instanceof MergeRequestOpened || $event instanceof MergeRequestUpdated) {
            return $event->lastCommitSha;
        }

        if ($event instanceof PushToMRBranch) {
            return $event->afterSha;
        }

        return null;
    }

    /**
     * Extract the MR IID from an event, if available.
     */
    private function extractMrIid(WebhookEvent $event): ?int
    {
        if ($event instanceof MergeRequestOpened
            || $event instanceof MergeRequestUpdated
            || $event instanceof NoteOnMR
        ) {
            return $event->mergeRequestIid;
        }

        return null;
    }

    /**
     * Resolve the MR IID for a push event by querying GitLab API.
     *
     * Finds the open MR targeting the pushed branch, enabling superseding
     * logic to run for rapid successive pushes.
     */
    private function resolveMrIidFromBranch(PushToMRBranch $event): ?int
    {
        try {
            $gitLab = app(GitLabClient::class);
            $mr = $gitLab->findOpenMergeRequestForBranch(
                $event->gitlabProjectId,
                $event->branchName(),
            );

            return $mr ? (int) $mr['iid'] : null;
        } catch (Throwable $e) {
            // Best-effort: log failure but don't block event processing
            Log::warning('EventDeduplicator: failed to resolve MR IID from branch', [
                'project_id' => $event->projectId,
                'branch' => $event->branchName(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
