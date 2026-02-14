<?php

use App\Services\LabelMapper;

// ─── Helpers ─────────────────────────────────────────────────────

function highRiskResult(): array
{
    return [
        'summary' => [
            'risk_level' => 'high',
            'total_findings' => 2,
            'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
            'walkthrough' => [],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'critical',
                'category' => 'security',
                'file' => 'src/auth.py',
                'line' => 42,
                'end_line' => 45,
                'title' => 'SQL injection',
                'description' => 'Vulnerable query.',
                'suggestion' => 'Use parameterized queries.',
                'labels' => [],
            ],
            [
                'id' => 2,
                'severity' => 'major',
                'category' => 'bug',
                'file' => 'src/utils.py',
                'line' => 10,
                'end_line' => 12,
                'title' => 'NPE risk',
                'description' => 'Null check missing.',
                'suggestion' => 'Add null check.',
                'labels' => [],
            ],
        ],
    ];
}

function mediumRiskResult(): array
{
    return [
        'summary' => [
            'risk_level' => 'medium',
            'total_findings' => 1,
            'findings_by_severity' => ['critical' => 0, 'major' => 1, 'minor' => 0],
            'walkthrough' => [],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'major',
                'category' => 'bug',
                'file' => 'src/app.py',
                'line' => 15,
                'end_line' => 20,
                'title' => 'Unchecked return',
                'description' => 'Return value ignored.',
                'suggestion' => 'Check return value.',
                'labels' => [],
            ],
        ],
    ];
}

function lowRiskResult(): array
{
    return [
        'summary' => [
            'risk_level' => 'low',
            'total_findings' => 1,
            'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 1],
            'walkthrough' => [],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'minor',
                'category' => 'style',
                'file' => 'src/config.py',
                'line' => 7,
                'end_line' => 7,
                'title' => 'Unused import',
                'description' => 'os is unused.',
                'suggestion' => 'Remove import.',
                'labels' => [],
            ],
        ],
    ];
}

function noFindingsResult(): array
{
    return [
        'summary' => [
            'risk_level' => 'low',
            'total_findings' => 0,
            'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
            'walkthrough' => [],
        ],
        'findings' => [],
    ];
}

function securityFindingNonCriticalResult(): array
{
    return [
        'summary' => [
            'risk_level' => 'medium',
            'total_findings' => 1,
            'findings_by_severity' => ['critical' => 0, 'major' => 1, 'minor' => 0],
            'walkthrough' => [],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'major',
                'category' => 'security',
                'file' => 'src/auth.py',
                'line' => 10,
                'end_line' => 15,
                'title' => 'Weak hashing',
                'description' => 'MD5 used for passwords.',
                'suggestion' => 'Use bcrypt.',
                'labels' => [],
            ],
        ],
    ];
}

// ─── mapLabels: high risk → ai::reviewed + ai::risk-high ────────

it('maps high risk level to ai::risk-high label', function () {
    $mapper = new LabelMapper();
    $labels = $mapper->mapLabels(highRiskResult());

    expect($labels)->toContain('ai::reviewed')
        ->and($labels)->toContain('ai::risk-high')
        ->and($labels)->not->toContain('ai::risk-medium')
        ->and($labels)->not->toContain('ai::risk-low');
});

// ─── mapLabels: medium risk → ai::reviewed + ai::risk-medium ────

it('maps medium risk level to ai::risk-medium label', function () {
    $mapper = new LabelMapper();
    $labels = $mapper->mapLabels(mediumRiskResult());

    expect($labels)->toContain('ai::reviewed')
        ->and($labels)->toContain('ai::risk-medium')
        ->and($labels)->not->toContain('ai::risk-high')
        ->and($labels)->not->toContain('ai::risk-low');
});

// ─── mapLabels: low risk → ai::reviewed + ai::risk-low ──────────

it('maps low risk level to ai::risk-low label', function () {
    $mapper = new LabelMapper();
    $labels = $mapper->mapLabels(lowRiskResult());

    expect($labels)->toContain('ai::reviewed')
        ->and($labels)->toContain('ai::risk-low')
        ->and($labels)->not->toContain('ai::risk-high')
        ->and($labels)->not->toContain('ai::risk-medium');
});

// ─── mapLabels: no findings → ai::reviewed + ai::risk-low ───────

it('maps no findings to ai::reviewed and ai::risk-low', function () {
    $mapper = new LabelMapper();
    $labels = $mapper->mapLabels(noFindingsResult());

    expect($labels)->toContain('ai::reviewed')
        ->and($labels)->toContain('ai::risk-low');
});

// ─── mapLabels: security finding → ai::security ─────────────────

it('adds ai::security label when security category findings exist', function () {
    $mapper = new LabelMapper();
    $labels = $mapper->mapLabels(highRiskResult());

    expect($labels)->toContain('ai::security');
});

// ─── mapLabels: non-security finding → no ai::security ──────────

it('does not add ai::security when no security findings exist', function () {
    $mapper = new LabelMapper();
    $labels = $mapper->mapLabels(mediumRiskResult());

    expect($labels)->not->toContain('ai::security');
});

// ─── mapLabels: security finding at non-critical severity ────────

it('adds ai::security for security category regardless of severity', function () {
    $mapper = new LabelMapper();
    $labels = $mapper->mapLabels(securityFindingNonCriticalResult());

    expect($labels)->toContain('ai::security')
        ->and($labels)->toContain('ai::risk-medium');
});

// ─── mapLabels: always includes ai::reviewed ─────────────────────

it('always includes ai::reviewed label', function () {
    $mapper = new LabelMapper();

    expect($mapper->mapLabels(highRiskResult()))->toContain('ai::reviewed')
        ->and($mapper->mapLabels(mediumRiskResult()))->toContain('ai::reviewed')
        ->and($mapper->mapLabels(lowRiskResult()))->toContain('ai::reviewed')
        ->and($mapper->mapLabels(noFindingsResult()))->toContain('ai::reviewed');
});

// ─── mapCommitStatus: critical findings → failed ─────────────────

it('returns failed commit status when critical findings exist', function () {
    $mapper = new LabelMapper();
    $status = $mapper->mapCommitStatus(highRiskResult());

    expect($status)->toBe('failed');
});

// ─── mapCommitStatus: no critical findings → success ─────────────

it('returns success commit status when no critical findings exist', function () {
    $mapper = new LabelMapper();

    expect($mapper->mapCommitStatus(mediumRiskResult()))->toBe('success')
        ->and($mapper->mapCommitStatus(lowRiskResult()))->toBe('success')
        ->and($mapper->mapCommitStatus(noFindingsResult()))->toBe('success');
});

// ─── mapCommitStatus: security finding without critical → success ─

it('returns success for security findings without critical severity', function () {
    $mapper = new LabelMapper();
    $status = $mapper->mapCommitStatus(securityFindingNonCriticalResult());

    expect($status)->toBe('success');
});
