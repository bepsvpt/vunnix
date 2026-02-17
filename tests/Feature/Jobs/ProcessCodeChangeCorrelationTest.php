<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessCodeChangeCorrelation;
use App\Models\FindingAcceptance;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('marks code_change_correlated when push modifies finding region', function (): void {
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => [
            'findings' => [
                ['id' => 1, 'severity' => 'critical', 'file' => 'src/auth.py', 'line' => 42, 'end_line' => 45, 'title' => 'SQL injection', 'category' => 'security', 'description' => '', 'suggestion' => '', 'labels' => []],
            ],
        ],
    ]);

    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'mr_iid' => 42,
        'finding_id' => '1',
        'file' => 'src/auth.py',
        'line' => 42,
        'severity' => 'critical',
        'title' => 'SQL injection',
        'status' => 'pending',
    ]);

    Http::fake([
        '*/api/v4/projects/*/repository/compare*' => Http::response([
            'diffs' => [
                [
                    'new_path' => 'src/auth.py',
                    'diff' => "@@ -40,8 +40,10 @@ class Auth\n context\n-bad\n+good\n context",
                ],
            ],
        ], 200),
    ]);

    $job = new ProcessCodeChangeCorrelation(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
        beforeSha: 'aaa111',
        afterSha: 'bbb222',
    );
    $job->handle(app(GitLabClient::class));

    $acceptance = FindingAcceptance::first();
    expect($acceptance->code_change_correlated)->toBeTrue();
    expect($acceptance->correlated_commit_sha)->toBe('bbb222');
});

it('does not correlate when push does not touch finding file', function (): void {
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => [
            'findings' => [
                ['id' => 1, 'severity' => 'critical', 'file' => 'src/auth.py', 'line' => 42, 'end_line' => 45, 'title' => 'SQL injection', 'category' => 'security', 'description' => '', 'suggestion' => '', 'labels' => []],
            ],
        ],
    ]);

    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'mr_iid' => 42,
        'finding_id' => '1',
        'file' => 'src/auth.py',
        'line' => 42,
        'severity' => 'critical',
        'title' => 'SQL injection',
        'status' => 'pending',
    ]);

    Http::fake([
        '*/api/v4/projects/*/repository/compare*' => Http::response([
            'diffs' => [
                [
                    'new_path' => 'src/other.py',
                    'diff' => "@@ -1,3 +1,5 @@\n context\n-old\n+new\n context",
                ],
            ],
        ], 200),
    ]);

    $job = new ProcessCodeChangeCorrelation(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
        beforeSha: 'aaa111',
        afterSha: 'bbb222',
    );
    $job->handle(app(GitLabClient::class));

    $acceptance = FindingAcceptance::first();
    expect($acceptance->code_change_correlated)->toBeFalse();
});

it('skips when no pending acceptances exist for the MR', function (): void {
    Http::fake();

    $job = new ProcessCodeChangeCorrelation(
        projectId: 1,
        gitlabProjectId: 100,
        mrIid: 999,
        beforeSha: 'aaa111',
        afterSha: 'bbb222',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Exception: compareBranches fails → logs warning, rethrows ──

it('logs warning and rethrows when compareBranches fails', function (): void {
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => [
            'findings' => [
                ['id' => 1, 'severity' => 'critical', 'file' => 'src/auth.py', 'line' => 42, 'end_line' => 45, 'title' => 'SQL injection', 'category' => 'security', 'description' => '', 'suggestion' => '', 'labels' => []],
            ],
        ],
    ]);

    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'mr_iid' => 42,
        'finding_id' => '1',
        'file' => 'src/auth.py',
        'line' => 42,
        'severity' => 'critical',
        'title' => 'SQL injection',
        'status' => 'pending',
    ]);

    Http::fake([
        '*/api/v4/projects/*/repository/compare*' => Http::response('Internal Server Error', 500),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'ProcessCodeChangeCorrelation: failed to compare branches')
                && $context['project_id'] > 0;
        });

    // Allow GitLabClient's own warning log
    Log::shouldReceive('warning')
        ->withArgs(function (string $message): bool {
            return str_contains($message, 'GitLab API error');
        })
        ->zeroOrMoreTimes();

    $job = new ProcessCodeChangeCorrelation(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
        beforeSha: 'aaa111',
        afterSha: 'bbb222',
    );

    expect(fn () => $job->handle(app(GitLabClient::class)))
        ->toThrow(\App\Exceptions\GitLabApiException::class);
});
