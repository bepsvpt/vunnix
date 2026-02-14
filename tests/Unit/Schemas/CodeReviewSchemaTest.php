<?php

uses(Tests\TestCase::class);

use App\Schemas\CodeReviewSchema;

/**
 * Helper: build a valid code review result with optional overrides.
 */
function validCodeReviewResult(array $overrides = []): array
{
    $base = [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'medium',
            'total_findings' => 2,
            'findings_by_severity' => ['critical' => 0, 'major' => 1, 'minor' => 1],
            'walkthrough' => [
                ['file' => 'src/auth.py', 'change_summary' => 'Added OAuth2 token refresh logic'],
                ['file' => 'src/utils.py', 'change_summary' => 'Refactored helper methods'],
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
                'suggestion' => "```diff\n-  use_token(token)\n+  if token.is_valid():\n+      use_token(token)\n```",
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
                'description' => 'The `os` module is imported but never used.',
                'suggestion' => "```diff\n-  import os\n```",
                'labels' => ['cleanup'],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-medium'],
        'commit_status' => 'success',
    ];

    return array_replace_recursive($base, $overrides);
}

// ─── Valid data passes ──────────────────────────────────────────

it('validates a complete valid code review result', function () {
    $result = CodeReviewSchema::validate(validCodeReviewResult());

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

it('validates a result with zero findings', function () {
    $data = validCodeReviewResult([
        'summary' => [
            'total_findings' => 0,
            'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
            'walkthrough' => [
                ['file' => 'README.md', 'change_summary' => 'Updated docs'],
            ],
        ],
        'findings' => [],
    ]);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeTrue();
});

it('validates all three risk levels', function (string $riskLevel) {
    $data = validCodeReviewResult(['summary' => ['risk_level' => $riskLevel]]);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeTrue();
})->with(['high', 'medium', 'low']);

it('validates all severity values in findings', function (string $severity) {
    $data = validCodeReviewResult();
    $data['findings'][0]['severity'] = $severity;
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeTrue();
})->with(['critical', 'major', 'minor']);

it('validates all category values in findings', function (string $category) {
    $data = validCodeReviewResult();
    $data['findings'][0]['category'] = $category;
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeTrue();
})->with(['security', 'bug', 'performance', 'style', 'convention']);

it('validates both commit status values', function (string $status) {
    $data = validCodeReviewResult(['commit_status' => $status]);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeTrue();
})->with(['success', 'failed']);

// ─── Missing required fields fail ───────────────────────────────

it('fails when summary is missing', function () {
    $data = validCodeReviewResult();
    unset($data['summary']);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('summary');
});

it('fails when version is missing', function () {
    $data = validCodeReviewResult();
    unset($data['version']);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('version');
});

it('fails when findings is missing', function () {
    $data = validCodeReviewResult();
    unset($data['findings']);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('findings');
});

it('fails when labels is missing', function () {
    $data = validCodeReviewResult();
    unset($data['labels']);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('labels');
});

it('fails when commit_status is missing', function () {
    $data = validCodeReviewResult();
    unset($data['commit_status']);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('commit_status');
});

it('fails when summary.risk_level is missing', function () {
    $data = validCodeReviewResult();
    unset($data['summary']['risk_level']);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('summary.risk_level');
});

it('fails when summary.findings_by_severity is missing', function () {
    $data = validCodeReviewResult();
    unset($data['summary']['findings_by_severity']);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('summary.findings_by_severity');
});

it('fails when a finding is missing required fields', function () {
    $data = validCodeReviewResult();
    unset($data['findings'][0]['title']);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('findings.0.title');
});

// ─── Invalid values fail ────────────────────────────────────────

it('fails when severity has an invalid value', function () {
    $data = validCodeReviewResult();
    $data['findings'][0]['severity'] = 'warning';
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('findings.0.severity');
});

it('fails when risk_level has an invalid value', function () {
    $data = validCodeReviewResult(['summary' => ['risk_level' => 'extreme']]);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('summary.risk_level');
});

it('fails when category has an invalid value', function () {
    $data = validCodeReviewResult();
    $data['findings'][0]['category'] = 'readability';
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('findings.0.category');
});

it('fails when commit_status has an invalid value', function () {
    $data = validCodeReviewResult(['commit_status' => 'pending']);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('commit_status');
});

it('fails when finding line is zero', function () {
    $data = validCodeReviewResult();
    $data['findings'][0]['line'] = 0;
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('findings.0.line');
});

it('fails when finding id is zero', function () {
    $data = validCodeReviewResult();
    $data['findings'][0]['id'] = 0;
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('findings.0.id');
});

it('fails when total_findings is negative', function () {
    $data = validCodeReviewResult(['summary' => ['total_findings' => -1]]);
    $result = CodeReviewSchema::validate($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('summary.total_findings');
});

// ─── Extra fields stripped ──────────────────────────────────────

it('strips unknown top-level fields', function () {
    $data = validCodeReviewResult();
    $data['unknown_field'] = 'should be removed';
    $data['another_extra'] = 42;
    $stripped = CodeReviewSchema::strip($data);

    expect($stripped)->not->toHaveKey('unknown_field');
    expect($stripped)->not->toHaveKey('another_extra');
    expect($stripped)->toHaveKeys(['version', 'summary', 'findings', 'labels', 'commit_status']);
});

it('strips unknown fields from summary', function () {
    $data = validCodeReviewResult();
    $data['summary']['extra_summary_field'] = 'should go';
    $stripped = CodeReviewSchema::strip($data);

    expect($stripped['summary'])->not->toHaveKey('extra_summary_field');
    expect($stripped['summary'])->toHaveKeys(['risk_level', 'total_findings', 'findings_by_severity', 'walkthrough']);
});

it('strips unknown fields from findings_by_severity', function () {
    $data = validCodeReviewResult();
    $data['summary']['findings_by_severity']['info'] = 5;
    $stripped = CodeReviewSchema::strip($data);

    expect($stripped['summary']['findings_by_severity'])->not->toHaveKey('info');
    expect($stripped['summary']['findings_by_severity'])->toHaveKeys(['critical', 'major', 'minor']);
});

it('strips unknown fields from walkthrough entries', function () {
    $data = validCodeReviewResult();
    $data['summary']['walkthrough'][0]['extra'] = 'nope';
    $stripped = CodeReviewSchema::strip($data);

    expect($stripped['summary']['walkthrough'][0])->not->toHaveKey('extra');
    expect($stripped['summary']['walkthrough'][0])->toHaveKeys(['file', 'change_summary']);
});

it('strips unknown fields from findings', function () {
    $data = validCodeReviewResult();
    $data['findings'][0]['confidence'] = 0.95;
    $data['findings'][0]['ai_reasoning'] = 'internal';
    $stripped = CodeReviewSchema::strip($data);

    expect($stripped['findings'][0])->not->toHaveKey('confidence');
    expect($stripped['findings'][0])->not->toHaveKey('ai_reasoning');
    expect($stripped['findings'][0])->toHaveKeys([
        'id', 'severity', 'category', 'file', 'line',
        'end_line', 'title', 'description', 'suggestion', 'labels',
    ]);
});

it('preserves valid data through strip', function () {
    $data = validCodeReviewResult();
    $stripped = CodeReviewSchema::strip($data);

    expect($stripped)->toEqual($data);
});

// ─── validateAndStrip ───────────────────────────────────────────

it('returns stripped data when valid via validateAndStrip', function () {
    $data = validCodeReviewResult();
    $data['extra'] = 'junk';
    $result = CodeReviewSchema::validateAndStrip($data);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($result['data'])->not->toHaveKey('extra');
    expect($result['data'])->toHaveKeys(['version', 'summary', 'findings', 'labels', 'commit_status']);
});

it('returns null data when invalid via validateAndStrip', function () {
    $data = validCodeReviewResult();
    unset($data['summary']);
    $result = CodeReviewSchema::validateAndStrip($data);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
    expect($result['data'])->toBeNull();
});

// ─── Constants ──────────────────────────────────────────────────

it('exposes schema version constant', function () {
    expect(CodeReviewSchema::VERSION)->toBe('1.0');
});

it('exposes severity constants', function () {
    expect(CodeReviewSchema::SEVERITIES)->toBe(['critical', 'major', 'minor']);
});

it('exposes category constants', function () {
    expect(CodeReviewSchema::CATEGORIES)->toBe(['security', 'bug', 'performance', 'style', 'convention']);
});

it('exposes risk level constants', function () {
    expect(CodeReviewSchema::RISK_LEVELS)->toBe(['high', 'medium', 'low']);
});

it('returns rules as an array', function () {
    $rules = CodeReviewSchema::rules();

    expect($rules)->toBeArray();
    expect($rules)->toHaveKey('version');
    expect($rules)->toHaveKey('summary');
    expect($rules)->toHaveKey('findings.*.severity');
    expect($rules)->toHaveKey('commit_status');
});
