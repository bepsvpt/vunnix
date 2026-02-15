<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessThreadResolution;
use App\Models\FindingAcceptance;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates finding acceptance to accepted when thread is resolved', function () {
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => [
            'findings' => [
                ['id' => 1, 'severity' => 'critical', 'file' => 'src/auth.py', 'line' => 42, 'end_line' => 45, 'title' => 'SQL injection risk', 'category' => 'security', 'description' => '', 'suggestion' => '', 'labels' => []],
            ],
        ],
    ]);

    // Pre-create a pending acceptance record
    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'mr_iid' => 42,
        'finding_id' => '1',
        'file' => 'src/auth.py',
        'line' => 42,
        'severity' => 'critical',
        'title' => 'SQL injection risk',
        'gitlab_discussion_id' => 'disc-ai-1',
        'status' => 'pending',
    ]);

    $job = new ProcessThreadResolution(
        projectId: $task->project_id,
        mrIid: 42,
        discussionId: 'disc-ai-1',
        resolved: true,
    );
    $job->handle();

    $acceptance = FindingAcceptance::first();
    expect($acceptance->status)->toBe('accepted');
    expect($acceptance->resolved_at)->not->toBeNull();
});

it('reverts acceptance to pending when thread is unresolved', function () {
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
    ]);

    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'mr_iid' => 42,
        'finding_id' => '1',
        'file' => 'src/auth.py',
        'line' => 42,
        'severity' => 'critical',
        'title' => 'SQL injection risk',
        'gitlab_discussion_id' => 'disc-ai-1',
        'status' => 'accepted',
        'resolved_at' => now(),
    ]);

    $job = new ProcessThreadResolution(
        projectId: $task->project_id,
        mrIid: 42,
        discussionId: 'disc-ai-1',
        resolved: false,
    );
    $job->handle();

    $acceptance = FindingAcceptance::first();
    expect($acceptance->status)->toBe('pending');
    expect($acceptance->resolved_at)->toBeNull();
});

it('does nothing when no matching acceptance record exists', function () {
    $job = new ProcessThreadResolution(
        projectId: 1,
        mrIid: 999,
        discussionId: 'disc-unknown',
        resolved: true,
    );
    $job->handle();

    expect(FindingAcceptance::count())->toBe(0);
});
