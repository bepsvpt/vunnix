<?php

uses(Tests\TestCase::class);

use App\Schemas\FeatureDevSchema;

/**
 * Helper: build a valid feature development result with optional overrides.
 */
function validFeatureDevResult(array $overrides = []): array
{
    $base = [
        'version' => '1.0',
        'branch' => 'ai/payment-feature',
        'mr_title' => 'Add Stripe payment flow',
        'mr_description' => 'Implements the Stripe payment integration as described in Issue #42.',
        'files_changed' => [
            [
                'path' => 'src/PaymentController.php',
                'action' => 'created',
                'summary' => 'New controller for Stripe payment handling',
            ],
            [
                'path' => 'src/PaymentService.php',
                'action' => 'modified',
                'summary' => 'Added webhook signature validation',
            ],
        ],
        'tests_added' => true,
        'notes' => 'Used existing PaymentGateway interface for consistency.',
    ];

    return array_replace_recursive($base, $overrides);
}

// ─── Valid data passes ──────────────────────────────────────────

it('validates a complete valid feature dev result', function () {
    $result = FeatureDevSchema::validate(validFeatureDevResult());

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

it('validates a result with no files changed', function () {
    $data = validFeatureDevResult(['files_changed' => []]);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates both file action values', function (string $action) {
    $data = validFeatureDevResult();
    $data['files_changed'][0]['action'] = $action;
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeTrue();
})->with(['created', 'modified']);

it('validates when tests_added is false', function () {
    $data = validFeatureDevResult(['tests_added' => false]);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates a result with many files changed', function () {
    $files = [];
    for ($i = 1; $i <= 20; $i++) {
        $files[] = [
            'path' => "src/File{$i}.php",
            'action' => $i % 2 === 0 ? 'modified' : 'created',
            'summary' => "Change {$i}",
        ];
    }
    $data = validFeatureDevResult(['files_changed' => $files]);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

// ─── Missing required fields fail ───────────────────────────────

it('fails when version is missing', function () {
    $data = validFeatureDevResult();
    unset($data['version']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('version');
});

it('fails when branch is missing', function () {
    $data = validFeatureDevResult();
    unset($data['branch']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('branch');
});

it('fails when mr_title is missing', function () {
    $data = validFeatureDevResult();
    unset($data['mr_title']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('mr_title');
});

it('fails when mr_description is missing', function () {
    $data = validFeatureDevResult();
    unset($data['mr_description']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('mr_description');
});

it('fails when files_changed is missing', function () {
    $data = validFeatureDevResult();
    unset($data['files_changed']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('files_changed');
});

it('fails when tests_added is missing', function () {
    $data = validFeatureDevResult();
    unset($data['tests_added']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('tests_added');
});

it('fails when notes is missing', function () {
    $data = validFeatureDevResult();
    unset($data['notes']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('notes');
});

it('fails when a file entry is missing path', function () {
    $data = validFeatureDevResult();
    unset($data['files_changed'][0]['path']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('files_changed.0.path');
});

it('fails when a file entry is missing action', function () {
    $data = validFeatureDevResult();
    unset($data['files_changed'][0]['action']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('files_changed.0.action');
});

it('fails when a file entry is missing summary', function () {
    $data = validFeatureDevResult();
    unset($data['files_changed'][0]['summary']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('files_changed.0.summary');
});

// ─── Invalid values fail ────────────────────────────────────────

it('fails when file action has an invalid value', function () {
    $data = validFeatureDevResult();
    $data['files_changed'][0]['action'] = 'deleted';
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('files_changed.0.action');
});

it('fails when tests_added is not a boolean', function () {
    $data = validFeatureDevResult(['tests_added' => 'yes']);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('tests_added');
});

it('fails when mr_title exceeds 500 characters', function () {
    $data = validFeatureDevResult(['mr_title' => str_repeat('A', 501)]);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('mr_title');
});

it('fails when branch exceeds 255 characters', function () {
    $data = validFeatureDevResult(['branch' => str_repeat('a', 256)]);
    $result = FeatureDevSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('branch');
});

// ─── Extra fields stripped ──────────────────────────────────────

it('strips unknown top-level fields', function () {
    $data = validFeatureDevResult();
    $data['unknown_field'] = 'should be removed';
    $data['another_extra'] = 42;
    $stripped = FeatureDevSchema::strip($data);

    expect($stripped)->not->toHaveKey('unknown_field');
    expect($stripped)->not->toHaveKey('another_extra');
    expect($stripped)->toHaveKeys(['version', 'branch', 'mr_title', 'mr_description', 'files_changed', 'tests_added', 'notes']);
});

it('strips unknown fields from files_changed entries', function () {
    $data = validFeatureDevResult();
    $data['files_changed'][0]['diff'] = '+some code';
    $data['files_changed'][0]['lines_added'] = 42;
    $stripped = FeatureDevSchema::strip($data);

    expect($stripped['files_changed'][0])->not->toHaveKey('diff');
    expect($stripped['files_changed'][0])->not->toHaveKey('lines_added');
    expect($stripped['files_changed'][0])->toHaveKeys(['path', 'action', 'summary']);
});

it('preserves valid data through strip', function () {
    $data = validFeatureDevResult();
    $stripped = FeatureDevSchema::strip($data);

    expect($stripped)->toEqual($data);
});

// ─── validateAndStrip ───────────────────────────────────────────

it('returns stripped data when valid via validateAndStrip', function () {
    $data = validFeatureDevResult();
    $data['extra'] = 'junk';
    $result = FeatureDevSchema::validateAndStrip($data);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($result['data'])->not->toHaveKey('extra');
    expect($result['data'])->toHaveKeys(['version', 'branch', 'mr_title', 'mr_description', 'files_changed', 'tests_added', 'notes']);
});

it('returns null data when invalid via validateAndStrip', function () {
    $data = validFeatureDevResult();
    unset($data['branch']);
    $result = FeatureDevSchema::validateAndStrip($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
    expect($result['data'])->toBeNull();
});

// ─── Constants ──────────────────────────────────────────────────

it('exposes schema version constant', function () {
    expect(FeatureDevSchema::VERSION)->toBe('1.0');
});

it('exposes file action constants', function () {
    expect(FeatureDevSchema::FILE_ACTIONS)->toBe(['created', 'modified']);
});

it('returns rules as an array', function () {
    $rules = FeatureDevSchema::rules();

    expect($rules)->toBeArray();
    expect($rules)->toHaveKey('version');
    expect($rules)->toHaveKey('branch');
    expect($rules)->toHaveKey('files_changed.*.path');
    expect($rules)->toHaveKey('tests_added');
    expect($rules)->toHaveKey('notes');
});
