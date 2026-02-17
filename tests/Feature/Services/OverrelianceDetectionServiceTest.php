<?php

use App\Models\OverrelianceAlert;
use App\Models\Project;
use App\Models\Task;
use App\Services\OverrelianceDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->project = Project::factory()->enabled()->create();
});

// ─── High acceptance rate ───────────────────────────────────────────

it('creates alert when acceptance rate exceeds 95% for 2 consecutive weeks', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Service checks w=1 (Feb 2–8) and w=2 (Jan 26–Feb 1)
    // w=2 (Jan 26–Feb 1): 20 findings, 19 accepted + 1 dismissed = 95% → use 96% instead
    seedAcceptances($this->project, 24, 'accepted', Carbon::parse('2026-01-27 12:00:00'));
    seedAcceptances($this->project, 1, 'dismissed', Carbon::parse('2026-01-28 12:00:00'));

    // w=1 (Feb 2–8): 20 findings, 20 accepted = 100%
    seedAcceptances($this->project, 20, 'accepted', Carbon::parse('2026-02-03 12:00:00'));

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateHighAcceptanceRate($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('high_acceptance_rate')
        ->and($alert->severity)->toBe('warning');
});

it('does not create alert when acceptance rate is 94%', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // w=2 (Jan 26–Feb 1): 50 findings, 47 accepted = 94%
    seedAcceptances($this->project, 47, 'accepted', Carbon::parse('2026-01-27 12:00:00'));
    seedAcceptances($this->project, 3, 'dismissed', Carbon::parse('2026-01-28 12:00:00'));

    // w=1 (Feb 2–8): same
    seedAcceptances($this->project, 47, 'accepted', Carbon::parse('2026-02-03 12:00:00'));
    seedAcceptances($this->project, 3, 'dismissed', Carbon::parse('2026-02-04 12:00:00'));

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateHighAcceptanceRate($now);

    expect($alert)->toBeNull();
});

it('does not create alert when high acceptance rate for only 1 week', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // w=2 (Jan 26–Feb 1): 20 findings, 15 accepted = 75% (below threshold)
    seedAcceptances($this->project, 15, 'accepted', Carbon::parse('2026-01-27 12:00:00'));
    seedAcceptances($this->project, 5, 'dismissed', Carbon::parse('2026-01-28 12:00:00'));

    // w=1 (Feb 2–8): 20 findings, 20 accepted = 100% (above but only 1 week consecutive)
    seedAcceptances($this->project, 20, 'accepted', Carbon::parse('2026-02-03 12:00:00'));

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateHighAcceptanceRate($now);

    expect($alert)->toBeNull();
});

it('skips high acceptance rate when insufficient data', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Only 2 findings in w=1 (Feb 2–8) — not enough to be meaningful
    seedAcceptances($this->project, 2, 'accepted', Carbon::parse('2026-02-03 12:00:00'));

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateHighAcceptanceRate($now);

    expect($alert)->toBeNull();
});

// ─── Critical acceptance rate ───────────────────────────────────────

it('creates alert when critical finding acceptance exceeds 99%', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 100 critical findings, all accepted
    seedAcceptances($this->project, 100, 'accepted', $now->copy()->subDays(14), 'critical');

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateCriticalAcceptanceRate($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('critical_acceptance_rate')
        ->and($alert->severity)->toBe('warning');
});

it('does not create alert when critical acceptance is below 99%', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 100 critical findings, 98 accepted = 98%
    seedAcceptances($this->project, 98, 'accepted', $now->copy()->subDays(14), 'critical');
    seedAcceptances($this->project, 2, 'dismissed', $now->copy()->subDays(14), 'critical');

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateCriticalAcceptanceRate($now);

    expect($alert)->toBeNull();
});

// ─── Bulk resolution pattern ────────────────────────────────────────

it('creates alert when bulk resolution ratio exceeds threshold', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 20 findings, 15 bulk-resolved (75%)
    seedAcceptances($this->project, 15, 'accepted', $now->copy()->subDays(7), 'major', true);
    seedAcceptances($this->project, 5, 'accepted', $now->copy()->subDays(7), 'major', false);

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateBulkResolution($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('bulk_resolution')
        ->and($alert->severity)->toBe('warning');
});

it('does not create alert when bulk resolution ratio is low', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 20 findings, 2 bulk-resolved (10%)
    seedAcceptances($this->project, 2, 'accepted', $now->copy()->subDays(7), 'major', true);
    seedAcceptances($this->project, 18, 'accepted', $now->copy()->subDays(7), 'major', false);

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateBulkResolution($now);

    expect($alert)->toBeNull();
});

// ─── Zero reactions ─────────────────────────────────────────────────

it('creates alert when zero negative reactions across many reviews', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 30 findings, all with 0 negative reactions
    seedAcceptances($this->project, 30, 'accepted', $now->copy()->subDays(14));

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateZeroReactions($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('zero_reactions')
        ->and($alert->severity)->toBe('info');
});

it('does not create alert when negative reactions exist', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 30 findings, some with negative reactions
    seedAcceptances($this->project, 28, 'accepted', $now->copy()->subDays(14));
    // 2 findings with negative feedback
    seedAcceptances($this->project, 2, 'accepted', $now->copy()->subDays(14), 'major', false, 1);

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateZeroReactions($now);

    expect($alert)->toBeNull();
});

it('does not create alert when too few findings for reaction check', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Only 5 findings — below minimum threshold of 20
    seedAcceptances($this->project, 5, 'accepted', $now->copy()->subDays(14));

    $service = new OverrelianceDetectionService;
    $alert = $service->evaluateZeroReactions($now);

    expect($alert)->toBeNull();
});

// ─── Deduplication ──────────────────────────────────────────────────

it('does not create duplicate alert for same rule in same week', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Set up data triggering high acceptance rate (w=2: Jan 27, w=1: Feb 3)
    seedAcceptances($this->project, 20, 'accepted', Carbon::parse('2026-01-27 12:00:00'));
    seedAcceptances($this->project, 20, 'accepted', Carbon::parse('2026-02-03 12:00:00'));

    $service = new OverrelianceDetectionService;

    $alert1 = $service->evaluateHighAcceptanceRate($now);
    expect($alert1)->not->toBeNull();

    $alert2 = $service->evaluateHighAcceptanceRate($now);
    expect($alert2)->toBeNull();

    expect(OverrelianceAlert::where('rule', 'high_acceptance_rate')->count())->toBe(1);
});

// ─── evaluateAll ────────────────────────────────────────────────────

it('evaluateAll runs all 4 rules and returns created alerts', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Trigger high acceptance rate (w=2: Jan 27, w=1: Feb 3)
    seedAcceptances($this->project, 20, 'accepted', Carbon::parse('2026-01-27 12:00:00'));
    seedAcceptances($this->project, 20, 'accepted', Carbon::parse('2026-02-03 12:00:00'));

    $service = new OverrelianceDetectionService;
    $alerts = $service->evaluateAll($now);

    expect($alerts)->not->toBeEmpty();
    $rules = collect($alerts)->pluck('rule')->all();
    expect($rules)->toContain('high_acceptance_rate');
});

// ─── Helper ─────────────────────────────────────────────────────────

function seedAcceptances(
    Project $project,
    int $count,
    string $status,
    Carbon $createdAt,
    string $severity = 'major',
    bool $bulkResolved = false,
    int $emojiNegativeCount = 0,
): void {
    $task = Task::factory()->create(['project_id' => $project->id]);

    for ($i = 0; $i < $count; $i++) {
        DB::table('finding_acceptances')->insert([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'mr_iid' => 1,
            'finding_id' => (string) ($i + 1),
            'file' => 'src/example.php',
            'line' => $i + 1,
            'severity' => $severity,
            'title' => "Finding {$i}",
            'status' => $status,
            'bulk_resolved' => $bulkResolved,
            'emoji_negative_count' => $emojiNegativeCount,
            'emoji_positive_count' => 0,
            'emoji_sentiment' => $emojiNegativeCount > 0 ? 'negative' : 'neutral',
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $createdAt->toDateTimeString(),
        ]);
    }
}
