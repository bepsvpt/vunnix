<?php

namespace App\Http\Controllers;

use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\MergeRequestMerged;
use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\NoteOnMR;
use App\Events\Webhook\PushToMRBranch;
use App\Events\Webhook\WebhookEvent;
use App\Jobs\ProcessAcceptanceTracking;
use App\Jobs\ProcessCodeChangeCorrelation;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EventDeduplicator;
use App\Services\EventRouter;
use App\Services\GitLabClient;
use App\Services\RoutingResult;
use App\Services\TaskDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookController extends Controller
{
    /**
     * Supported GitLab webhook event types and their internal names.
     *
     * GitLab sends the event type via the X-Gitlab-Event header.
     *
     * @see https://docs.gitlab.com/ee/user/project/integrations/webhook_events.html
     */
    private const EVENT_MAP = [
        'Merge Request Hook' => 'merge_request',
        'Note Hook' => 'note',
        'Issue Hook' => 'issue',
        'Push Hook' => 'push',
    ];

    /**
     * Intents that require the `review.trigger` permission (§3.1).
     *
     * Auto-review (MR open/update), incremental review (push), and
     * acceptance tracking fire for all enabled projects — no permission
     * check needed. @ai commands and ai::develop label triggers require
     * the user to have `review.trigger` on the project.
     */
    private const PERMISSION_REQUIRED_INTENTS = [
        'on_demand_review',
        'improve',
        'ask_command',
        'issue_discussion',
        'feature_dev',
    ];

    /**
     * Handle an incoming GitLab webhook event.
     *
     * The VerifyWebhookToken middleware has already validated the token and
     * resolved the project — available via $request->input('webhook_project').
     *
     * This controller parses the event type and payload, then passes
     * the event context to the EventRouter for intent classification.
     */
    public function __invoke(
        Request $request,
        EventRouter $eventRouter,
        EventDeduplicator $deduplicator,
        TaskDispatchService $taskDispatchService,
    ): JsonResponse {
        /** @var Project $project */
        $project = $request->input('webhook_project');
        $gitlabEvent = $request->header('X-Gitlab-Event');
        $eventUuid = $request->header('X-Gitlab-Event-UUID');

        if ($gitlabEvent === null || $gitlabEvent === '') {
            Log::warning('Webhook request missing X-Gitlab-Event header', [
                'project_id' => $project->id,
            ]);

            return response()->json([
                'status' => 'ignored',
                'reason' => 'Missing X-Gitlab-Event header.',
            ], 400);
        }

        $eventType = self::EVENT_MAP[$gitlabEvent] ?? null;

        if ($eventType === null) {
            Log::info('Webhook received unsupported event type', [
                'project_id' => $project->id,
                'gitlab_event' => $gitlabEvent,
            ]);

            return response()->json([
                'status' => 'ignored',
                'reason' => "Unsupported event type: {$gitlabEvent}",
            ]);
        }

        $payload = $request->all();

        // Remove the injected webhook_project from payload to keep it clean
        unset($payload['webhook_project']);

        $eventContext = $this->buildEventContext($eventType, $payload, $project);

        try {
            app(AuditLogService::class)->logWebhookReceived(
                projectId: $project->id,
                eventType: $eventType,
                relevantIds: array_filter([
                    'merge_request_iid' => $eventContext['merge_request_iid'] ?? null,
                    'issue_iid' => $eventContext['issue_iid'] ?? null,
                    'action' => $eventContext['action'] ?? null,
                    'event_uuid' => $eventUuid,
                ], static fn (mixed $v): bool => $v !== null),
            );
        } catch (Throwable) {
            // Audit logging should never break webhook processing
        }

        Log::info('Webhook event received', [
            'project_id' => $project->id,
            'event_type' => $eventType,
            'event_uuid' => $eventUuid,
            'object_kind' => $payload['object_kind'] ?? null,
        ]);

        $routingResult = $eventRouter->route($eventContext);

        // If the event was not routable (filtered, unsupported), return early
        if (! $routingResult instanceof \App\Services\RoutingResult) {
            return response()->json([
                'status' => 'accepted',
                'event_type' => $eventType,
                'project_id' => $project->id,
                'intent' => null,
            ]);
        }

        // T41: Permission check for @ai commands and label triggers (§3.1)
        if (! $this->hasRequiredPermission($routingResult, $project)) {
            return response()->json([
                'status' => 'accepted',
                'event_type' => $eventType,
                'project_id' => $project->id,
                'intent' => $routingResult->intent,
                'permission_denied' => true,
            ]);
        }

        // T14: Deduplication + latest-wins superseding (D140)
        $dedupResult = $deduplicator->process($eventUuid, $routingResult);

        if ($dedupResult->rejected()) {
            return response()->json([
                'status' => 'duplicate',
                'reason' => $dedupResult->outcome,
                'event_type' => $eventType,
                'project_id' => $project->id,
            ]);
        }

        // T86: Dispatch acceptance tracking for MR merge events
        if ($routingResult->intent === 'acceptance_tracking') {
            $this->dispatchAcceptanceTracking($routingResult, $project);

            return response()->json([
                'status' => 'accepted',
                'event_type' => $eventType,
                'project_id' => $project->id,
                'intent' => $routingResult->intent,
                'superseded_count' => $dedupResult->supersededCount,
            ]);
        }

        // T39: Dispatch task for accepted, routable events
        $task = $taskDispatchService->dispatch($routingResult);

        // T86: Additionally dispatch code change correlation for push events
        if ($routingResult->intent === 'incremental_review' && $routingResult->event instanceof PushToMRBranch) {
            $this->dispatchCodeChangeCorrelation($routingResult, $project);
        }

        return response()->json([
            'status' => 'accepted',
            'event_type' => $eventType,
            'project_id' => $project->id,
            'intent' => $routingResult->intent,
            'superseded_count' => $dedupResult->supersededCount,
            'task_id' => $task?->id,
        ]);
    }

    /**
     * Build a normalized event context array from the GitLab webhook payload.
     *
     * Extracts common fields used by the Event Router (T13) for intent
     * classification, deduplication (T14), and task dispatch (T17).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildEventContext(string $eventType, array $payload, Project $project): array
    {
        $context = [
            'event_type' => $eventType,
            'project_id' => $project->id,
            'gitlab_project_id' => $project->gitlab_project_id,
            'payload' => $payload,
        ];

        return match ($eventType) {
            'merge_request' => $this->enrichMergeRequestContext($context, $payload),
            'note' => $this->enrichNoteContext($context, $payload),
            'issue' => $this->enrichIssueContext($context, $payload),
            'push' => $this->enrichPushContext($context, $payload),
            default => $context,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichMergeRequestContext(array $context, array $payload): array
    {
        $attrs = $payload['object_attributes'] ?? [];

        $context['merge_request_iid'] = $attrs['iid'] ?? null;
        $context['action'] = $attrs['action'] ?? null;
        $context['source_branch'] = $attrs['source_branch'] ?? null;
        $context['target_branch'] = $attrs['target_branch'] ?? null;
        $context['author_id'] = $attrs['author_id'] ?? null;
        $context['last_commit_sha'] = $attrs['last_commit']['id'] ?? null;

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichNoteContext(array $context, array $payload): array
    {
        $attrs = $payload['object_attributes'] ?? [];

        $context['note'] = $attrs['note'] ?? '';
        $context['noteable_type'] = $attrs['noteable_type'] ?? null;
        $context['author_id'] = $attrs['author_id'] ?? null;

        // MR note context
        if (isset($payload['merge_request'])) {
            $context['merge_request_iid'] = $payload['merge_request']['iid'] ?? null;
        }

        // Issue note context
        if (isset($payload['issue'])) {
            $context['issue_iid'] = $payload['issue']['iid'] ?? null;
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichIssueContext(array $context, array $payload): array
    {
        $attrs = $payload['object_attributes'] ?? [];

        $context['issue_iid'] = $attrs['iid'] ?? null;
        $context['action'] = $attrs['action'] ?? null;
        $context['author_id'] = $attrs['author_id'] ?? null;
        $context['labels'] = array_map(
            fn (array $label) => $label['title'] ?? '',
            $payload['labels'] ?? [],
        );

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichPushContext(array $context, array $payload): array
    {
        $context['ref'] = $payload['ref'] ?? null;
        $context['before'] = $payload['before'] ?? null;
        $context['after'] = $payload['after'] ?? null;
        $context['user_id'] = $payload['user_id'] ?? null;
        $context['commits'] = $payload['commits'] ?? [];
        $context['total_commits_count'] = $payload['total_commits_count'] ?? 0;

        return $context;
    }

    // ------------------------------------------------------------------
    //  Permission check (T41, §3.1)
    // ------------------------------------------------------------------

    /**
     * Check if the routed intent requires the `review.trigger` permission,
     * and if so, verify the webhook author has it on the project.
     *
     * Per §3.1: "@ai commands on MRs require review.trigger. @ai on Issues
     * requires review.trigger. The ai::develop label trigger requires
     * review.trigger. If the GitLab user has no Vunnix account or lacks
     * the permission, the event is logged and silently dropped."
     *
     * Returns true if no permission is needed or the user has the permission.
     * Returns false (and logs) if the permission check fails.
     */
    private function hasRequiredPermission(RoutingResult $routingResult, Project $project): bool
    {
        if (! in_array($routingResult->intent, self::PERMISSION_REQUIRED_INTENTS, true)) {
            return true;
        }

        $gitlabId = $this->extractAuthorId($routingResult->event);

        if ($gitlabId === null) {
            Log::info('Webhook permission check: no author ID on event, dropping', [
                'intent' => $routingResult->intent,
                'project_id' => $project->id,
            ]);

            return false;
        }

        $user = User::where('gitlab_id', $gitlabId)->first();

        if ($user === null) {
            Log::info('Webhook permission check: GitLab user has no Vunnix account, dropping', [
                'intent' => $routingResult->intent,
                'gitlab_id' => $gitlabId,
                'project_id' => $project->id,
            ]);

            return false;
        }

        if (! $user->hasPermission('review.trigger', $project)) {
            Log::info('Webhook permission check: user lacks review.trigger, dropping', [
                'intent' => $routingResult->intent,
                'user_id' => $user->id,
                'gitlab_id' => $gitlabId,
                'project_id' => $project->id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Extract the GitLab author/user ID from a webhook event.
     */
    private function extractAuthorId(WebhookEvent $event): ?int
    {
        if ($event instanceof NoteOnMR) {
            return $event->authorId;
        }

        if ($event instanceof NoteOnIssue) {
            return $event->authorId;
        }

        if ($event instanceof IssueLabelChanged) {
            return $event->authorId;
        }

        return null;
    }

    // ------------------------------------------------------------------
    //  Acceptance tracking (T86, D149)
    // ------------------------------------------------------------------

    /**
     * Dispatch acceptance tracking job for MR merge events.
     */
    private function dispatchAcceptanceTracking(RoutingResult $routingResult, Project $project): void
    {
        $event = $routingResult->event;

        if (! $event instanceof MergeRequestMerged) {
            return;
        }

        ProcessAcceptanceTracking::dispatch(
            $project->id,
            $project->gitlab_project_id,
            $event->mergeRequestIid,
        );

        Log::info('WebhookController: dispatched acceptance tracking', [
            'project_id' => $project->id,
            'mr_iid' => $event->mergeRequestIid,
        ]);
    }

    /**
     * Dispatch code change correlation for push events (§16.2).
     *
     * Runs alongside the normal incremental review dispatch — correlates
     * push diffs with existing AI findings for acceptance signals.
     */
    private function dispatchCodeChangeCorrelation(RoutingResult $routingResult, Project $project): void
    {
        $event = $routingResult->event;

        if (! $event instanceof PushToMRBranch) {
            return;
        }

        // Resolve MR IID for the pushed branch
        try {
            $gitLab = app(GitLabClient::class);
            $mr = $gitLab->findOpenMergeRequestForBranch(
                $project->gitlab_project_id,
                $event->branchName(),
            );

            if ($mr === null) {
                return;
            }

            ProcessCodeChangeCorrelation::dispatch(
                $project->id,
                $project->gitlab_project_id,
                (int) $mr['iid'],
                $event->beforeSha,
                $event->afterSha,
            );
        } catch (Throwable $e) {
            Log::warning('WebhookController: failed to dispatch code change correlation', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
