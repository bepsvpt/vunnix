<?php

use App\Services\InlineThreadFormatter;

// ─── Helpers ─────────────────────────────────────────────────────

function criticalFinding(): array
{
    return [
        'id' => 1,
        'severity' => 'critical',
        'category' => 'security',
        'file' => 'src/auth.py',
        'line' => 42,
        'end_line' => 45,
        'title' => 'SQL injection risk',
        'description' => 'User input is interpolated directly into SQL query.',
        'suggestion' => "```diff\n-  query(f\"SELECT * FROM users WHERE id={id}\")\n+  query(\"SELECT * FROM users WHERE id=?\", [id])\n```",
        'labels' => [],
    ];
}

function majorFinding(): array
{
    return [
        'id' => 2,
        'severity' => 'major',
        'category' => 'bug',
        'file' => 'src/utils.py',
        'line' => 18,
        'end_line' => 22,
        'title' => 'Null pointer dereference',
        'description' => 'The user variable may be null when accessed.',
        'suggestion' => "```diff\n-  user.name\n+  user?.name ?? \"Unknown\"\n```",
        'labels' => [],
    ];
}

function minorFinding(): array
{
    return [
        'id' => 3,
        'severity' => 'minor',
        'category' => 'style',
        'file' => 'src/config.py',
        'line' => 7,
        'end_line' => 7,
        'title' => 'Unused import',
        'description' => 'The os module is imported but never used.',
        'suggestion' => "```diff\n-  import os\n```",
        'labels' => [],
    ];
}

// ─── Formats a critical finding with correct structure ──────────

it('formats a critical finding with severity tag, description, and suggestion', function (): void {
    $formatter = new InlineThreadFormatter;
    $markdown = $formatter->format(criticalFinding());

    expect($markdown)->toContain('🔴 **Critical**')
        ->and($markdown)->toContain('Security')
        ->and($markdown)->toContain('SQL injection risk')
        ->and($markdown)->toContain('User input is interpolated directly into SQL query.')
        ->and($markdown)->toContain('```diff');
});

// ─── Formats a major finding ────────────────────────────────────

it('formats a major finding with correct severity tag', function (): void {
    $formatter = new InlineThreadFormatter;
    $markdown = $formatter->format(majorFinding());

    expect($markdown)->toContain('🟡 **Major**')
        ->and($markdown)->toContain('Bug')
        ->and($markdown)->toContain('Null pointer dereference');
});

// ─── Includes suggestion block ──────────────────────────────────

it('includes the suggestion in the formatted output', function (): void {
    $formatter = new InlineThreadFormatter;
    $markdown = $formatter->format(criticalFinding());

    expect($markdown)->toContain('**Suggested fix:**')
        ->and($markdown)->toContain('```diff');
});

// ─── filterHighMedium returns only critical/major ───────────────

it('filters findings to only critical and major severity', function (): void {
    $formatter = new InlineThreadFormatter;
    $findings = [criticalFinding(), majorFinding(), minorFinding()];

    $filtered = $formatter->filterHighMedium($findings);

    expect($filtered)->toHaveCount(2)
        ->and($filtered[0]['severity'])->toBe('critical')
        ->and($filtered[1]['severity'])->toBe('major');
});

// ─── filterHighMedium with no qualifying findings ───────────────

it('returns empty array when no high/medium findings exist', function (): void {
    $formatter = new InlineThreadFormatter;
    $filtered = $formatter->filterHighMedium([minorFinding()]);

    expect($filtered)->toBeEmpty();
});

// ─── filterHighMedium with empty input ──────────────────────────

it('returns empty array for empty findings input', function (): void {
    $formatter = new InlineThreadFormatter;
    $filtered = $formatter->filterHighMedium([]);

    expect($filtered)->toBeEmpty();
});
