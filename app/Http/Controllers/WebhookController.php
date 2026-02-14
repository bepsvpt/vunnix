<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\EventRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Supported GitLab webhook event types and their internal names.
     *
     * GitLab sends the event type via the X-Gitlab-Event header.
     * @see https://docs.gitlab.com/ee/user/project/integrations/webhook_events.html
     */
    private const EVENT_MAP = [
        'Merge Request Hook' => 'merge_request',
        'Note Hook' => 'note',
        'Issue Hook' => 'issue',
        'Push Hook' => 'push',
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
    public function __invoke(Request $request, EventRouter $eventRouter): JsonResponse
    {
        /** @var Project $project */
        $project = $request->input('webhook_project');
        $gitlabEvent = $request->header('X-Gitlab-Event');

        if (empty($gitlabEvent)) {
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

        Log::info('Webhook event received', [
            'project_id' => $project->id,
            'event_type' => $eventType,
            'object_kind' => $payload['object_kind'] ?? null,
        ]);

        $result = $eventRouter->route($eventContext);

        return response()->json([
            'status' => 'accepted',
            'event_type' => $eventType,
            'project_id' => $project->id,
            'intent' => $result?->intent,
        ]);
    }

    /**
     * Build a normalized event context array from the GitLab webhook payload.
     *
     * Extracts common fields used by the Event Router (T13) for intent
     * classification, deduplication (T14), and task dispatch (T17).
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
}
