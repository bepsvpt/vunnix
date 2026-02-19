<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessAcceptanceTracking;
use App\Models\FindingAcceptance;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function createReviewTaskWithFindings(int $mrIid = 42, array $findings = []): Task
{
    if ($findings === []) {
        $findings = [
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
                'title' => 'Null pointer dereference',
                'description' => 'User may be null.',
                'suggestion' => 'Add null check.',
                'labels' => [],
            ],
        ];
    }

    return Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(5),
        'mr_iid' => $mrIid,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'high',
                'total_findings' => count($findings),
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
                'walkthrough' => [],
            ],
            'findings' => $findings,
            'labels' => ['ai::reviewed'],
            'commit_status' => 'failed',
        ],
    ]);
}

function fakeGitLabDiscussions(): array
{
    return [
        // AI thread — resolved (accepted)
        [
            'id' => 'disc-ai-1',
            'notes' => [[
                'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**\n\nUser input in SQL query.",
                'resolved' => true,
                'updated_at' => '2026-02-15T10:00:00Z',
                'position' => ['new_path' => 'src/auth.py', 'new_line' => 42],
            ]],
        ],
        // AI thread — unresolved (dismissed)
        [
            'id' => 'disc-ai-2',
            'notes' => [[
                'body' => "🟡 **Major** | Bug\n\n**Null pointer dereference**\n\nUser may be null.",
                'resolved' => false,
                'updated_at' => '2026-02-15T10:05:00Z',
                'position' => ['new_path' => 'src/utils.py', 'new_line' => 18],
            ]],
        ],
        // Human thread — should be ignored
        [
            'id' => 'disc-human-1',
            'notes' => [[
                'body' => 'Nice work on the refactoring!',
                'resolved' => true,
                'updated_at' => '2026-02-15T09:00:00Z',
            ]],
        ],
    ];
}

// ─── MR merge final classification ───────────────────────────────

it('classifies AI findings as accepted or dismissed on MR merge', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeGitLabDiscussions(), 200),
    ]);

    $task = createReviewTaskWithFindings();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    // Should create 2 FindingAcceptance records (one per AI finding, not for human thread)
    expect(FindingAcceptance::count())->toBe(2);

    $accepted = FindingAcceptance::where('status', 'accepted')->first();
    expect($accepted->finding_id)->toBe('1');
    expect($accepted->file)->toBe('src/auth.py');
    expect($accepted->gitlab_discussion_id)->toBe('disc-ai-1');

    $dismissed = FindingAcceptance::where('status', 'dismissed')->first();
    expect($dismissed->finding_id)->toBe('2');
    expect($dismissed->file)->toBe('src/utils.py');
    expect($dismissed->gitlab_discussion_id)->toBe('disc-ai-2');
});

it('skips if no completed review tasks exist for the MR', function (): void {
    Http::fake();

    $job = new ProcessAcceptanceTracking(
        projectId: 1,
        gitlabProjectId: 100,
        mrIid: 999,
    );
    $job->handle(app(GitLabClient::class));

    expect(FindingAcceptance::count())->toBe(0);
    Http::assertNothingSent();
});

it('detects bulk resolution and flags acceptance records', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response([
            // 3 AI threads all resolved within 30 seconds
            [
                'id' => 'disc-1',
                'notes' => [[
                    'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**",
                    'resolved' => true,
                    'updated_at' => '2026-02-15T10:00:00Z',
                    'position' => ['new_path' => 'src/auth.py', 'new_line' => 42],
                ]],
            ],
            [
                'id' => 'disc-2',
                'notes' => [[
                    'body' => "🟡 **Major** | Bug\n\n**Null pointer dereference**",
                    'resolved' => true,
                    'updated_at' => '2026-02-15T10:00:15Z',
                    'position' => ['new_path' => 'src/utils.py', 'new_line' => 18],
                ]],
            ],
            [
                'id' => 'disc-3',
                'notes' => [[
                    'body' => "🟡 **Major** | Performance\n\n**N+1 query detected**",
                    'resolved' => true,
                    'updated_at' => '2026-02-15T10:00:25Z',
                    'position' => ['new_path' => 'src/db.py', 'new_line' => 50],
                ]],
            ],
        ], 200),
    ]);

    // Need a task with 3 findings
    $task = createReviewTaskWithFindings(42, [
        ['id' => 1, 'severity' => 'critical', 'category' => 'security', 'file' => 'src/auth.py', 'line' => 42, 'end_line' => 45, 'title' => 'SQL injection risk', 'description' => 'Desc', 'suggestion' => 'Fix', 'labels' => []],
        ['id' => 2, 'severity' => 'major', 'category' => 'bug', 'file' => 'src/utils.py', 'line' => 18, 'end_line' => 22, 'title' => 'Null pointer dereference', 'description' => 'Desc', 'suggestion' => 'Fix', 'labels' => []],
        ['id' => 3, 'severity' => 'major', 'category' => 'performance', 'file' => 'src/db.py', 'line' => 50, 'end_line' => 55, 'title' => 'N+1 query detected', 'description' => 'Desc', 'suggestion' => 'Fix', 'labels' => []],
    ]);

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    expect(FindingAcceptance::count())->toBe(3);
    expect(FindingAcceptance::where('bulk_resolved', true)->count())->toBe(3);
});

// ─── Integration: acceptance rate calculation ────────────────────

it('produces correct acceptance rate from stored records', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeGitLabDiscussions(), 200),
    ]);

    $task = createReviewTaskWithFindings();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    $total = FindingAcceptance::where('project_id', $task->project_id)
        ->where('mr_iid', 42)
        ->count();
    $accepted = FindingAcceptance::where('project_id', $task->project_id)
        ->where('mr_iid', 42)
        ->whereIn('status', ['accepted', 'accepted_auto'])
        ->count();

    $rate = $total > 0 ? round(($accepted / $total) * 100, 1) : 0;

    // 1 accepted, 1 dismissed → 50%
    expect($rate)->toBe(50.0);
});

it('skips non critical and non major findings', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeGitLabDiscussions(), 200),
    ]);

    $task = createReviewTaskWithFindings(42, [
        ['id' => 10, 'severity' => 'minor', 'category' => 'style', 'file' => 'src/style.py', 'line' => 1, 'end_line' => 2, 'title' => 'Style issue', 'description' => 'Desc', 'suggestion' => 'Fix', 'labels' => []],
    ]);

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    expect(FindingAcceptance::count())->toBe(0);
});

it('logs and rethrows when discussions cannot be fetched', function (): void {
    $task = createReviewTaskWithFindings();

    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(['message' => 'error'], 500),
    ]);

    Log::shouldReceive('warning')
        ->withAnyArgs()
        ->atLeast()
        ->once();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );

    expect(fn () => $job->handle(app(GitLabClient::class)))
        ->toThrow(\App\Exceptions\GitLabApiException::class);
});
