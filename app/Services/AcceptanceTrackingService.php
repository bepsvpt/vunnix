<?php

namespace App\Services;

use Carbon\Carbon;

class AcceptanceTrackingService
{
    /**
     * Minimum number of resolved threads to consider "bulk resolution."
     */
    private const BULK_RESOLUTION_MIN_THREADS = 3;

    /**
     * Maximum time span (seconds) between first and last resolution
     * to be flagged as bulk resolution (over-reliance signal D113).
     */
    private const BULK_RESOLUTION_WINDOW_SECONDS = 60;

    /**
     * Number of lines of padding around a finding's line range when
     * checking for code change correlation.
     */
    private const CORRELATION_LINE_PADDING = 5;

    /**
     * Classify a GitLab discussion thread as accepted or dismissed.
     *
     * Per §16.2:
     * - Resolved (by engineer) → accepted
     * - Resolved (auto — finding no longer present) → accepted_auto
     * - Unresolved at merge → dismissed
     *
     * @param  array<string, mixed>  $discussion
     */
    public function classifyThreadState(array $discussion): string
    {
        $notes = $discussion['notes'] ?? [];
        if ($notes === []) {
            return 'dismissed';
        }

        // GitLab marks the first note's `resolved` field for the whole thread
        $firstNote = $notes[0];
        $resolved = $firstNote['resolved'] ?? false;

        return $resolved === true ? 'accepted' : 'dismissed';
    }

    /**
     * Detect bulk resolution pattern from timestamps.
     *
     * Per D113/§16.5: If 3+ threads are all resolved within 60 seconds,
     * flag as potential over-reliance (rubber-stamping).
     *
     * @param  array<int, array<string, mixed>>  $discussions  Resolved AI discussions
     */
    public function detectBulkResolution(array $discussions): bool
    {
        $resolvedDiscussions = array_filter(
            $discussions,
            fn (array $d): bool => ($d['notes'][0]['resolved'] ?? false) === true,
        );

        if (count($resolvedDiscussions) < self::BULK_RESOLUTION_MIN_THREADS) {
            return false;
        }

        $timestamps = [];
        foreach ($resolvedDiscussions as $discussion) {
            $updatedAt = $discussion['notes'][0]['updated_at'] ?? null;
            if ($updatedAt !== null) {
                $timestamps[] = Carbon::parse($updatedAt);
            }
        }

        if (count($timestamps) < self::BULK_RESOLUTION_MIN_THREADS) {
            return false;
        }

        sort($timestamps);

        $firstResolved = $timestamps[0];
        $lastResolved = end($timestamps);

        return $firstResolved->diffInSeconds($lastResolved) <= self::BULK_RESOLUTION_WINDOW_SECONDS;
    }

    /**
     * Check if a code change (from push event diffs) correlates with a finding.
     *
     * Per §16.2: If a finding targets file:line and the next push modifies
     * that region → strong acceptance signal.
     *
     * @param  array{file: string, line: int, end_line: int}  $finding
     * @param  array<int, array{new_path: string, diff: string}>  $diffs
     */
    public function correlateCodeChange(array $finding, array $diffs): bool
    {
        $targetFile = $finding['file'];
        $targetStart = $finding['line'] - self::CORRELATION_LINE_PADDING;
        $targetEnd = $finding['end_line'] + self::CORRELATION_LINE_PADDING;

        foreach ($diffs as $diff) {
            if ($diff['new_path'] !== $targetFile) {
                continue;
            }

            $modifiedRanges = $this->parseHunkRanges($diff['diff']);

            foreach ($modifiedRanges as [$hunkStart, $hunkEnd]) {
                if ($hunkStart <= $targetEnd && $hunkEnd >= $targetStart) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if a GitLab discussion was created by the AI (Vunnix bot).
     *
     * AI-created discussions use the InlineThreadFormatter format with
     * severity tag markers (emoji + bold severity level).
     *
     * @param  array<string, mixed>  $discussion
     */
    public function isAiCreatedDiscussion(array $discussion): bool
    {
        $notes = $discussion['notes'] ?? [];
        if ($notes === []) {
            return false;
        }

        $body = $notes[0]['body'] ?? '';

        // AI threads always start with a severity tag: 🔴 **Critical**, 🟡 **Major**, or 🟢 **Minor**
        return (bool) preg_match('/^(?:🔴|🟡|🟢)\s\*\*(?:Critical|Major|Minor)\*\*/', $body);
    }

    /**
     * Match a finding to its GitLab discussion by file path + title.
     *
     * Same matching logic as PostInlineThreads::hasExistingThread().
     *
     * @param  array<string, mixed>  $finding  Finding from task result
     * @param  array<int, array<string, mixed>>  $discussions  GitLab discussions
     * @return string|null The discussion ID if found, null otherwise
     */
    public function matchFindingToDiscussion(array $finding, array $discussions): ?string
    {
        foreach ($discussions as $discussion) {
            $notes = $discussion['notes'] ?? [];
            if ($notes === []) {
                continue;
            }

            $firstNote = $notes[0];
            $body = $firstNote['body'] ?? '';
            $position = $firstNote['position'] ?? [];

            $sameFile = ($position['new_path'] ?? '') === $finding['file'];
            $sameTitle = str_contains($body, $finding['title']);

            if ($sameFile && $sameTitle) {
                return $discussion['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * Parse unified diff hunk headers to extract modified line ranges.
     *
     * @return array<int, array{0: int, 1: int}> [[startLine, endLine], ...]
     */
    private function parseHunkRanges(string $diff): array
    {
        $ranges = [];

        // Match @@ -old,count +new,count @@ patterns
        preg_match_all('/@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $diff, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $start = (int) $match[1];
            $count = isset($match[2]) ? (int) $match[2] : 1;
            $end = $start + max($count - 1, 0);
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }
}
