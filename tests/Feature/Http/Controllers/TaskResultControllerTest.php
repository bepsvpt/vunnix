<?php

use App\Enums\TaskStatus;
use App\Exceptions\InvalidTaskTransitionException;
use App\Jobs\ProcessTaskResult;
use App\Models\Task;
use App\Services\TaskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createTaskResultRunningTaskWithToken(array $overrides = []): array
{
    $task = Task::factory()->running()->create($overrides);
    $tokenService = app(TaskTokenService::class);
    $token = $tokenService->generate($task->id);

    return [$task, $token];
}

it('accepts a completed result for a running task', function (): void {
    Queue::fake([ProcessTaskResult::class]);

    [$task, $token] = createTaskResultRunningTaskWithToken();

    $response = $this->postJson("/api/v1/tasks/{$task->id}/result", [
        'status' => 'completed',
        'result' => ['summary' => 'All good'],
        'tokens' => ['input' => 100, 'output' => 200, 'thinking' => 50],
        'duration_seconds' => 30,
        'prompt_version' => ['skill' => 'code-review', 'claude_md' => '1.0', 'schema' => '1.0'],
    ], ['Authorization' => "Bearer {$token}"]);

    $response->assertOk();
    $response->assertJsonPath('status', 'accepted');
    $response->assertJsonPath('task_status', 'processing');

    Queue::assertPushed(ProcessTaskResult::class);
});

it('returns 409 when task is not in running state', function (): void {
    $task = Task::factory()->completed()->create();
    $tokenService = app(TaskTokenService::class);
    $token = $tokenService->generate($task->id);

    $response = $this->postJson("/api/v1/tasks/{$task->id}/result", [
        'status' => 'completed',
        'result' => ['summary' => 'Late result'],
        'tokens' => ['input' => 100, 'output' => 200, 'thinking' => 50],
        'duration_seconds' => 30,
        'prompt_version' => ['skill' => 'code-review', 'claude_md' => '1.0', 'schema' => '1.0'],
    ], ['Authorization' => "Bearer {$token}"]);

    $response->assertStatus(409);
    $response->assertJsonPath('error', "Task is not in running state (current: {$task->status->value}).");
});

it('accepts a failed result and transitions task to failed', function (): void {
    [$task, $token] = createTaskResultRunningTaskWithToken();

    $response = $this->postJson("/api/v1/tasks/{$task->id}/result", [
        'status' => 'failed',
        'error' => 'Pipeline timeout',
        'tokens' => ['input' => 50, 'output' => 10, 'thinking' => 0],
        'duration_seconds' => 120,
        'prompt_version' => ['skill' => 'code-review', 'claude_md' => '1.0', 'schema' => '1.0'],
    ], ['Authorization' => "Bearer {$token}"]);

    $response->assertOk();
    $response->assertJsonPath('status', 'accepted');
    $response->assertJsonPath('task_status', 'failed');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_reason)->toBe('Pipeline timeout');
});

it('returns 409 when transitionTo throws InvalidTaskTransitionException', function (): void {
    // This tests lines 96-105: the catch block for InvalidTaskTransitionException.
    // To trigger this, we call the controller directly with a mock task that
    // throws on transitionTo (simulating a race condition where the task's
    // state changed between the initial check and the transition attempt).

    Log::shouldReceive('error')
        ->once()
        ->with('Task result transition failed', Mockery::on(fn (array $ctx): bool => $ctx['from'] === 'completed'
            && $ctx['to'] === 'failed'));

    // Allow other log calls (the warning log from guard check won't fire
    // since our mock task reports Running status)
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    $controller = new \App\Http\Controllers\TaskResultController;

    $request = Mockery::mock(\App\Http\Requests\StoreTaskResultRequest::class);
    $request->shouldReceive('validated')->andReturn([
        'status' => 'failed',
        'error' => 'Executor crash',
        'tokens' => ['input' => 10, 'output' => 5, 'thinking' => 0],
        'duration_seconds' => 5,
        'prompt_version' => ['skill' => 'test', 'claude_md' => '1.0', 'schema' => '1.0'],
        'result' => null,
    ]);

    // Create a partial mock task that behaves like a running task
    // but throws InvalidTaskTransitionException on transitionTo
    $task = Task::factory()->running()->create();
    $taskMock = Mockery::mock($task)->makePartial();
    $taskMock->shouldReceive('transitionTo')
        ->with(TaskStatus::Failed, 'Executor crash')
        ->once()
        ->andThrow(new InvalidTaskTransitionException(
            TaskStatus::Completed,
            TaskStatus::Failed,
        ));

    $result = $controller->__invoke($request, $taskMock);

    expect($result->getStatusCode())->toBe(409);
    expect($result->getData(true))->toBe(['error' => 'Task state transition failed.']);
});
