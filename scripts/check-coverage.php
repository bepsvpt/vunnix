#!/usr/bin/env php
<?php

/**
 * Per-file test coverage enforcement.
 *
 * Parses a Clover XML coverage report and fails if any source file's
 * line coverage falls below the minimum threshold (default: 90%).
 *
 * Usage:
 *   php scripts/check-coverage.php [clover.xml path] [--min=90]
 *
 * Exit codes:
 *   0 — All files meet the threshold
 *   1 — One or more files are below the threshold
 *   2 — Usage error (missing file, bad XML, etc.)
 */

// ─── Configuration ──────────────────────────────────────────────────────────

/** @var int Minimum line coverage percentage per file */
$minCoverage = 90;

/** @var list<string> Glob patterns (relative to project root) to exclude from enforcement */
$excludePatterns = [
    // Add patterns here for files that are intentionally below threshold, e.g.:
    // 'app/Providers/AppServiceProvider.php',
];

// ─── CLI argument parsing ───────────────────────────────────────────────────

$cloverPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--min=')) {
        $minCoverage = (int) substr($arg, 6);
        if ($minCoverage < 0 || $minCoverage > 100) {
            fwrite(STDERR, "Error: --min must be between 0 and 100.\n");
            exit(2);
        }
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    } else {
        $cloverPath = $arg;
    }
}

if ($cloverPath === null) {
    $cloverPath = __DIR__.'/../coverage/php/clover.xml';
}

if (! file_exists($cloverPath)) {
    fwrite(STDERR, "Error: Clover XML not found at {$cloverPath}\n");
    fwrite(STDERR, "Run 'composer test:coverage' first to generate coverage data.\n");
    exit(2);
}

// ─── Parse Clover XML ───────────────────────────────────────────────────────

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Error: Failed to parse Clover XML at {$cloverPath}\n");
    exit(2);
}

// Detect project root (directory containing composer.json)
$projectRoot = realpath(__DIR__.'/..').'/';

// ─── Helpers ────────────────────────────────────────────────────────────────

function matchesExcludePattern(string $relativePath, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (fnmatch($pattern, $relativePath)) {
            return true;
        }
    }

    return false;
}

function formatCoverage(float $pct): string
{
    return number_format($pct, 1).'%';
}

// ─── Analyze each file ──────────────────────────────────────────────────────

$failures = [];
$passed = 0;
$skipped = 0;
$total = 0;

// XPath finds <file> elements at any depth (they nest inside <package> nodes)
$fileNodes = $xml->xpath('//file[@name]') ?: [];

foreach ($fileNodes as $fileNode) {
    $filePath = (string) $fileNode['name'];
    $total++;

    // Only check files under app/
    if (! str_starts_with($filePath, $projectRoot.'app/')) {
        $skipped++;

        continue;
    }

    $relativePath = str_replace($projectRoot, '', $filePath);

    // Check exclusions
    if (matchesExcludePattern($relativePath, $excludePatterns)) {
        $skipped++;

        continue;
    }

    // Each <file> has multiple <metrics> nodes: one per <class> + one file-level.
    // The file-level <metrics> is the last direct child and contains the aggregate.
    $allMetrics = $fileNode->metrics;
    if (! $allMetrics || count($allMetrics) === 0) {
        $skipped++;

        continue;
    }
    $metrics = $allMetrics[count($allMetrics) - 1];

    $statements = (int) ($metrics['statements'] ?? 0);
    $coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);

    // Skip files with no executable statements
    if ($statements === 0) {
        $skipped++;

        continue;
    }

    $coverage = ($coveredStatements / $statements) * 100;

    if ($coverage < $minCoverage) {
        $failures[] = [
            'file' => $relativePath,
            'coverage' => $coverage,
            'statements' => $statements,
            'covered' => $coveredStatements,
            'missing' => $statements - $coveredStatements,
        ];
    } else {
        $passed++;
    }
}

// ─── Report ─────────────────────────────────────────────────────────────────

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║           Per-File Coverage Check (min: {$minCoverage}%)              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

if (count($failures) === 0) {
    echo "  ✅ All {$passed} files meet the {$minCoverage}% minimum coverage threshold.\n";
    echo "     ({$skipped} files skipped — excluded or no executable statements)\n\n";
    exit(0);
}

// Sort failures by coverage ascending (worst first)
usort($failures, fn ($a, $b) => $a['coverage'] <=> $b['coverage']);

echo '  ❌ '.count($failures)." file(s) below {$minCoverage}% coverage:\n\n";

// Calculate column widths
$maxFileLen = max(array_map(fn ($f) => strlen($f['file']), $failures));
$maxFileLen = max($maxFileLen, 4); // minimum "File" header width

echo sprintf("  %-{$maxFileLen}s  %10s  %10s  %10s\n", 'File', 'Coverage', 'Stmts', 'Missing');
echo '  '.str_repeat('─', $maxFileLen + 36)."\n";

foreach ($failures as $failure) {
    echo sprintf(
        "  %-{$maxFileLen}s  %9s  %10d  %10d\n",
        $failure['file'],
        formatCoverage($failure['coverage']),
        $failure['statements'],
        $failure['missing'],
    );
}

echo "\n";
echo "  Summary: {$passed} passed, ".count($failures)." failed, {$skipped} skipped\n";
echo "  Threshold: {$minCoverage}% minimum line coverage per file\n\n";

exit(1);
