<?php

use App\Services\AcceptanceTrackingService;

// ─── classifyThreadState ─────────────────────────────────────────

it('classifies resolved thread as accepted', function (): void {
    $service = new AcceptanceTrackingService;

    $discussion = [
        'id' => 'disc-1',
        'notes' => [[
            'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**",
            'resolved' => true,
            'position' => ['new_path' => 'src/auth.py', 'new_line' => 42],
        ]],
    ];

    expect($service->classifyThreadState($discussion))->toBe('accepted');
});

it('classifies unresolved thread as dismissed', function (): void {
    $service = new AcceptanceTrackingService;

    $discussion = [
        'id' => 'disc-2',
        'notes' => [[
            'body' => "🟡 **Major** | Bug\n\n**Null pointer**",
            'resolved' => false,
            'position' => ['new_path' => 'src/utils.py', 'new_line' => 18],
        ]],
    ];

    expect($service->classifyThreadState($discussion))->toBe('dismissed');
});

// ─── detectBulkResolution ────────────────────────────────────────

it('detects bulk resolution when all threads resolved within 60 seconds', function (): void {
    $service = new AcceptanceTrackingService;

    $discussions = [
        ['id' => 'disc-1', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:00Z']]],
        ['id' => 'disc-2', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:30Z']]],
        ['id' => 'disc-3', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:45Z']]],
    ];

    expect($service->detectBulkResolution($discussions))->toBeTrue();
});

it('does not flag bulk resolution when threads resolved over time', function (): void {
    $service = new AcceptanceTrackingService;

    $discussions = [
        ['id' => 'disc-1', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:00Z']]],
        ['id' => 'disc-2', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:30:00Z']]],
        ['id' => 'disc-3', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T11:00:00Z']]],
    ];

    expect($service->detectBulkResolution($discussions))->toBeFalse();
});

it('does not flag bulk resolution with fewer than 3 threads', function (): void {
    $service = new AcceptanceTrackingService;

    $discussions = [
        ['id' => 'disc-1', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:00Z']]],
        ['id' => 'disc-2', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:05Z']]],
    ];

    expect($service->detectBulkResolution($discussions))->toBeFalse();
});

// ─── correlateCodeChange ─────────────────────────────────────────

it('detects code change correlation when push modifies finding region', function (): void {
    $service = new AcceptanceTrackingService;

    $finding = ['file' => 'src/auth.py', 'line' => 42, 'end_line' => 45];

    // GitLab compare API returns diffs with modified line ranges
    $diffs = [
        [
            'new_path' => 'src/auth.py',
            'diff' => "@@ -40,8 +40,10 @@ class Auth\n some context\n-bad line\n+good line\n more context",
        ],
    ];

    expect($service->correlateCodeChange($finding, $diffs))->toBeTrue();
});

it('does not correlate when push does not touch finding file', function (): void {
    $service = new AcceptanceTrackingService;

    $finding = ['file' => 'src/auth.py', 'line' => 42, 'end_line' => 45];

    $diffs = [
        [
            'new_path' => 'src/other.py',
            'diff' => "@@ -1,3 +1,5 @@ something\n some context\n-old\n+new\n more",
        ],
    ];

    expect($service->correlateCodeChange($finding, $diffs))->toBeFalse();
});

it('does not correlate when push modifies different region of same file', function (): void {
    $service = new AcceptanceTrackingService;

    $finding = ['file' => 'src/auth.py', 'line' => 42, 'end_line' => 45];

    $diffs = [
        [
            'new_path' => 'src/auth.py',
            'diff' => "@@ -100,3 +100,5 @@ class Auth\n far away context\n-old line\n+new line\n more",
        ],
    ];

    expect($service->correlateCodeChange($finding, $diffs))->toBeFalse();
});

// ─── isAiCreatedDiscussion ───────────────────────────────────────

it('identifies AI-created discussions by severity tag markers', function (): void {
    $service = new AcceptanceTrackingService;

    $aiDiscussion = [
        'notes' => [[
            'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**",
        ]],
    ];

    $humanDiscussion = [
        'notes' => [[
            'body' => 'This looks wrong, can you fix it?',
        ]],
    ];

    expect($service->isAiCreatedDiscussion($aiDiscussion))->toBeTrue();
    expect($service->isAiCreatedDiscussion($humanDiscussion))->toBeFalse();
});
