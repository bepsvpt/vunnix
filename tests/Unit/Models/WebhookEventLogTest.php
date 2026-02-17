<?php

use App\Models\Project;
use App\Models\WebhookEventLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('defines a project BelongsTo relationship', function (): void {
    $log = new WebhookEventLog;
    $relation = $log->project();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Project::class);
});

it('loads project relationship from database', function (): void {
    $project = Project::factory()->create();

    $log = WebhookEventLog::create([
        'gitlab_event_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'project_id' => $project->id,
        'event_type' => 'merge_request',
        'intent' => 'auto_review',
        'mr_iid' => 42,
        'commit_sha' => str_repeat('a', 40),
    ]);

    $loaded = WebhookEventLog::with('project')->find($log->id);

    expect($loaded->project)->toBeInstanceOf(Project::class)
        ->and($loaded->project->id)->toBe($project->id);
});

it('has correct table name', function (): void {
    $log = new WebhookEventLog;
    expect($log->getTable())->toBe('webhook_events');
});

it('casts mr_iid to integer', function (): void {
    $log = new WebhookEventLog;
    $casts = $log->getCasts();
    expect($casts['mr_iid'])->toBe('integer');
});

it('casts created_at to datetime', function (): void {
    $log = new WebhookEventLog;
    $casts = $log->getCasts();
    expect($casts['created_at'])->toBe('datetime');
});
