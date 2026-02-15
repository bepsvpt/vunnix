<?php

use App\Agents\Tools\DispatchAction;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccessChecker;
use App\Services\TaskDispatcher;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->taskDispatcher = Mockery::mock(TaskDispatcher::class);
    $this->tool = new DispatchAction($this->accessChecker, $this->taskDispatcher);
});

afterEach(fn () => Mockery::close());

// ─── Description ────────────────────────────────────────────────

it('has a description', function () {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines the expected schema parameters', function () {
    $schema = new JsonSchemaTypeFactory;
    $params = $this->tool->schema($schema);

    expect($params)->toHaveKeys([
        'action_type',
        'project_id',
        'title',
        'description',
        'branch_name',
        'target_branch',
        'assignee_id',
        'labels',
        'user_id',
        'conversation_id',
    ]);
});

// ─── Handle — access denied ────────────────────────────────────

it('returns rejection when access checker denies access', function () {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturn('Access denied: project not registered.');

    $request = new Request([
        'action_type' => 'implement_feature',
        'project_id' => 42,
        'title' => 'Add payment',
        'description' => 'Implement Stripe payments',
        'user_id' => 1,
        'conversation_id' => 'conv-abc',
    ]);

    $result = $this->tool->handle($request);

    expect($result)->toContain('Access denied');
    $this->taskDispatcher->shouldNotHaveReceived('dispatch');
});

// ─── Handle — invalid action type ──────────────────────────────

it('returns error for invalid action type', function () {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturnNull();

    $request = new Request([
        'action_type' => 'invalid_type',
        'project_id' => 42,
        'title' => 'Test',
        'description' => 'Test desc',
        'user_id' => 1,
        'conversation_id' => 'conv-abc',
    ]);

    $result = $this->tool->handle($request);

    expect($result)->toContain('Invalid action type');
    $this->taskDispatcher->shouldNotHaveReceived('dispatch');
});

// ─── Action type mapping ───────────────────────────────────────

it('includes existing_mr_iid in schema parameters', function () {
    $schema = new JsonSchemaTypeFactory;
    $params = $this->tool->schema($schema);

    expect($params)->toHaveKey('existing_mr_iid');
});

// ─── Action type mapping ───────────────────────────────────────

it('maps action types to TaskType enum correctly', function () {
    $mapping = DispatchAction::ACTION_TYPE_MAP;

    expect($mapping)->toHaveKeys([
        'create_issue',
        'implement_feature',
        'ui_adjustment',
        'create_mr',
        'deep_analysis',
    ]);

    expect($mapping['create_issue'])->toBe(TaskType::PrdCreation);
    expect($mapping['implement_feature'])->toBe(TaskType::FeatureDev);
    expect($mapping['ui_adjustment'])->toBe(TaskType::UiAdjustment);
    expect($mapping['create_mr'])->toBe(TaskType::FeatureDev);
    expect($mapping['deep_analysis'])->toBe(TaskType::DeepAnalysis);
});
