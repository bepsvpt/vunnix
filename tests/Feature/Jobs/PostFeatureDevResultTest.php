<?php

use App\Jobs\PostFeatureDevResult;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('updates existing MR instead of creating new one when existing_mr_iid is set', function (): void {
    $task = Task::factory()->running()->create([
        'type' => 'ui_adjustment',
        'mr_iid' => 456,
        'issue_iid' => 10,
        'result' => [
            'existing_mr_iid' => 456,
            'branch' => 'ai/fix-card-padding',
            'mr_title' => 'Fix card padding',
            'mr_description' => 'Reduced padding on mobile',
            'files_changed' => [
                ['path' => 'styles/card.css', 'action' => 'modified', 'summary' => 'Padding fix'],
            ],
        ],
    ]);

    Http::fake([
        // Should call PUT (update), not POST (create)
        '*/merge_requests/456' => Http::response([
            'iid' => 456,
            'title' => 'Fix card padding',
            'web_url' => 'https://gitlab.example.com/-/merge_requests/456',
        ], 200),
        '*/issues/10/notes' => Http::response(['id' => 999], 201),
    ]);

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    // MR IID should remain unchanged (456, not a new one)
    $task->refresh();
    expect($task->mr_iid)->toBe(456);

    // Should have called PUT (update), not POST (create) for the MR
    Http::assertSent(function ($request): bool {
        return $request->method() === 'PUT'
            && str_contains($request->url(), 'merge_requests/456');
    });

    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'merge_requests');
    });
});

it('skips issue summary when task has no issue_iid (conversation origin)', function (): void {
    $task = Task::factory()->running()->create([
        'type' => 'ui_adjustment',
        'mr_iid' => null,
        'issue_iid' => null,
        'conversation_id' => 'conv-designer-123',
        'result' => [
            'branch' => 'ai/fix-padding',
            'mr_title' => 'Fix card padding',
            'mr_description' => 'Reduced padding',
            'files_changed' => [],
        ],
    ]);

    Http::fake([
        '*/merge_requests' => Http::response([
            'iid' => 789,
            'title' => 'Fix card padding',
        ], 201),
    ]);

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    $task->refresh();
    expect($task->mr_iid)->toBe(789);

    // Should NOT call the issue notes endpoint
    Http::assertNotSent(function ($request): bool {
        return str_contains($request->url(), '/notes');
    });
});

it('creates new MR when existing_mr_iid is not set', function (): void {
    $task = Task::factory()->running()->create([
        'type' => 'feature_dev',
        'mr_iid' => null,
        'issue_iid' => 10,
        'result' => [
            'branch' => 'ai/new-feature',
            'mr_title' => 'Add new feature',
            'mr_description' => 'Feature description',
            'files_changed' => [],
        ],
    ]);

    Http::fake([
        '*/merge_requests' => Http::response([
            'iid' => 789,
            'title' => 'Add new feature',
        ], 201),
        '*/issues/10/notes' => Http::response(['id' => 111], 201),
    ]);

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    $task->refresh();
    expect($task->mr_iid)->toBe(789);

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'merge_requests');
    });
});
