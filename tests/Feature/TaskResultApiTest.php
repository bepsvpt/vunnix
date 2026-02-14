<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Services\TaskTokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────

/**
 * Build a schema-valid code review result (passes CodeReviewSchema validation).
 */
function validSchemaResult(): array
{
    return [
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
    ];
}

function validResultPayload(array $overrides = []): array
{
    return array_merge([
        'status' => 'completed',
        'result' => validSchemaResult(),
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

function resultUrl(Task $task): string
{
    return "/api/v1/tasks/{$task->id}/result";
}

function generateToken(int $taskId): string
{
    return app(TaskTokenService::class)->generate($taskId);
}

// ─── Happy path: completed result ────────────────────────────────

it('accepts a completed result and transitions task to completed via Result Processor', function () {
    // Fake GitLab API — PostSummaryComment runs inline on sync queue
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1, 'body' => 'mocked'], 201),
    ]);

    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    // With sync queue, ProcessTaskResult runs inline → ResultProcessor validates → Completed
    $response = $this->postJson(resultUrl($task), validResultPayload(), [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'task_id' => $task->id,
            'task_status' => 'processing',
        ]);

    // ResultProcessor ran synchronously and transitioned to Completed
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed)
        ->and($task->result)->toBeArray()
        ->and($task->result['commit_status'])->toBe('success')
        ->and($task->tokens_used)->toBe(185000)
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->prompt_version)->toBe([
            'skill' => 'frontend-review:1.0',
            'claude_md' => 'executor:1.0',
            'schema' => 'review:1.0',
        ]);
});

it('dispatches ProcessTaskResult job for completed results', function () {
    Queue::fake();

    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    $this->postJson(resultUrl($task), validResultPayload(), [
        'Authorization' => "Bearer {$token}",
    ])->assertOk();

    Queue::assertPushed(\App\Jobs\ProcessTaskResult::class, function ($job) use ($task) {
        return $job->taskId === $task->id;
    });

    // Task stays in Running when queue is faked (RP hasn't run yet)
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Running);
});

// ─── Happy path: failed result ───────────────────────────────────

it('accepts a failed result and transitions task to failed immediately', function () {
    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    $response = $this->postJson(resultUrl($task), validResultPayload([
        'status' => 'failed',
        'result' => null,
        'error' => 'Claude API rate limit exceeded',
    ]), [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'task_id' => $task->id,
            'task_status' => 'failed',
        ]);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_reason)->toBe('Claude API rate limit exceeded')
        ->and($task->tokens_used)->toBe(185000);
});

it('does not dispatch ProcessTaskResult for failed results', function () {
    Queue::fake();

    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    $this->postJson(resultUrl($task), validResultPayload([
        'status' => 'failed',
        'result' => null,
        'error' => 'timeout',
    ]), [
        'Authorization' => "Bearer {$token}",
    ])->assertOk();

    Queue::assertNotPushed(\App\Jobs\ProcessTaskResult::class);
});

// ─── 401: Missing bearer token ───────────────────────────────────

it('returns 401 when bearer token is missing', function () {
    $task = Task::factory()->running()->create();

    $response = $this->postJson(resultUrl($task), validResultPayload());

    $response->assertUnauthorized()
        ->assertJson(['error' => 'Missing task token.']);
});

// ─── 401: Invalid bearer token ───────────────────────────────────

it('returns 401 when bearer token is invalid', function () {
    $task = Task::factory()->running()->create();

    $response = $this->postJson(resultUrl($task), validResultPayload(), [
        'Authorization' => 'Bearer not-a-valid-token',
    ]);

    $response->assertUnauthorized()
        ->assertJson(['error' => 'Invalid or expired task token.']);
});

// ─── 401: Token for wrong task ID (security — task scoping) ──────

it('returns 401 when token belongs to a different task', function () {
    $taskA = Task::factory()->running()->create();
    $taskB = Task::factory()->running()->create();

    // Generate token for task A, use it on task B
    $tokenForA = generateToken($taskA->id);

    $response = $this->postJson(resultUrl($taskB), validResultPayload(), [
        'Authorization' => "Bearer {$tokenForA}",
    ]);

    $response->assertUnauthorized()
        ->assertJson(['error' => 'Invalid or expired task token.']);
});

// ─── 401: Expired token ──────────────────────────────────────────

it('returns 401 when bearer token is expired', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 12, 0, 0));

    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    // Advance past the 60-minute budget
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 13, 1, 0));

    $response = $this->postJson(resultUrl($task), validResultPayload(), [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertUnauthorized()
        ->assertJson(['error' => 'Invalid or expired task token.']);

    Carbon::setTestNow();
});

// ─── 404: Non-existent task ──────────────────────────────────────

it('returns 404 when task does not exist', function () {
    $token = generateToken(99999);

    $response = $this->postJson('/api/v1/tasks/99999/result', validResultPayload(), [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertNotFound();
});

// ─── 409: Task not in running state ──────────────────────────────

it('returns 409 when task is already completed', function () {
    $task = Task::factory()->completed()->create();
    $token = generateToken($task->id);

    $response = $this->postJson(resultUrl($task), validResultPayload(), [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertStatus(409)
        ->assertJsonFragment(['error' => 'Task is not in running state (current: completed).']);
});

it('returns 409 when task is in queued state', function () {
    $task = Task::factory()->queued()->create();
    $token = generateToken($task->id);

    $response = $this->postJson(resultUrl($task), validResultPayload(), [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertStatus(409)
        ->assertJsonFragment(['error' => 'Task is not in running state (current: queued).']);
});

// ─── 422: Validation errors ──────────────────────────────────────

it('returns 422 when status field is missing', function () {
    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    $payload = validResultPayload();
    unset($payload['status']);

    $response = $this->postJson(resultUrl($task), $payload, [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('returns 422 when status is not a valid value', function () {
    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    $response = $this->postJson(resultUrl($task), validResultPayload([
        'status' => 'invalid',
    ]), [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('returns 422 when tokens are missing', function () {
    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    $payload = validResultPayload();
    unset($payload['tokens']);

    $response = $this->postJson(resultUrl($task), $payload, [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['tokens']);
});

it('returns 422 when result is missing for completed status', function () {
    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    $response = $this->postJson(resultUrl($task), validResultPayload([
        'status' => 'completed',
        'result' => null,
    ]), [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['result']);
});

it('returns 422 when prompt_version is missing', function () {
    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    $payload = validResultPayload();
    unset($payload['prompt_version']);

    $response = $this->postJson(resultUrl($task), $payload, [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt_version']);
});

it('returns 422 when duration_seconds is missing', function () {
    $task = Task::factory()->running()->create();
    $token = generateToken($task->id);

    $payload = validResultPayload();
    unset($payload['duration_seconds']);

    $response = $this->postJson(resultUrl($task), $payload, [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['duration_seconds']);
});

// ─── Security: Token scoping (D127 — per M2 verification) ───────

it('prevents cross-task token reuse — token for task A cannot access task B', function () {
    Queue::fake();

    $taskA = Task::factory()->running()->create();
    $taskB = Task::factory()->running()->create();

    $tokenA = generateToken($taskA->id);
    $tokenB = generateToken($taskB->id);

    // Token A on task A — should work
    $this->postJson(resultUrl($taskA), validResultPayload(), [
        'Authorization' => "Bearer {$tokenA}",
    ])->assertOk();

    // Token A on task B — must fail
    $this->postJson(resultUrl($taskB), validResultPayload(), [
        'Authorization' => "Bearer {$tokenA}",
    ])->assertUnauthorized();

    // Token B on task B — should work
    $this->postJson(resultUrl($taskB), validResultPayload(), [
        'Authorization' => "Bearer {$tokenB}",
    ])->assertOk();
});
