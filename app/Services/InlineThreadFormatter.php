<?php

namespace App\Services;

/**
 * Formats a single code review finding as a markdown discussion thread body.
 *
 * Layer 2 of the 3-Layer Comment Pattern (§4.5).
 * Each thread contains: severity tag, category, title, description, and suggested fix.
 *
 * @see \App\Schemas\CodeReviewSchema
 */
class InlineThreadFormatter
{
    private const SEVERITY_TAGS = [
        'critical' => '🔴 **Critical**',
        'major' => '🟡 **Major**',
        'minor' => '🟢 **Minor**',
    ];

    private const INLINE_SEVERITIES = ['critical', 'major'];

    /**
     * Format a single finding as a markdown discussion thread body.
     *
     * @param  array<string, mixed>  $finding
     */
    public function format(array $finding): string
    {
        $severity = self::SEVERITY_TAGS[$finding['severity']] ?? $finding['severity'];
        $category = ucfirst($finding['category']);

        $lines = [];
        $lines[] = "{$severity} | {$category}";
        $lines[] = '';
        $lines[] = "**{$finding['title']}**";
        $lines[] = '';
        $lines[] = $finding['description'];
        $lines[] = '';
        $lines[] = '**Suggested fix:**';
        $lines[] = '';
        $lines[] = $finding['suggestion'];

        return implode("\n", $lines);
    }

    /**
     * Filter findings to only those that should get inline threads (high/medium severity).
     *
     * Per §4.5: "high/medium severity only" — critical and major findings get threads,
     * minor findings are informational only (appear in Layer 1 summary).
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    public function filterHighMedium(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn (array $finding) => in_array($finding['severity'], self::INLINE_SEVERITIES, true),
        ));
    }
}
