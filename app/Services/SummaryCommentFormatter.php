<?php

namespace App\Services;

/**
 * Formats a validated CodeReviewSchema result as a markdown summary comment.
 *
 * Layer 1 of the 3-Layer Comment Pattern (§4.5).
 * Produces: header + risk badge + collapsible walkthrough + collapsible findings.
 *
 * @see \App\Schemas\CodeReviewSchema
 */
class SummaryCommentFormatter
{
    private const RISK_BADGES = [
        'high' => '🔴 High',
        'medium' => '🟡 Medium',
        'low' => '🟢 Low',
    ];

    private const SEVERITY_BADGES = [
        'critical' => '🔴 Critical',
        'major' => '🟡 Major',
        'minor' => '🟢 Minor',
    ];

    /**
     * Format a validated code review result as a markdown summary comment.
     *
     * @param  array  $result  A validated CodeReviewSchema array.
     */
    public function format(array $result): string
    {
        $summary = $result['summary'];
        $findings = $result['findings'];

        $riskBadge = self::RISK_BADGES[$summary['risk_level']] ?? $summary['risk_level'];
        $issueCount = $summary['total_findings'];
        $filesChanged = count($summary['walkthrough']);

        $lines = [];

        // Header + risk badge
        $lines[] = '## 🤖 AI Code Review';
        $lines[] = '';
        $lines[] = "**Risk Level:** {$riskBadge} | **Issues Found:** {$issueCount} | **Files Changed:** {$filesChanged}";
        $lines[] = '';

        // Walkthrough (collapsible)
        $lines[] = '<details>';
        $lines[] = '<summary>📋 Walkthrough</summary>';
        $lines[] = '';
        $lines[] = '| File | Change |';
        $lines[] = '|------|--------|';
        foreach ($summary['walkthrough'] as $entry) {
            $file = '`' . $entry['file'] . '`';
            $lines[] = "| {$file} | {$entry['change_summary']} |";
        }
        $lines[] = '';
        $lines[] = '</details>';
        $lines[] = '';

        // Findings (collapsible)
        $lines[] = '<details>';
        $lines[] = '<summary>🔍 Findings Summary</summary>';
        $lines[] = '';
        $lines[] = '| # | Severity | Category | File | Description |';
        $lines[] = '|---|----------|----------|------|-------------|';
        foreach ($findings as $finding) {
            $severity = self::SEVERITY_BADGES[$finding['severity']] ?? $finding['severity'];
            $category = ucfirst($finding['category']);
            $file = '`' . $finding['file'] . ':' . $finding['line'] . '`';
            $lines[] = "| {$finding['id']} | {$severity} | {$category} | {$file} | {$finding['title']} |";
        }
        $lines[] = '';
        $lines[] = '</details>';

        return implode("\n", $lines);
    }
}
