<?php

use App\Events\TaskStatusChanged;
use App\Models\Task;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('TaskStatusChanged broadcasts on private task channel', function (): void {
    $task = Task::factory()->create(['status' => 'completed']);

    $event = new TaskStatusChanged($task);

    expect($event->broadcastOn())
        ->toBeArray()
        ->toHaveCount(2);

    $channels = $event->broadcastOn();
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe("private-task.{$task->id}");
    expect($channels[1])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[1]->name)->toBe("private-project.{$task->project_id}.activity");
});

test('TaskStatusChanged payload includes status and task summary', function (): void {
    $task = Task::factory()->create([
        'status' => 'completed',
        'type' => 'code_review',
        'pipeline_id' => 12345,
    ]);

    $event = new TaskStatusChanged($task);
    $data = $event->broadcastWith();

    expect($data)->toHaveKeys(['task_id', 'status', 'type', 'project_id', 'pipeline_id', 'timestamp']);
    expect($data['task_id'])->toBe($task->id);
    expect($data['status'])->toBe('completed');
    expect($data['type'])->toBe('code_review');
    expect($data['project_id'])->toBe($task->project_id);
    expect($data['pipeline_id'])->toBe(12345);
});

test('TaskStatusChanged event name is task.status.changed', function (): void {
    $task = Task::factory()->create();

    $event = new TaskStatusChanged($task);

    expect($event->broadcastAs())->toBe('task.status.changed');
});

// -- T70: Result card data in broadcast payload --

test('includes result_data in broadcast payload for completed feature_dev task', function (): void {
    $task = Task::factory()->create([
        'type' => 'feature_dev',
        'status' => 'completed',
        'mr_iid' => 123,
        'issue_iid' => null,
        'result' => [
            'branch' => 'ai/payment-feature',
            'mr_title' => 'Add payment flow',
            'mr_description' => 'Implements Stripe checkout',
            'files_changed' => [
                ['path' => 'app/Payment.php', 'action' => 'created', 'summary' => 'Payment model'],
            ],
            'tests_added' => true,
            'notes' => 'Added tests',
        ],
    ]);

    $event = new TaskStatusChanged($task);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('result_data');
    expect($payload['result_data'])->toHaveKey('branch', 'ai/payment-feature');
    expect($payload['result_data'])->toHaveKey('target_branch');
    expect($payload['result_data']['files_changed'])->toHaveCount(1);
    expect($payload['result_data'])->not->toHaveKey('screenshot');
});

test('includes screenshot in broadcast payload for completed ui_adjustment task', function (): void {
    $task = Task::factory()->create([
        'type' => 'ui_adjustment',
        'status' => 'completed',
        'mr_iid' => 456,
        'result' => [
            'branch' => 'ai/fix-padding',
            'mr_title' => 'Fix card padding',
            'mr_description' => 'Fixes padding on cards',
            'files_changed' => [
                ['path' => 'src/Card.vue', 'action' => 'modified', 'summary' => 'Fixed padding'],
            ],
            'tests_added' => false,
            'notes' => 'Visual fix',
            'screenshot' => 'iVBORw0KGgoAAAANSUhEUg==',
            'screenshot_mobile' => null,
        ],
    ]);

    $event = new TaskStatusChanged($task);
    $payload = $event->broadcastWith();

    expect($payload['result_data'])->toHaveKey('screenshot', 'iVBORw0KGgoAAAANSUhEUg==');
});

test('includes error_reason in broadcast payload for failed task', function (): void {
    $task = Task::factory()->create([
        'type' => 'feature_dev',
        'status' => 'failed',
        'error_reason' => 'Schema validation failed',
        'result' => null,
    ]);

    $event = new TaskStatusChanged($task);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('error_reason', 'Schema validation failed');
});

test('omits result_data for non-terminal tasks', function (): void {
    $task = Task::factory()->create([
        'type' => 'feature_dev',
        'status' => 'running',
        'started_at' => now(),
        'result' => ['branch' => 'ai/something'],
    ]);

    $event = new TaskStatusChanged($task);
    $payload = $event->broadcastWith();

    expect($payload)->not->toHaveKey('result_data');
});
