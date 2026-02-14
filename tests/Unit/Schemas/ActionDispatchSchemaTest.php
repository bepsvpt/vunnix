<?php

uses(Tests\TestCase::class);

use App\Schemas\ActionDispatchSchema;

/**
 * Helper: build a valid action dispatch data array with optional overrides.
 */
function validActionDispatch(array $overrides = []): array
{
    $base = [
        'action_type' => 'implement_feature',
        'project_id' => 42,
        'title' => 'Add Stripe payment flow',
        'description' => 'Implement the Stripe payment integration with checkout page, webhook handling, and subscription management.',
        'branch_name' => 'ai/payment-feature',
        'target_branch' => 'main',
        'assignee_id' => 7,
        'labels' => ['feature', 'ai::created'],
    ];

    return array_replace_recursive($base, $overrides);
}

// ─── Valid data passes ──────────────────────────────────────────

it('validates a complete valid action dispatch', function () {
    $result = ActionDispatchSchema::validate(validActionDispatch());

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

it('validates all action types', function (string $actionType) {
    $data = validActionDispatch(['action_type' => $actionType]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeTrue();
})->with(['create_issue', 'implement_feature', 'ui_adjustment', 'create_mr', 'deep_analysis']);

it('validates with empty labels array', function () {
    $data = validActionDispatch(['labels' => []]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates with nullable optional fields', function () {
    $data = validActionDispatch([
        'branch_name' => null,
        'target_branch' => null,
        'assignee_id' => null,
    ]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates create_issue without branch fields', function () {
    $data = validActionDispatch([
        'action_type' => 'create_issue',
        'branch_name' => null,
        'target_branch' => null,
        'assignee_id' => 12,
    ]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates deep_analysis with minimal fields', function () {
    $data = validActionDispatch([
        'action_type' => 'deep_analysis',
        'branch_name' => null,
        'target_branch' => null,
        'assignee_id' => null,
        'labels' => [],
    ]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

// ─── Missing required fields fail ───────────────────────────────

it('fails when action_type is missing', function () {
    $data = validActionDispatch();
    unset($data['action_type']);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('action_type');
});

it('fails when project_id is missing', function () {
    $data = validActionDispatch();
    unset($data['project_id']);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('project_id');
});

it('fails when title is missing', function () {
    $data = validActionDispatch();
    unset($data['title']);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('title');
});

it('fails when description is missing', function () {
    $data = validActionDispatch();
    unset($data['description']);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('description');
});

it('fails when labels is missing', function () {
    $data = validActionDispatch();
    unset($data['labels']);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('labels');
});

// ─── Invalid values fail ────────────────────────────────────────

it('fails when action_type has an invalid value', function () {
    $data = validActionDispatch(['action_type' => 'delete_repo']);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('action_type');
});

it('fails when project_id is zero', function () {
    $data = validActionDispatch(['project_id' => 0]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('project_id');
});

it('fails when project_id is negative', function () {
    $data = validActionDispatch(['project_id' => -5]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('project_id');
});

it('fails when title exceeds max length', function () {
    $data = validActionDispatch(['title' => str_repeat('a', 501)]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('title');
});

it('passes when title is exactly at max length', function () {
    $data = validActionDispatch(['title' => str_repeat('a', 500)]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('fails when branch_name exceeds max length', function () {
    $data = validActionDispatch(['branch_name' => str_repeat('a', 256)]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('branch_name');
});

it('fails when target_branch exceeds max length', function () {
    $data = validActionDispatch(['target_branch' => str_repeat('a', 256)]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('target_branch');
});

it('fails when assignee_id is zero', function () {
    $data = validActionDispatch(['assignee_id' => 0]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('assignee_id');
});

it('fails when labels contains a non-string value', function () {
    $data = validActionDispatch(['labels' => ['valid-label', 123]]);
    $result = ActionDispatchSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('labels.1');
});

// ─── Extra fields stripped ──────────────────────────────────────

it('strips unknown top-level fields', function () {
    $data = validActionDispatch();
    $data['unknown_field'] = 'should be removed';
    $data['conversation_id'] = 'abc-123';
    $data['user_id'] = 99;
    $stripped = ActionDispatchSchema::strip($data);

    expect($stripped)->not->toHaveKey('unknown_field');
    expect($stripped)->not->toHaveKey('conversation_id');
    expect($stripped)->not->toHaveKey('user_id');
    expect($stripped)->toHaveKeys([
        'action_type', 'project_id', 'title', 'description',
        'branch_name', 'target_branch', 'assignee_id', 'labels',
    ]);
});

it('preserves valid data through strip', function () {
    $data = validActionDispatch();
    $stripped = ActionDispatchSchema::strip($data);

    expect($stripped)->toEqual($data);
});

it('strips only extra fields, preserves all schema fields', function () {
    $data = validActionDispatch();
    $data['extra'] = 'junk';
    $stripped = ActionDispatchSchema::strip($data);

    $expected = validActionDispatch();
    expect($stripped)->toEqual($expected);
});

// ─── validateAndStrip ───────────────────────────────────────────

it('returns stripped data when valid via validateAndStrip', function () {
    $data = validActionDispatch();
    $data['extra'] = 'junk';
    $result = ActionDispatchSchema::validateAndStrip($data);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($result['data'])->not->toHaveKey('extra');
    expect($result['data'])->toHaveKeys([
        'action_type', 'project_id', 'title', 'description',
        'branch_name', 'target_branch', 'assignee_id', 'labels',
    ]);
});

it('returns null data when invalid via validateAndStrip', function () {
    $data = validActionDispatch();
    unset($data['action_type']);
    $result = ActionDispatchSchema::validateAndStrip($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
    expect($result['data'])->toBeNull();
});

// ─── Constants ──────────────────────────────────────────────────

it('exposes schema version constant', function () {
    expect(ActionDispatchSchema::VERSION)->toBe('1.0');
});

it('exposes action types constant', function () {
    expect(ActionDispatchSchema::ACTION_TYPES)->toBe([
        'create_issue',
        'implement_feature',
        'ui_adjustment',
        'create_mr',
        'deep_analysis',
    ]);
});

it('action types match DispatchAction tool mapping', function () {
    $schemaTypes = ActionDispatchSchema::ACTION_TYPES;
    $toolTypes = array_keys(\App\Agents\Tools\DispatchAction::ACTION_TYPE_MAP);

    // Schema must cover all types the tool accepts
    foreach ($toolTypes as $type) {
        expect($schemaTypes)->toContain($type);
    }
});

it('returns rules as an array', function () {
    $rules = ActionDispatchSchema::rules();

    expect($rules)->toBeArray();
    expect($rules)->toHaveKey('action_type');
    expect($rules)->toHaveKey('project_id');
    expect($rules)->toHaveKey('title');
    expect($rules)->toHaveKey('description');
    expect($rules)->toHaveKey('labels');
});
