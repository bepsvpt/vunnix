<?php

use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\MergeRequestUpdated;
use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\NoteOnMR;
use App\Events\Webhook\PushToMRBranch;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookEventLog;
use App\Services\DeduplicationResult;
use App\Services\EventDeduplicator;
use App\Services\RoutingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Create shared fixtures: a user and project for FK constraints
beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->enabled()->create();
});

// ------------------------------------------------------------------
//  Helpers
// ------------------------------------------------------------------

function dedupMrOpenedEvent(int $projectId, int $mrIid = 42, string $commitSha = 'abc123'): MergeRequestOpened
{
    return new MergeRequestOpened($projectId, 100, [], $mrIid, 'feature/x', 'main', 7, $commitSha);
}

function dedupMrUpdatedEvent(int $projectId, int $mrIid = 42, string $commitSha = 'def456'): MergeRequestUpdated
{
    return new MergeRequestUpdated($projectId, 100, [], $mrIid, 'feature/x', 'main', 7, $commitSha);
}

function dedupPushEvent(int $projectId, string $afterSha = 'ghi789'): PushToMRBranch
{
    return new PushToMRBranch($projectId, 100, [], 'refs/heads/feature/x', '000', $afterSha, 7, [], 1);
}

function dedupNoteOnMrEvent(int $projectId, int $mrIid = 42): NoteOnMR
{
    return new NoteOnMR($projectId, 100, [], $mrIid, '@ai review', 5);
}

function dedupNoteOnIssueEvent(int $projectId): NoteOnIssue
{
    return new NoteOnIssue($projectId, 100, [], 17, '@ai explain this', 5);
}

function dedupIssueLabelEvent(int $projectId): IssueLabelChanged
{
    return new IssueLabelChanged($projectId, 100, [], 99, 'update', 12, ['ai::develop']);
}

function dedupRouting(string $intent, string $priority, $event): RoutingResult
{
    return new RoutingResult($intent, $priority, $event);
}

/**
 * Insert a task row directly (T15 Task model doesn't exist yet).
 */
function insertTask(int $projectId, int $userId, array $overrides = []): int
{
    return DB::table('tasks')->insertGetId(array_merge([
        'project_id' => $projectId,
        'type' => 'code_review',
        'origin' => 'webhook',
        'user_id' => $userId,
        'priority' => 'normal',
        'status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ------------------------------------------------------------------
//  Event UUID deduplication
// ------------------------------------------------------------------

it('accepts an event with a new UUID', function (): void {
    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id);
    $result = $dedup->process('00000000-0000-0000-0000-000000000001', dedupRouting('auto_review', 'normal', $event));

    expect($result->accepted())->toBeTrue()
        ->and($result->outcome)->toBe(EventDeduplicator::ACCEPT);
});

it('rejects an event with a duplicate UUID', function (): void {
    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id);

    // First call — accept and log the UUID
    $dedup->process('00000000-0000-0000-0000-000000000001', dedupRouting('auto_review', 'normal', $event));

    // Second call — same UUID → reject
    $result = $dedup->process('00000000-0000-0000-0000-000000000001', dedupRouting('auto_review', 'normal', $event));

    expect($result->rejected())->toBeTrue()
        ->and($result->outcome)->toBe(EventDeduplicator::DUPLICATE_UUID);
});

it('accepts events with different UUIDs for the same project', function (): void {
    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id);

    $result1 = $dedup->process('00000000-0000-0000-0000-000000000001', dedupRouting('auto_review', 'normal', $event));
    $result2 = $dedup->process('00000000-0000-0000-0000-000000000002', dedupRouting('auto_review', 'normal', $event));

    expect($result1->accepted())->toBeTrue()
        ->and($result2->accepted())->toBeTrue();
});

it('accepts same UUID for different projects', function (): void {
    $project2 = Project::factory()->enabled()->create();

    $dedup = new EventDeduplicator;
    $event1 = dedupMrOpenedEvent($this->project->id);
    $event2 = dedupMrOpenedEvent($project2->id);

    $result1 = $dedup->process('00000000-0000-0000-0000-000000000001', dedupRouting('auto_review', 'normal', $event1));
    $result2 = $dedup->process('00000000-0000-0000-0000-000000000001', dedupRouting('auto_review', 'normal', $event2));

    expect($result1->accepted())->toBeTrue()
        ->and($result2->accepted())->toBeTrue();
});

it('accepts events when UUID is null (no header sent)', function (): void {
    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id);

    $result = $dedup->process(null, dedupRouting('auto_review', 'normal', $event));

    expect($result->accepted())->toBeTrue();
});

it('logs accepted events to the webhook_events table', function (): void {
    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id, mrIid: 42, commitSha: 'abc123');
    $dedup->process('00000000-0000-0000-0000-000000000001', dedupRouting('auto_review', 'normal', $event));

    $log = WebhookEventLog::first();
    expect($log)->not->toBeNull()
        ->and($log->gitlab_event_uuid)->toBe('00000000-0000-0000-0000-000000000001')
        ->and($log->project_id)->toBe($this->project->id)
        ->and($log->event_type)->toBe('merge_request_opened')
        ->and($log->intent)->toBe('auto_review')
        ->and($log->mr_iid)->toBe(42)
        ->and($log->commit_sha)->toBe('abc123');
});

it('does not log events when UUID is null', function (): void {
    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id);

    $dedup->process(null, dedupRouting('auto_review', 'normal', $event));

    expect(WebhookEventLog::count())->toBe(0);
});

// ------------------------------------------------------------------
//  Commit SHA deduplication
// ------------------------------------------------------------------

it('rejects duplicate commit SHA for same MR in active state', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'abc123',
        'status' => 'queued',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id, mrIid: 42, commitSha: 'abc123');

    $result = $dedup->process('00000000-0000-0000-0000-000000000002', dedupRouting('auto_review', 'normal', $event));

    expect($result->rejected())->toBeTrue()
        ->and($result->outcome)->toBe(EventDeduplicator::DUPLICATE_COMMIT);
});

it('rejects duplicate commit SHA in running state', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'abc123',
        'status' => 'running',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id, mrIid: 42, commitSha: 'abc123');

    $result = $dedup->process('00000000-0000-0000-0000-00000000002b', dedupRouting('auto_review', 'normal', $event));

    expect($result->rejected())->toBeTrue()
        ->and($result->outcome)->toBe(EventDeduplicator::DUPLICATE_COMMIT);
});

it('allows same commit SHA when previous task is completed', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'abc123',
        'status' => 'completed',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id, mrIid: 42, commitSha: 'abc123');

    $result = $dedup->process('00000000-0000-0000-0000-000000000003', dedupRouting('auto_review', 'normal', $event));

    expect($result->accepted())->toBeTrue();
});

it('allows same commit SHA when previous task is failed', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'abc123',
        'status' => 'failed',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id, mrIid: 42, commitSha: 'abc123');

    $result = $dedup->process('00000000-0000-0000-0000-000000000004', dedupRouting('auto_review', 'normal', $event));

    expect($result->accepted())->toBeTrue();
});

it('allows same commit SHA when previous task was superseded', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'abc123',
        'status' => 'superseded',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id, mrIid: 42, commitSha: 'abc123');

    $result = $dedup->process('00000000-0000-0000-0000-00000000004b', dedupRouting('auto_review', 'normal', $event));

    expect($result->accepted())->toBeTrue();
});

it('skips commit SHA dedup for Note events (no commit SHA)', function (): void {
    $dedup = new EventDeduplicator;
    $event = dedupNoteOnMrEvent($this->project->id);

    $result = $dedup->process('00000000-0000-0000-0000-000000000005', dedupRouting('on_demand_review', 'high', $event));

    expect($result->accepted())->toBeTrue();
});

it('skips commit SHA dedup for Issue events (no commit SHA)', function (): void {
    $dedup = new EventDeduplicator;
    $event = dedupIssueLabelEvent($this->project->id);

    $result = $dedup->process('00000000-0000-0000-0000-000000000006', dedupRouting('feature_dev', 'low', $event));

    expect($result->accepted())->toBeTrue();
});

// ------------------------------------------------------------------
//  Latest-wins superseding (D140)
// ------------------------------------------------------------------

it('supersedes queued tasks when new MR update arrives (D140)', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'old-sha',
        'status' => 'queued',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrUpdatedEvent($this->project->id, mrIid: 42, commitSha: 'new-sha');

    $result = $dedup->process('00000000-0000-0000-0000-000000000007', dedupRouting('auto_review', 'normal', $event));

    expect($result->accepted())->toBeTrue()
        ->and($result->supersededCount)->toBe(1)
        ->and($result->didSupersede())->toBeTrue();

    $oldTask = DB::table('tasks')->where('commit_sha', 'old-sha')->first();
    expect($oldTask->status)->toBe('superseded');
});

it('supersedes running tasks when new MR update arrives (D140)', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'old-sha',
        'status' => 'running',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrUpdatedEvent($this->project->id, mrIid: 42, commitSha: 'new-sha');

    $result = $dedup->process('00000000-0000-0000-0000-000000000008', dedupRouting('auto_review', 'normal', $event));

    expect($result->accepted())->toBeTrue()
        ->and($result->supersededCount)->toBe(1);

    $oldTask = DB::table('tasks')->where('commit_sha', 'old-sha')->first();
    expect($oldTask->status)->toBe('superseded');
});

it('supersedes queued tasks when new MR opened arrives (D140)', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'old-sha',
        'status' => 'queued',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id, mrIid: 42, commitSha: 'brand-new');

    $result = $dedup->process('00000000-0000-0000-0000-00000000008b', dedupRouting('auto_review', 'normal', $event));

    expect($result->accepted())->toBeTrue()
        ->and($result->supersededCount)->toBe(1);
});

it('supersedes multiple active tasks for same MR (D140)', function (): void {
    foreach (['queued', 'running'] as $status) {
        insertTask($this->project->id, $this->user->id, [
            'mr_iid' => 42,
            'commit_sha' => "sha-{$status}",
            'status' => $status,
        ]);
    }

    $dedup = new EventDeduplicator;
    $event = dedupMrOpenedEvent($this->project->id, mrIid: 42, commitSha: 'brand-new');

    $result = $dedup->process('00000000-0000-0000-0000-000000000009', dedupRouting('auto_review', 'normal', $event));

    expect($result->supersededCount)->toBe(2);

    $remaining = DB::table('tasks')
        ->where('project_id', $this->project->id)
        ->where('mr_iid', 42)
        ->where('status', '!=', 'superseded')
        ->count();

    expect($remaining)->toBe(0);
});

it('does not supersede tasks for different MR (D140)', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 99,
        'commit_sha' => 'other-sha',
        'status' => 'queued',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrUpdatedEvent($this->project->id, mrIid: 42, commitSha: 'new-sha');

    $result = $dedup->process('00000000-0000-0000-0000-000000000010', dedupRouting('auto_review', 'normal', $event));

    expect($result->supersededCount)->toBe(0);

    $otherTask = DB::table('tasks')->where('mr_iid', 99)->first();
    expect($otherTask->status)->toBe('queued');
});

it('does not supersede tasks for different project (D140)', function (): void {
    $project2 = Project::factory()->enabled()->create();

    insertTask($project2->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'other-sha',
        'status' => 'queued',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupMrUpdatedEvent($this->project->id, mrIid: 42, commitSha: 'new-sha');

    $result = $dedup->process('00000000-0000-0000-0000-000000000011', dedupRouting('auto_review', 'normal', $event));

    expect($result->supersededCount)->toBe(0);
});

it('does not supersede completed, failed, or already superseded tasks (D140)', function (): void {
    foreach (['completed', 'failed', 'superseded'] as $status) {
        insertTask($this->project->id, $this->user->id, [
            'mr_iid' => 42,
            'commit_sha' => "sha-{$status}",
            'status' => $status,
        ]);
    }

    $dedup = new EventDeduplicator;
    $event = dedupMrUpdatedEvent($this->project->id, mrIid: 42, commitSha: 'new-sha');

    $result = $dedup->process('00000000-0000-0000-0000-000000000012', dedupRouting('auto_review', 'normal', $event));

    expect($result->supersededCount)->toBe(0);
});

it('does not supersede on Note events (D140 — only push/update trigger)', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => 42,
        'commit_sha' => 'old-sha',
        'status' => 'queued',
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupNoteOnMrEvent($this->project->id, mrIid: 42);

    $result = $dedup->process('00000000-0000-0000-0000-000000000013', dedupRouting('on_demand_review', 'high', $event));

    expect($result->supersededCount)->toBe(0);

    $oldTask = DB::table('tasks')->where('commit_sha', 'old-sha')->first();
    expect($oldTask->status)->toBe('queued');
});

it('does not supersede on Issue label events (D140)', function (): void {
    insertTask($this->project->id, $this->user->id, [
        'mr_iid' => null,
        'commit_sha' => null,
        'status' => 'queued',
        'type' => 'feature_dev',
        'priority' => 'low',
        'issue_iid' => 99,
    ]);

    $dedup = new EventDeduplicator;
    $event = dedupIssueLabelEvent($this->project->id);

    $result = $dedup->process('00000000-0000-0000-0000-000000000014', dedupRouting('feature_dev', 'low', $event));

    expect($result->supersededCount)->toBe(0);
});

// ------------------------------------------------------------------
//  DeduplicationResult value object
// ------------------------------------------------------------------

it('reports accepted state correctly', function (): void {
    $result = new DeduplicationResult(EventDeduplicator::ACCEPT, supersededCount: 0);

    expect($result->accepted())->toBeTrue()
        ->and($result->rejected())->toBeFalse()
        ->and($result->didSupersede())->toBeFalse();
});

it('reports rejected state correctly', function (): void {
    $result = new DeduplicationResult(EventDeduplicator::DUPLICATE_UUID, supersededCount: 0);

    expect($result->accepted())->toBeFalse()
        ->and($result->rejected())->toBeTrue();
});

it('reports superseding correctly', function (): void {
    $result = new DeduplicationResult(EventDeduplicator::ACCEPT, supersededCount: 3);

    expect($result->accepted())->toBeTrue()
        ->and($result->didSupersede())->toBeTrue()
        ->and($result->supersededCount)->toBe(3);
});
