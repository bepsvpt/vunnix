<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\GlobalSetting;
use App\Models\Task;
use App\Models\TaskMetric;
use App\Services\TaskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────

function costResultUrl(Task $task): string
{
    return "/api/v1/tasks/{$task->id}/result";
}

function costGenerateToken(int $taskId): string
{
    return app(TaskTokenService::class)->generate($taskId);
}

function costResultPayload(array $overrides = []): array
{
    return array_merge([
        'status' => 'completed',
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
        'tokens' => [
            'input' => 150000,
            'output' => 30000,
            'thinking' => 5000,
        ],
        'duration_seconds' => 180,
        'prompt_version' => [
            'skill' => 'frontend-review:1.0',
            'claude_md' => 'executor:1.0',
            'schema' => 'review:1.0',
        ],
    ], $overrides);
}

// ─── Cost calculation on result submission ───────────────────────

test('calculates cost from tokens using default prices when result is submitted', function () {
    Queue::fake();

    $task = Task::factory()->running()->create();
    $token = costGenerateToken($task->id);

    $this->postJson(costResultUrl($task), costResultPayload([
        'tokens' => ['input' => 150000, 'output' => 30000, 'thinking' => 5000],
    ]), [
        'Authorization' => "Bearer {$token}",
    ])->assertOk();

    $task->refresh();

    // (150000 × $5/MTok) + (30000 × $25/MTok) = $0.75 + $0.75 = $1.50
    expect((float) $task->cost)->toBe(1.5);
});

test('calculates cost for failed result submission', function () {
    $task = Task::factory()->running()->create();
    $token = costGenerateToken($task->id);

    $this->postJson(costResultUrl($task), costResultPayload([
        'status' => 'failed',
        'result' => null,
        'error' => 'timeout',
        'tokens' => ['input' => 80000, 'output' => 15000, 'thinking' => 2000],
    ]), [
        'Authorization' => "Bearer {$token}",
    ])->assertOk();

    $task->refresh();

    // (80000 × $5/MTok) + (15000 × $25/MTok) = $0.40 + $0.375 = $0.775
    expect((float) $task->cost)->toBe(0.775);
});

// ─── Cost flows to TaskMetric via observer ───────────────────────

test('cost propagates from task to task_metrics via observer', function () {
    Event::fake([\App\Events\TaskStatusChanged::class]);

    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'type' => TaskType::CodeReview,
        'input_tokens' => 150000,
        'output_tokens' => 30000,
        'cost' => 1.5,
        'duration_seconds' => 120,
        'started_at' => now()->subMinutes(2),
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [],
            ],
            'findings' => [],
            'labels' => [],
            'commit_status' => 'success',
        ],
    ]);

    $task->transitionTo(TaskStatus::Completed);

    $metric = TaskMetric::where('task_id', $task->id)->first();

    expect($metric)->not->toBeNull()
        ->and((float) $metric->cost)->toBe(1.5);
});

// ─── Custom prices from GlobalSetting ────────────────────────────

test('uses custom prices from GlobalSetting when configured', function () {
    Queue::fake();

    // Set custom prices (e.g., a cheaper model)
    GlobalSetting::set('ai_prices', ['input' => 3.0, 'output' => 15.0], 'json', 'AI pricing per MTok');

    $task = Task::factory()->running()->create();
    $token = costGenerateToken($task->id);

    $this->postJson(costResultUrl($task), costResultPayload([
        'tokens' => ['input' => 150000, 'output' => 30000, 'thinking' => 5000],
    ]), [
        'Authorization' => "Bearer {$token}",
    ])->assertOk();

    $task->refresh();

    // (150000 × $3/MTok) + (30000 × $15/MTok) = $0.45 + $0.45 = $0.90
    expect((float) $task->cost)->toBe(0.9);
});

// ─── Zero/null tokens ────────────────────────────────────────────

test('calculates zero cost when tokens are zero', function () {
    Queue::fake();

    $task = Task::factory()->running()->create();
    $token = costGenerateToken($task->id);

    $this->postJson(costResultUrl($task), costResultPayload([
        'tokens' => ['input' => 0, 'output' => 0, 'thinking' => 0],
    ]), [
        'Authorization' => "Bearer {$token}",
    ])->assertOk();

    $task->refresh();

    expect((float) $task->cost)->toBe(0.0);
});
