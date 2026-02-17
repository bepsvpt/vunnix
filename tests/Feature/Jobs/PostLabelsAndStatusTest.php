<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostLabelsAndStatus;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────

function labelsTaskWithCritical(): Task
{
    return Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'mr_iid' => 42,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'high',
                'total_findings' => 2,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'src/auth.py', 'change_summary' => 'Added auth logic'],
                ],
            ],
            'findings' => [
                [
                    'id' => 1,
                    'severity' => 'critical',
                    'category' => 'security',
                    'file' => 'src/auth.py',
                    'line' => 42,
                    'end_line' => 45,
                    'title' => 'SQL injection risk',
                    'description' => 'User input in SQL query.',
                    'suggestion' => 'Use parameterized queries.',
                    'labels' => [],
                ],
                [
                    'id' => 2,
                    'severity' => 'major',
                    'category' => 'bug',
                    'file' => 'src/utils.py',
                    'line' => 18,
                    'end_line' => 22,
                    'title' => 'Null dereference',
                    'description' => 'User may be null.',
                    'suggestion' => 'Add null check.',
                    'labels' => [],
                ],
            ],
            'labels' => ['ai::reviewed', 'ai::risk-high'],
            'commit_status' => 'failed',
        ],
    ]);
}

function labelsTaskNoFindings(): Task
{
    return Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'mr_iid' => 42,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'README.md', 'change_summary' => 'Updated docs'],
                ],
            ],
            'findings' => [],
            'labels' => ['ai::reviewed', 'ai::risk-low'],
            'commit_status' => 'success',
        ],
    ]);
}

function fakeMrForLabels(): array
{
    return [
        'iid' => 42,
        'sha' => 'abc123def456',
        'diff_refs' => [
            'base_sha' => 'aaa111',
            'start_sha' => 'bbb222',
            'head_sha' => 'ccc333',
        ],
    ];
}

// ─── Applies correct labels for high risk + security ────────────

it('adds risk-high and security labels for critical security findings', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::sequence()
            ->push(fakeMrForLabels(), 200)   // getMergeRequest
            ->push(['iid' => 42], 200)       // removeMergeRequestLabels (T40/D56)
            ->push(['iid' => 42], 200),      // addMergeRequestLabels (PUT)
        '*/api/v4/projects/*/statuses/*' => Http::response(['id' => 1, 'status' => 'failed'], 201),
    ]);

    $task = labelsTaskWithCritical();

    $job = new PostLabelsAndStatus($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }
        if (! str_contains($request->url(), '/merge_requests/42')) {
            return false;
        }

        $addLabels = $request['add_labels'] ?? '';

        return str_contains($addLabels, 'ai::reviewed')
            && str_contains($addLabels, 'ai::risk-high')
            && str_contains($addLabels, 'ai::security');
    });
});

// ─── Sets commit status to failed for critical findings ─────────

it('sets commit status to failed when critical findings exist', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::sequence()
            ->push(fakeMrForLabels(), 200)
            ->push(['iid' => 42], 200)       // removeMergeRequestLabels (T40/D56)
            ->push(['iid' => 42], 200),      // addMergeRequestLabels
        '*/api/v4/projects/*/statuses/*' => Http::response(['id' => 1, 'status' => 'failed'], 201),
    ]);

    $task = labelsTaskWithCritical();

    $job = new PostLabelsAndStatus($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/statuses/')) {
            return false;
        }

        return ($request['state'] ?? '') === 'failed'
            && ($request['name'] ?? '') === 'vunnix-code-review';
    });
});

// ─── Applies low risk labels and success status for no findings ──

it('adds risk-low label and success status for clean review', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::sequence()
            ->push(fakeMrForLabels(), 200)
            ->push(['iid' => 42], 200)       // removeMergeRequestLabels (T40/D56)
            ->push(['iid' => 42], 200),      // addMergeRequestLabels
        '*/api/v4/projects/*/statuses/*' => Http::response(['id' => 1, 'status' => 'success'], 201),
    ]);

    $task = labelsTaskNoFindings();

    $job = new PostLabelsAndStatus($task->id);
    $job->handle(app(GitLabClient::class));

    // Check labels
    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }

        $addLabels = $request['add_labels'] ?? '';

        return str_contains($addLabels, 'ai::reviewed')
            && str_contains($addLabels, 'ai::risk-low')
            && ! str_contains($addLabels, 'ai::security');
    });

    // Check commit status
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/statuses/')) {
            return false;
        }

        return ($request['state'] ?? '') === 'success';
    });
});

// ─── Uses SHA from MR response for commit status ────────────────

it('uses the SHA from the MR response when setting commit status', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::sequence()
            ->push(fakeMrForLabels(), 200)
            ->push(['iid' => 42], 200)       // removeMergeRequestLabels (T40/D56)
            ->push(['iid' => 42], 200),      // addMergeRequestLabels
        '*/api/v4/projects/*/statuses/*' => Http::response(['id' => 1], 201),
    ]);

    $task = labelsTaskNoFindings();

    $job = new PostLabelsAndStatus($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/statuses/abc123def456');
    });
});

// ─── Skips if task not found ────────────────────────────────────

it('returns early if the task does not exist', function (): void {
    Http::fake();

    $job = new PostLabelsAndStatus(999999);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if task has no MR IID ────────────────────────────────

it('returns early if the task has no mr_iid', function (): void {
    Http::fake();

    $task = Task::factory()->create([
        'type' => TaskType::IssueDiscussion,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'mr_iid' => null,
        'result' => ['response' => 'Some discussion.'],
    ]);

    $job = new PostLabelsAndStatus($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if task has no result ────────────────────────────────

it('returns early if the task has no result', function (): void {
    Http::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'result' => null,
    ]);

    $job = new PostLabelsAndStatus($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── T40: Removes old AI risk labels on incremental review (D56) ──

it('removes stale AI risk labels when review risk level changes', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::sequence()
            ->push(fakeMrForLabels(), 200)                         // getMergeRequest
            ->push(['iid' => 42], 200)                             // removeMergeRequestLabels (PUT)
            ->push(['iid' => 42], 200),                            // addMergeRequestLabels (PUT)
        '*/api/v4/projects/*/statuses/*' => Http::response(['id' => 1, 'status' => 'success'], 201),
    ]);

    // New review result: risk_level=low (downgraded from high)
    $task = labelsTaskNoFindings();

    $job = new PostLabelsAndStatus($task->id);
    $job->handle(app(GitLabClient::class));

    // Verify a remove_labels PUT was sent to clear stale risk labels
    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }
        if (! str_contains($request->url(), '/merge_requests/42')) {
            return false;
        }

        $removeLabels = $request['remove_labels'] ?? '';

        // New risk is 'low', so 'high' and 'medium' should be removed
        return str_contains($removeLabels, 'ai::risk-high')
            && str_contains($removeLabels, 'ai::risk-medium')
            && ! str_contains($removeLabels, 'ai::risk-low');
    });
});

it('removes other risk labels even when current risk level is high', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::sequence()
            ->push(fakeMrForLabels(), 200)                         // getMergeRequest
            ->push(['iid' => 42], 200)                             // removeMergeRequestLabels (PUT)
            ->push(['iid' => 42], 200),                            // addMergeRequestLabels (PUT)
        '*/api/v4/projects/*/statuses/*' => Http::response(['id' => 1, 'status' => 'failed'], 201),
    ]);

    $task = labelsTaskWithCritical(); // risk_level=high

    $job = new PostLabelsAndStatus($task->id);
    $job->handle(app(GitLabClient::class));

    // Should remove medium and low (the non-active risk labels)
    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }
        if (! str_contains($request->url(), '/merge_requests/42')) {
            return false;
        }

        $removeLabels = $request['remove_labels'] ?? '';

        return str_contains($removeLabels, 'ai::risk-medium')
            && str_contains($removeLabels, 'ai::risk-low')
            && ! str_contains($removeLabels, 'ai::risk-high');
    });
});

it('continues adding labels when remove old labels fails', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::sequence()
            ->push(fakeMrForLabels(), 200)                         // getMergeRequest
            ->push(null, 500)                                      // removeMergeRequestLabels fails
            ->push(['iid' => 42], 200),                            // addMergeRequestLabels succeeds
        '*/api/v4/projects/*/statuses/*' => Http::response(['id' => 1, 'status' => 'success'], 201),
    ]);

    $task = labelsTaskNoFindings();

    $job = new PostLabelsAndStatus($task->id);
    $job->handle(app(GitLabClient::class));

    // Even though remove failed, add_labels should still be sent
    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }

        $addLabels = $request['add_labels'] ?? '';

        return str_contains($addLabels, 'ai::reviewed')
            && str_contains($addLabels, 'ai::risk-low');
    });
});
