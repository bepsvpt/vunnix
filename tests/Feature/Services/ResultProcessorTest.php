<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Services\ResultProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────

function validCodeReview(): array
{
    return [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'medium',
            'total_findings' => 2,
            'findings_by_severity' => ['critical' => 0, 'major' => 1, 'minor' => 1],
            'walkthrough' => [
                ['file' => 'src/auth.py', 'change_summary' => 'Added OAuth2 token refresh logic'],
            ],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'major',
                'category' => 'security',
                'file' => 'src/auth.py',
                'line' => 18,
                'end_line' => 22,
                'title' => 'Token not validated before use',
                'description' => 'The OAuth token is used without checking expiration.',
                'suggestion' => '```diff\n-  use_token(token)\n+  if token.is_valid():\n+      use_token(token)\n```',
                'labels' => [],
            ],
            [
                'id' => 2,
                'severity' => 'minor',
                'category' => 'style',
                'file' => 'src/utils.py',
                'line' => 5,
                'end_line' => 5,
                'title' => 'Unused import',
                'description' => 'The os module is imported but never used.',
                'suggestion' => '```diff\n-  import os\n```',
                'labels' => [],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-medium'],
        'commit_status' => 'success',
    ];
}

function validFeatureDev(): array
{
    return [
        'version' => '1.0',
        'branch' => 'ai/payment-feature',
        'mr_title' => 'Add Stripe payment flow',
        'mr_description' => 'Implements the Stripe payment integration.',
        'files_changed' => [
            ['path' => 'src/PaymentController.php', 'action' => 'created', 'summary' => 'New controller'],
        ],
        'tests_added' => true,
        'notes' => 'Used existing PaymentGateway interface.',
    ];
}

function validUiAdjustment(): array
{
    return array_merge(validFeatureDev(), [
        'branch' => 'ai/fix-button-padding',
        'mr_title' => 'Fix button padding',
        'screenshot' => 'iVBORw0KGgoAAAANS',
        'screenshot_mobile' => null,
    ]);
}

function makeRunningTask(TaskType $type): Task
{
    return Task::factory()->running()->create(['type' => $type]);
}

// ─── Code review: valid result ─────────────────────────────────

it('processes a valid code review result and transitions to Completed', function () {
    $task = makeRunningTask(TaskType::CodeReview);
    $task->result = validCodeReview();
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBeArray()
        ->and($result['data']['summary']['risk_level'])->toBe('medium')
        ->and($result['data']['findings'])->toHaveCount(2)
        ->and($result['data']['commit_status'])->toBe('success')
        ->and($result['errors'])->toBeEmpty();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed)
        ->and($task->completed_at)->not->toBeNull();
});

it('strips extra fields from valid code review result', function () {
    $task = makeRunningTask(TaskType::CodeReview);
    $resultWithExtras = validCodeReview();
    $resultWithExtras['extra_field'] = 'should be stripped';
    $resultWithExtras['findings'][0]['injected'] = 'malicious';
    $task->result = $resultWithExtras;
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->not->toHaveKey('extra_field');

    $task->refresh();
    expect($task->result)->not->toHaveKey('extra_field')
        ->and($task->result['findings'][0])->not->toHaveKey('injected');
});

// ─── Code review: invalid result ───────────────────────────────

it('fails with invalid code review result and transitions to Failed', function () {
    $task = makeRunningTask(TaskType::CodeReview);
    $task->result = ['summary' => 'just a string, not valid schema'];
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeFalse()
        ->and($result['data'])->toBeNull()
        ->and($result['errors'])->not->toBeEmpty();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_reason)->toContain('Schema validation failed');
});

it('fails when code review is missing required summary fields', function () {
    $task = makeRunningTask(TaskType::CodeReview);
    $invalid = validCodeReview();
    unset($invalid['summary']['risk_level']);
    $task->result = $invalid;
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeFalse();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_reason)->toContain('summary.risk_level');
});

// ─── Security audit: uses CodeReviewSchema ──────────────────────

it('processes a security audit using CodeReviewSchema', function () {
    $task = makeRunningTask(TaskType::SecurityAudit);
    $task->result = validCodeReview();
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['summary']['risk_level'])->toBe('medium');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
});

// ─── Feature dev: valid result ──────────────────────────────────

it('processes a valid feature dev result and transitions to Completed', function () {
    $task = makeRunningTask(TaskType::FeatureDev);
    $task->result = validFeatureDev();
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['branch'])->toBe('ai/payment-feature')
        ->and($result['data']['mr_title'])->toBe('Add Stripe payment flow')
        ->and($result['data']['files_changed'])->toHaveCount(1);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
});

// ─── Feature dev: invalid result ────────────────────────────────

it('fails when feature dev result is missing branch', function () {
    $task = makeRunningTask(TaskType::FeatureDev);
    $invalid = validFeatureDev();
    unset($invalid['branch']);
    $task->result = $invalid;
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeFalse();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_reason)->toContain('branch');
});

// ─── UI adjustment: valid result ────────────────────────────────

it('processes a valid UI adjustment result and transitions to Completed', function () {
    $task = makeRunningTask(TaskType::UiAdjustment);
    $task->result = validUiAdjustment();
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['screenshot'])->toBe('iVBORw0KGgoAAAANS')
        ->and($result['data']['screenshot_mobile'])->toBeNull();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
});

// ─── Issue discussion: no schema (passthrough) ──────────────────

it('passes through issue discussion results without schema validation', function () {
    $task = makeRunningTask(TaskType::IssueDiscussion);
    $freeformResult = [
        'response' => 'Here is my analysis of the issue...',
        'citations' => ['file.py:42'],
    ];
    $task->result = $freeformResult;
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe($freeformResult);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
});

// ─── PRD creation: no schema (passthrough) ──────────────────────

it('passes through PRD creation results without schema validation', function () {
    $task = makeRunningTask(TaskType::PrdCreation);
    $prdResult = [
        'title' => 'Payment Feature PRD',
        'body' => '## Overview\nA payment feature...',
    ];
    $task->result = $prdResult;
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe($prdResult);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
});

// ─── Null result ────────────────────────────────────────────────

it('fails when result is null', function () {
    $task = makeRunningTask(TaskType::CodeReview);
    $task->result = null;
    $task->save();

    $processor = app(ResultProcessor::class);
    $result = $processor->process($task);

    expect($result['success'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_reason)->toContain('Result payload is null');
});

// ─── Schema routing ─────────────────────────────────────────────

it('maps task types to correct schemas', function () {
    $processor = app(ResultProcessor::class);

    expect($processor->schemaFor(TaskType::CodeReview))->toBe(\App\Schemas\CodeReviewSchema::class)
        ->and($processor->schemaFor(TaskType::SecurityAudit))->toBe(\App\Schemas\CodeReviewSchema::class)
        ->and($processor->schemaFor(TaskType::FeatureDev))->toBe(\App\Schemas\FeatureDevSchema::class)
        ->and($processor->schemaFor(TaskType::UiAdjustment))->toBe(\App\Schemas\UiAdjustmentSchema::class)
        ->and($processor->schemaFor(TaskType::IssueDiscussion))->toBeNull()
        ->and($processor->schemaFor(TaskType::PrdCreation))->toBeNull();
});

// ─── Sanitized result stored on task ────────────────────────────

it('stores the sanitized result back on the task after validation', function () {
    $task = makeRunningTask(TaskType::CodeReview);
    $resultWithExtras = validCodeReview();
    $resultWithExtras['injected_field'] = 'should not persist';
    $task->result = $resultWithExtras;
    $task->save();

    $processor = app(ResultProcessor::class);
    $processor->process($task);

    $task->refresh();
    expect($task->result)->not->toHaveKey('injected_field')
        ->and($task->result)->toHaveKeys(['version', 'summary', 'findings', 'labels', 'commit_status']);
});
