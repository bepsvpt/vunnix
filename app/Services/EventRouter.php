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
use App\Jobs\PostHelpResponse;
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
    /**
     * Recognized @ai commands on MR notes.
     */
    private const COMMANDS = [
        'review' => 'on_demand_review',
        'improve' => 'improve',
    ];

    private ?int $botAccountId;

    public function __construct(?int $botAccountId = null)
    {
        // Accept explicit value (for testing) or read from config
        if ($botAccountId !== null) {
            $this->botAccountId = $botAccountId;
        } else {
            $botId = config('services.gitlab.bot_account_id');
            $this->botAccountId = $botId ? (int) $botId : null;
        }
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

        return $this->classifyIntent($event);
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

    // ------------------------------------------------------------------
    //  Intent classification
    // ------------------------------------------------------------------

    /**
     * Classify the intent of a parsed webhook event per the §3.1 routing table.
     */
    private function classifyIntent(WebhookEvent $event): ?RoutingResult
    {
        return match (true) {
            $event instanceof MergeRequestOpened,
            $event instanceof MergeRequestUpdated => new RoutingResult('auto_review', 'normal', $event),

            $event instanceof MergeRequestMerged => new RoutingResult('acceptance_tracking', 'normal', $event),

            $event instanceof NoteOnMR => $this->classifyMRNote($event),

            $event instanceof NoteOnIssue => $this->classifyIssueNote($event),

            $event instanceof IssueLabelChanged => $this->classifyIssueLabelChange($event),

            $event instanceof PushToMRBranch => new RoutingResult('incremental_review', 'normal', $event),

            default => null,
        };
    }

    /**
     * Classify a Note on MR — parse @ai commands per the routing table.
     *
     * - @ai review → on_demand_review (high priority)
     * - @ai improve → improve (normal priority)
     * - @ai ask "..." → ask_command (normal priority)
     * - @ai (unrecognized) → help_response (D155)
     * - No @ai mention → null (ignored)
     */
    private function classifyMRNote(NoteOnMR $event): ?RoutingResult
    {
        $note = $event->note;

        if (! $this->containsAiMention($note)) {
            return null;
        }

        // Check for @ai ask "..." pattern — extract quoted question
        if (preg_match('/@ai\s+ask\s+"([^"]+)"/', $note, $matches)) {
            return new RoutingResult('ask_command', 'normal', $event, [
                'question' => $matches[1],
            ]);
        }

        // Check recognized commands: @ai review, @ai improve
        foreach (self::COMMANDS as $command => $intent) {
            if (preg_match('/@ai\s+'.preg_quote($command, '/').'\b/i', $note)) {
                $priority = $intent === 'on_demand_review' ? 'high' : 'normal';

                return new RoutingResult($intent, $priority, $event);
            }
        }

        // D155: Unrecognized @ai command → dispatch help response
        $unrecognized = $this->extractUnrecognizedCommand($note);
        $this->dispatchHelpResponse($event, $unrecognized);

        return new RoutingResult('help_response', 'normal', $event);
    }

    /**
     * Classify a Note on Issue — any @ai mention triggers issue discussion.
     */
    private function classifyIssueNote(NoteOnIssue $event): ?RoutingResult
    {
        if (! $this->containsAiMention($event->note)) {
            return null;
        }

        return new RoutingResult('issue_discussion', 'normal', $event);
    }

    /**
     * Classify an Issue label change — ai::develop label triggers feature dev.
     */
    private function classifyIssueLabelChange(IssueLabelChanged $event): ?RoutingResult
    {
        if ($event->hasLabel('ai::develop')) {
            return new RoutingResult('feature_dev', 'low', $event);
        }

        return null;
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Check if text contains an @ai mention.
     */
    private function containsAiMention(string $text): bool
    {
        return (bool) preg_match('/@ai\b/i', $text);
    }

    /**
     * Extract the unrecognized command text after @ai for the help response.
     */
    private function extractUnrecognizedCommand(string $note): string
    {
        if (preg_match('/@ai\s+(\S+)/', $note, $matches)) {
            return '@ai '.$matches[1];
        }

        return '@ai';
    }

    /**
     * Dispatch a queued job to post the help response on the MR (D155).
     */
    private function dispatchHelpResponse(NoteOnMR $event, string $unrecognizedCommand): void
    {
        PostHelpResponse::dispatch(
            $event->gitlabProjectId,
            $event->mergeRequestIid,
            $unrecognizedCommand,
        );
    }
}
