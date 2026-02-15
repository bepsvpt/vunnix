<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessAcceptanceTracking;
use App\Models\FindingAcceptance;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function createReviewTaskForEmoji(int $mrIid = 42, array $findings = []): Task
{
    if (empty($findings)) {
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

function fakeDiscussionsWithNoteIds(): array
{
    return [
        [
            'id' => 'disc-ai-1',
            'notes' => [[
                'id' => 100,
                'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**\n\nUser input in SQL query.",
                'resolved' => true,
                'updated_at' => '2026-02-15T10:00:00Z',
                'position' => ['new_path' => 'src/auth.py', 'new_line' => 42],
            ]],
        ],
        [
            'id' => 'disc-ai-2',
            'notes' => [[
                'id' => 200,
                'body' => "🟡 **Major** | Bug\n\n**Null pointer dereference**\n\nUser may be null.",
                'resolved' => false,
                'updated_at' => '2026-02-15T10:05:00Z',
                'position' => ['new_path' => 'src/utils.py', 'new_line' => 18],
            ]],
        ],
    ];
}

// ─── Emoji reaction collection ──────────────────────────────────

it('stores emoji reactions alongside finding acceptance records', function () {
    Http::fake([
        // Emoji patterns must come BEFORE the discussions pattern — both contain
        // "discussions" in the path, and Http::fake matches the first pattern.
        '*/notes/100/award_emoji*' => Http::response([
            ['id' => 1, 'name' => 'thumbsup', 'user' => ['id' => 5]],
        ], 200),
        '*/notes/200/award_emoji*' => Http::response([
            ['id' => 2, 'name' => 'thumbsdown', 'user' => ['id' => 6]],
        ], 200),
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeDiscussionsWithNoteIds(), 200),
    ]);

    $task = createReviewTaskForEmoji();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    $accepted = FindingAcceptance::where('finding_id', '1')->first();
    expect($accepted->emoji_positive_count)->toBe(1);
    expect($accepted->emoji_negative_count)->toBe(0);
    expect($accepted->emoji_sentiment)->toBe('positive');
    expect($accepted->category)->toBe('security');

    $dismissed = FindingAcceptance::where('finding_id', '2')->first();
    expect($dismissed->emoji_positive_count)->toBe(0);
    expect($dismissed->emoji_negative_count)->toBe(1);
    expect($dismissed->emoji_sentiment)->toBe('negative');
    expect($dismissed->category)->toBe('bug');
});

it('stores neutral sentiment when no emoji reactions exist', function () {
    Http::fake([
        '*/award_emoji*' => Http::response([], 200),
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeDiscussionsWithNoteIds(), 200),
    ]);

    $task = createReviewTaskForEmoji();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    $records = FindingAcceptance::all();
    expect($records)->toHaveCount(2);
    $records->each(fn ($r) => expect($r->emoji_sentiment)->toBe('neutral'));
});

it('continues processing even when emoji API call fails for one note', function () {
    Http::fake([
        '*/notes/100/award_emoji*' => Http::response('Server Error', 500),
        '*/notes/200/award_emoji*' => Http::response([
            ['id' => 2, 'name' => 'thumbsup', 'user' => ['id' => 5]],
        ], 200),
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeDiscussionsWithNoteIds(), 200),
    ]);

    $task = createReviewTaskForEmoji();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    expect(FindingAcceptance::count())->toBe(2);

    $first = FindingAcceptance::where('finding_id', '1')->first();
    expect($first->emoji_sentiment)->toBe('neutral');

    $second = FindingAcceptance::where('finding_id', '2')->first();
    expect($second->emoji_positive_count)->toBe(1);
    expect($second->emoji_sentiment)->toBe('positive');
});
