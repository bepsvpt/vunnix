<?php

uses(Tests\TestCase::class);

use App\Schemas\UiAdjustmentSchema;

/**
 * Helper: build a valid UI adjustment result with optional overrides.
 */
function validUiAdjustmentResult(array $overrides = []): array
{
    $base = [
        'version' => '1.0',
        'branch' => 'ai/fix-card-padding',
        'mr_title' => 'Fix card padding on dashboard',
        'mr_description' => 'Increases card padding from 16px to 24px using --spacing-lg token.',
        'files_changed' => [
            [
                'path' => 'src/components/DashboardCard.vue',
                'action' => 'modified',
                'summary' => 'Updated padding to use --spacing-lg token',
            ],
        ],
        'tests_added' => false,
        'screenshot' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        'screenshot_mobile' => null,
        'notes' => 'Used existing --spacing-lg token (24px) instead of hardcoded value.',
    ];

    return array_replace_recursive($base, $overrides);
}

// ─── Valid data passes ──────────────────────────────────────────

it('validates a complete valid UI adjustment result', function () {
    $result = UiAdjustmentSchema::validate(validUiAdjustmentResult());

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

it('validates when screenshot is null (capture failed)', function () {
    $data = validUiAdjustmentResult(['screenshot' => null]);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates when screenshot_mobile is a base64 string', function () {
    $data = validUiAdjustmentResult([
        'screenshot_mobile' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAA',
    ]);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates when both screenshots are null', function () {
    $data = validUiAdjustmentResult([
        'screenshot' => null,
        'screenshot_mobile' => null,
    ]);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates when both screenshots are base64 strings', function () {
    $data = validUiAdjustmentResult([
        'screenshot' => 'iVBORw0KGgoAAAANSUhEUgAAAAE=',
        'screenshot_mobile' => 'iVBORw0KGgoAAAANSUhEUgAAAAE=',
    ]);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates both file action values', function (string $action) {
    $data = validUiAdjustmentResult();
    $data['files_changed'][0]['action'] = $action;
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeTrue();
})->with(['created', 'modified']);

// ─── Missing required fields fail ───────────────────────────────

it('fails when screenshot field is missing entirely', function () {
    $data = validUiAdjustmentResult();
    unset($data['screenshot']);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('screenshot');
});

it('fails when screenshot_mobile field is missing entirely', function () {
    $data = validUiAdjustmentResult();
    unset($data['screenshot_mobile']);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('screenshot_mobile');
});

it('fails when branch is missing', function () {
    $data = validUiAdjustmentResult();
    unset($data['branch']);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('branch');
});

it('fails when mr_title is missing', function () {
    $data = validUiAdjustmentResult();
    unset($data['mr_title']);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('mr_title');
});

it('fails when files_changed is missing', function () {
    $data = validUiAdjustmentResult();
    unset($data['files_changed']);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('files_changed');
});

it('fails when notes is missing', function () {
    $data = validUiAdjustmentResult();
    unset($data['notes']);
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('notes');
});

// ─── Invalid values fail ────────────────────────────────────────

it('fails when file action has an invalid value', function () {
    $data = validUiAdjustmentResult();
    $data['files_changed'][0]['action'] = 'deleted';
    $result = UiAdjustmentSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('files_changed.0.action');
});

// ─── Extra fields stripped ──────────────────────────────────────

it('strips unknown top-level fields', function () {
    $data = validUiAdjustmentResult();
    $data['unknown_field'] = 'should be removed';
    $data['ai_reasoning'] = 'internal';
    $stripped = UiAdjustmentSchema::strip($data);

    expect($stripped)->not->toHaveKey('unknown_field');
    expect($stripped)->not->toHaveKey('ai_reasoning');
    expect($stripped)->toHaveKeys([
        'version', 'branch', 'mr_title', 'mr_description',
        'files_changed', 'tests_added', 'screenshot', 'screenshot_mobile', 'notes',
    ]);
});

it('strips unknown fields from files_changed entries', function () {
    $data = validUiAdjustmentResult();
    $data['files_changed'][0]['diff'] = '+some code';
    $data['files_changed'][0]['lines_added'] = 42;
    $stripped = UiAdjustmentSchema::strip($data);

    expect($stripped['files_changed'][0])->not->toHaveKey('diff');
    expect($stripped['files_changed'][0])->not->toHaveKey('lines_added');
    expect($stripped['files_changed'][0])->toHaveKeys(['path', 'action', 'summary']);
});

it('preserves screenshot fields through strip', function () {
    $data = validUiAdjustmentResult();
    $stripped = UiAdjustmentSchema::strip($data);

    expect($stripped['screenshot'])->toBe($data['screenshot']);
    expect($stripped['screenshot_mobile'])->toBeNull();
});

it('preserves valid data through strip', function () {
    $data = validUiAdjustmentResult();
    $stripped = UiAdjustmentSchema::strip($data);

    expect($stripped)->toEqual($data);
});

// ─── validateAndStrip ───────────────────────────────────────────

it('returns stripped data when valid via validateAndStrip', function () {
    $data = validUiAdjustmentResult();
    $data['extra'] = 'junk';
    $result = UiAdjustmentSchema::validateAndStrip($data);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($result['data'])->not->toHaveKey('extra');
    expect($result['data'])->toHaveKeys([
        'version', 'branch', 'mr_title', 'mr_description',
        'files_changed', 'tests_added', 'screenshot', 'screenshot_mobile', 'notes',
    ]);
});

it('returns null data when invalid via validateAndStrip', function () {
    $data = validUiAdjustmentResult();
    unset($data['screenshot']);
    $result = UiAdjustmentSchema::validateAndStrip($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
    expect($result['data'])->toBeNull();
});

// ─── Constants ──────────────────────────────────────────────────

it('exposes schema version constant', function () {
    expect(UiAdjustmentSchema::VERSION)->toBe('1.0');
});

it('returns rules as an array with screenshot fields', function () {
    $rules = UiAdjustmentSchema::rules();

    expect($rules)->toBeArray();
    expect($rules)->toHaveKey('version');
    expect($rules)->toHaveKey('branch');
    expect($rules)->toHaveKey('screenshot');
    expect($rules)->toHaveKey('screenshot_mobile');
    expect($rules)->toHaveKey('files_changed.*.path');
    expect($rules)->toHaveKey('tests_added');
});

it('has more rules than FeatureDevSchema', function () {
    $featureRules = \App\Schemas\FeatureDevSchema::rules();
    $uiRules = UiAdjustmentSchema::rules();

    expect(count($uiRules))->toBeGreaterThan(count($featureRules));
});
