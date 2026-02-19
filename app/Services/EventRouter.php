<?php

namespace App\Services;

use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\MergeRequestMerged;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\MergeRequestUpdated;
use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\NoteOnMR;
use App\Events\Webhook\PushToMRBranch;
use App\Events\Webhook\WebhookEvent;
use App\Modules\TaskOrchestration\Application\Registries\IntentClassifierRegistry;
use App\Modules\WebhookIntake\Application\Classifiers\IssueLabelClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\IssueNoteClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\MergeRequestLifecycleClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\MergeRequestNoteClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\PushToMergeRequestClassifier;
use Illuminate\Support\Facades\Log;

/**
 * Routes incoming webhook events to AI action intents.
 *
 * Responsibilities:
 * - Parse webhook context arrays into typed Event objects
 * - Filter bot-authored Note events (D154)
 * - Classify intent per the routing table (§3.1)
 * - Handle unrecognized @ai commands with help response (D155)
 *
 * Does NOT dispatch to the task queue — that's T15-T16's responsibility.
 * Returns a RoutingResult (or null if the event should be ignored).
 */
class EventRouter
{
    private ?int $botAccountId;

    private IntentClassifierRegistry $intentClassifierRegistry;

    public function __construct(?int $botAccountId = null, ?IntentClassifierRegistry $intentClassifierRegistry = null)
    {
        // Accept explicit value (for testing) or read from config
        if ($botAccountId !== null) {
            $this->botAccountId = $botAccountId;
        } else {
            $botId = config('services.gitlab.bot_account_id');
            $this->botAccountId = in_array($botId, [null, '', 0], true) ? null : (int) $botId;
        }

        // Fallback for tests that instantiate EventRouter directly (without container).
        $this->intentClassifierRegistry = $intentClassifierRegistry ?? new IntentClassifierRegistry([
            new MergeRequestLifecycleClassifier,
            new MergeRequestNoteClassifier,
            new IssueNoteClassifier,
            new IssueLabelClassifier,
            new PushToMergeRequestClassifier,
        ]);
    }

    /**
     * Route a webhook event context to an AI action intent.
     *
     * @param  array<string, mixed>  $context  The normalized event context from WebhookController::buildEventContext()
     * @return RoutingResult|null Null if the event should be ignored (bot event, unsupported, etc.)
     */
    public function route(array $context): ?RoutingResult
    {
        $event = $this->parseEvent($context);

        if (! $event instanceof \App\Events\Webhook\WebhookEvent) {
            Log::debug('EventRouter: could not parse event', [
                'event_type' => $context['event_type'] ?? 'unknown',
            ]);

            return null;
        }

        // D154: Discard Note events from bot account
        if ($this->isBotNoteEvent($event)) {
            Log::info('EventRouter: discarding bot-authored note event (D154)', [
                'event_type' => $event->type(),
                'project_id' => $event->projectId,
            ]);

            return null;
        }

        return $this->intentClassifierRegistry->classify($event);
    }

    /**
     * Parse a webhook context array into a typed Event object.
     *
     * @param  array<string, mixed>  $context
     */
    public function parseEvent(array $context): ?WebhookEvent
    {
        $eventType = $context['event_type'] ?? null;
        $projectId = $context['project_id'] ?? 0;
        $gitlabProjectId = $context['gitlab_project_id'] ?? 0;
        $payload = $context['payload'] ?? [];

        return match ($eventType) {
            'merge_request' => $this->parseMergeRequestEvent($context, $projectId, $gitlabProjectId, $payload),
            'note' => $this->parseNoteEvent($context, $projectId, $gitlabProjectId, $payload),
            'issue' => $this->parseIssueEvent($context, $projectId, $gitlabProjectId, $payload),
            'push' => $this->parsePushEvent($context, $projectId, $gitlabProjectId, $payload),
            default => null,
        };
    }

    // ------------------------------------------------------------------
    //  Event parsing
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    private function parseMergeRequestEvent(array $context, int $projectId, int $gitlabProjectId, array $payload): ?WebhookEvent
    {
        $action = $context['action'] ?? null;
        $iid = $context['merge_request_iid'] ?? null;

        if ($iid === null) {
            return null;
        }

        $common = [
            $projectId,
            $gitlabProjectId,
            $payload,
            (int) $iid,
            $context['source_branch'] ?? '',
            $context['target_branch'] ?? '',
            (int) ($context['author_id'] ?? 0),
            $context['last_commit_sha'] ?? null,
        ];

        return match ($action) {
            'open' => new MergeRequestOpened(...$common),
            'update' => new MergeRequestUpdated(...$common),
            'merge' => new MergeRequestMerged(...$common),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    private function parseNoteEvent(array $context, int $projectId, int $gitlabProjectId, array $payload): ?WebhookEvent
    {
        $noteableType = $context['noteable_type'] ?? null;
        $note = $context['note'] ?? '';
        $authorId = (int) ($context['author_id'] ?? 0);

        return match ($noteableType) {
            'MergeRequest' => new NoteOnMR(
                $projectId,
                $gitlabProjectId,
                $payload,
                (int) ($context['merge_request_iid'] ?? 0),
                $note,
                $authorId,
            ),
            'Issue' => new NoteOnIssue(
                $projectId,
                $gitlabProjectId,
                $payload,
                (int) ($context['issue_iid'] ?? 0),
                $note,
                $authorId,
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    private function parseIssueEvent(array $context, int $projectId, int $gitlabProjectId, array $payload): ?WebhookEvent
    {
        $iid = $context['issue_iid'] ?? null;

        if ($iid === null) {
            return null;
        }

        return new IssueLabelChanged(
            $projectId,
            $gitlabProjectId,
            $payload,
            (int) $iid,
            $context['action'] ?? '',
            (int) ($context['author_id'] ?? 0),
            $context['labels'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    private function parsePushEvent(array $context, int $projectId, int $gitlabProjectId, array $payload): ?WebhookEvent
    {
        $ref = $context['ref'] ?? null;

        if ($ref === null) {
            return null;
        }

        return new PushToMRBranch(
            $projectId,
            $gitlabProjectId,
            $payload,
            $ref,
            $context['before'] ?? '',
            $context['after'] ?? '',
            (int) ($context['user_id'] ?? 0),
            $context['commits'] ?? [],
            (int) ($context['total_commits_count'] ?? 0),
        );
    }

    // ------------------------------------------------------------------
    //  Bot filtering (D154)
    // ------------------------------------------------------------------

    /**
     * Check if a webhook event is a Note authored by the Vunnix bot account.
     *
     * Per D154: Only Note events are filtered. Bot-authored MR events
     * (open/update) are intentionally kept — AI-created MRs receive
     * auto-review per D100.
     */
    private function isBotNoteEvent(WebhookEvent $event): bool
    {
        if ($this->botAccountId === null) {
            return false;
        }

        if ($event instanceof NoteOnMR) {
            return $event->authorId === $this->botAccountId;
        }

        if ($event instanceof NoteOnIssue) {
            return $event->authorId === $this->botAccountId;
        }

        return false;
    }
}
