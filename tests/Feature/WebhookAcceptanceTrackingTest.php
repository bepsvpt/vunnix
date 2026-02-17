<?php

use App\Jobs\ProcessAcceptanceTracking;
use App\Jobs\ProcessCodeChangeCorrelation;
use App\Models\Project;
use App\Models\ProjectConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

/**
 * Helper: create an enabled project with webhook token and return [project, token].
 */
function acceptanceWebhookProject(string $secret = 'test-secret'): array
{
    $project = Project::factory()->enabled()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $secret,
    ]);

    return [$project, $secret];
}

// ─── MR merge → acceptance tracking ─────────────────────────────

it('dispatches ProcessAcceptanceTracking job on MR merge webhook', function (): void {
    [$project, $token] = acceptanceWebhookProject();

    $payload = [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'merge',
            'source_branch' => 'feature/test',
            'target_branch' => 'main',
            'author_id' => 1,
            'last_commit' => ['id' => 'abc123'],
        ],
    ];

    $response = $this->postJson('/webhook', $payload, [
        'X-Gitlab-Event' => 'Merge Request Hook',
        'X-Gitlab-Token' => $token,
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-aaa000000001',
    ]);

    $response->assertOk();
    $response->assertJson(['intent' => 'acceptance_tracking']);

    Queue::assertPushed(ProcessAcceptanceTracking::class, function ($job) use ($project) {
        return $job->projectId === $project->id
            && $job->gitlabProjectId === $project->gitlab_project_id
            && $job->mrIid === 42;
    });
});

it('does not dispatch acceptance tracking for non-merge MR actions', function (): void {
    [$project, $token] = acceptanceWebhookProject();

    $payload = [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'open',
            'source_branch' => 'feature/test',
            'target_branch' => 'main',
            'author_id' => 1,
            'last_commit' => ['id' => 'abc123'],
        ],
    ];

    $response = $this->postJson('/webhook', $payload, [
        'X-Gitlab-Event' => 'Merge Request Hook',
        'X-Gitlab-Token' => $token,
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-aaa000000002',
    ]);

    $response->assertOk();

    Queue::assertNotPushed(ProcessAcceptanceTracking::class);
});

// ─── Push → code change correlation ─────────────────────────────

it('dispatches ProcessCodeChangeCorrelation on push webhook', function (): void {
    Queue::fake([ProcessCodeChangeCorrelation::class]);

    Http::fake([
        '*/api/v4/projects/*/merge_requests*' => Http::response([
            ['iid' => 42],
        ], 200),
    ]);

    [$project, $token] = acceptanceWebhookProject();

    $payload = [
        'object_kind' => 'push',
        'ref' => 'refs/heads/feature/test',
        'before' => 'aaa111',
        'after' => 'bbb222',
        'user_id' => 1,
        'commits' => [],
        'total_commits_count' => 1,
    ];

    $response = $this->postJson('/webhook', $payload, [
        'X-Gitlab-Event' => 'Push Hook',
        'X-Gitlab-Token' => $token,
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-aaa000000003',
    ]);

    $response->assertOk();

    Queue::assertPushed(ProcessCodeChangeCorrelation::class, function ($job) use ($project) {
        return $job->projectId === $project->id
            && $job->mrIid === 42
            && $job->beforeSha === 'aaa111'
            && $job->afterSha === 'bbb222';
    });
});
