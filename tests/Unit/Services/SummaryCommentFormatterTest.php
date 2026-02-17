<?php

use App\Services\SummaryCommentFormatter;

// ─── Helpers ─────────────────────────────────────────────────────

function mixedReviewResult(): array
{
    return [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'medium',
            'total_findings' => 3,
            'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 1],
            'walkthrough' => [
                ['file' => 'src/auth.py', 'change_summary' => 'Added OAuth2 token refresh logic'],
                ['file' => 'tests/test_auth.py', 'change_summary' => 'Added 3 test cases'],
            ],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'critical',
                'category' => 'security',
                'file' => 'src/auth.py',
                'line' => 42,
                'end_line' => 45,
                'title' => 'SQL injection risk',
                'description' => 'User input is interpolated directly into SQL query.',
                'suggestion' => '```diff\n-  query(f"SELECT * FROM users WHERE id={id}")\n+  query("SELECT * FROM users WHERE id=?", [id])\n```',
                'labels' => [],
            ],
            [
                'id' => 2,
                'severity' => 'major',
                'category' => 'bug',
                'file' => 'src/utils.py',
                'line' => 18,
                'end_line' => 22,
                'title' => 'Null pointer dereference',
                'description' => 'The user variable may be null when accessed.',
                'suggestion' => '```diff\n-  user.name\n+  user?.name ?? "Unknown"\n```',
                'labels' => [],
            ],
            [
                'id' => 3,
                'severity' => 'minor',
                'category' => 'style',
                'file' => 'src/config.py',
                'line' => 7,
                'end_line' => 7,
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

// ─── Happy path: mixed severities ───────────────────────────────

it('formats a mixed-severity review into correct markdown', function (): void {
    $formatter = new SummaryCommentFormatter;
    $markdown = $formatter->format(mixedReviewResult());

    // Header
    expect($markdown)->toContain('## 🤖 AI Code Review');

    // Risk badge line
    expect($markdown)->toContain('🟡 Medium')
        ->and($markdown)->toContain('**Issues Found:** 3')
        ->and($markdown)->toContain('**Files Changed:** 2');

    // Walkthrough section (collapsible)
    expect($markdown)->toContain('<details>')
        ->and($markdown)->toContain('📋 Walkthrough')
        ->and($markdown)->toContain('`src/auth.py`')
        ->and($markdown)->toContain('Added OAuth2 token refresh logic')
        ->and($markdown)->toContain('`tests/test_auth.py`')
        ->and($markdown)->toContain('Added 3 test cases');

    // Findings section (collapsible)
    expect($markdown)->toContain('🔍 Findings Summary')
        ->and($markdown)->toContain('🔴 Critical')
        ->and($markdown)->toContain('🟡 Major')
        ->and($markdown)->toContain('🟢 Minor')
        ->and($markdown)->toContain('`src/auth.py:42`')
        ->and($markdown)->toContain('SQL injection risk')
        ->and($markdown)->toContain('`src/utils.py:18`')
        ->and($markdown)->toContain('Null pointer dereference')
        ->and($markdown)->toContain('`src/config.py:7`')
        ->and($markdown)->toContain('Unused import');
});

it('includes the correct severity emojis in findings rows', function (): void {
    $formatter = new SummaryCommentFormatter;
    $markdown = $formatter->format(mixedReviewResult());

    // Each finding should have: | # | emoji Severity | Category | `file:line` | Title |
    expect($markdown)->toContain('| 1 | 🔴 Critical | Security |')
        ->and($markdown)->toContain('| 2 | 🟡 Major | Bug |')
        ->and($markdown)->toContain('| 3 | 🟢 Minor | Style |');
});

// ─── Edge: zero findings ────────────────────────────────────────

it('formats a zero-findings review correctly', function (): void {
    $result = [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'low',
            'total_findings' => 0,
            'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
            'walkthrough' => [
                ['file' => 'src/config.py', 'change_summary' => 'Updated default timeout value'],
            ],
        ],
        'findings' => [],
        'labels' => ['ai::reviewed', 'ai::risk-low'],
        'commit_status' => 'success',
    ];

    $formatter = new SummaryCommentFormatter;
    $markdown = $formatter->format($result);

    expect($markdown)->toContain('## 🤖 AI Code Review')
        ->and($markdown)->toContain('🟢 Low')
        ->and($markdown)->toContain('**Issues Found:** 0')
        ->and($markdown)->toContain('**Files Changed:** 1')
        ->and($markdown)->toContain('📋 Walkthrough')
        ->and($markdown)->toContain('`src/config.py`')
        ->and($markdown)->toContain('🔍 Findings Summary');
});

// ─── Edge: all critical findings ────────────────────────────────

it('formats an all-critical review with high risk correctly', function (): void {
    $result = [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'high',
            'total_findings' => 2,
            'findings_by_severity' => ['critical' => 2, 'major' => 0, 'minor' => 0],
            'walkthrough' => [
                ['file' => 'src/auth.py', 'change_summary' => 'Disabled authentication check'],
                ['file' => 'src/db.py', 'change_summary' => 'Added raw SQL queries'],
            ],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'critical',
                'category' => 'security',
                'file' => 'src/auth.py',
                'line' => 10,
                'end_line' => 15,
                'title' => 'Authentication bypass',
                'description' => 'Auth check removed entirely.',
                'suggestion' => 'Restore the auth middleware.',
                'labels' => [],
            ],
            [
                'id' => 2,
                'severity' => 'critical',
                'category' => 'security',
                'file' => 'src/db.py',
                'line' => 25,
                'end_line' => 30,
                'title' => 'SQL injection',
                'description' => 'Raw user input in SQL.',
                'suggestion' => 'Use parameterized queries.',
                'labels' => [],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-high'],
        'commit_status' => 'failed',
    ];

    $formatter = new SummaryCommentFormatter;
    $markdown = $formatter->format($result);

    expect($markdown)->toContain('🔴 High')
        ->and($markdown)->toContain('**Issues Found:** 2')
        ->and($markdown)->toContain('**Files Changed:** 2')
        ->and($markdown)->toContain('| 1 | 🔴 Critical | Security |')
        ->and($markdown)->toContain('| 2 | 🔴 Critical | Security |');
});

// ─── T40: Incremental review timestamp ──────────────────────────

it('appends updated timestamp for incremental reviews', function (): void {
    $formatter = new SummaryCommentFormatter;
    $result = mixedReviewResult();

    $markdown = $formatter->format($result, new \DateTimeImmutable('2026-02-14 14:32'));

    expect($markdown)->toContain('## 🤖 AI Code Review')
        ->and($markdown)->toContain('📝 Updated: 2026-02-14 14:32');
});

it('does not include timestamp for initial reviews', function (): void {
    $formatter = new SummaryCommentFormatter;
    $result = mixedReviewResult();

    $markdown = $formatter->format($result);

    expect($markdown)->not->toContain('📝 Updated');
});

// ─── Edge: category display uses title case ─────────────────────

it('capitalizes category names in the findings table', function (): void {
    $result = mixedReviewResult();

    $formatter = new SummaryCommentFormatter;
    $markdown = $formatter->format($result);

    expect($markdown)->toContain('| Security |')
        ->and($markdown)->toContain('| Bug |')
        ->and($markdown)->toContain('| Style |');
});
