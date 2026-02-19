#!/usr/bin/env php
<?php

/**
 * Coverage policy enforcement for backend/frontend Clover reports.
 *
 * Enforces both:
 * - overall line coverage minimum
 * - per-file line coverage minimum
 *
 * Usage:
 *   php scripts/check-coverage.php [clover.xml path] [--scope=backend|frontend]
 *                                  [--min-file=95] [--min-overall=97.5]
 *
 * Backwards compatibility:
 *   --min=<value> is accepted as an alias of --min-file=<value>
 */
$projectRoot = realpath(__DIR__.'/..');
if ($projectRoot === false) {
    fwrite(STDERR, "Error: Unable to resolve project root.\n");
    exit(2);
}
$projectRoot .= '/';

/**
 * @var array<string, array{
 *   defaultReport: string,
 *   prefixes: list<string>,
 *   pathAttributes: list<string>,
 *   minFile: float,
 *   minOverall: float
 * }>
 */
$policies = [
    'backend' => [
        'defaultReport' => $projectRoot.'coverage/php/clover.xml',
        'prefixes' => [$projectRoot.'app/'],
        'pathAttributes' => ['name'],
        'minFile' => 95.0,
        'minOverall' => 97.5,
    ],
    'frontend' => [
        'defaultReport' => $projectRoot.'coverage/js/clover.xml',
        'prefixes' => [$projectRoot.'resources/js/'],
        'pathAttributes' => ['path', 'name'],
        'minFile' => 95.0,
        'minOverall' => 97.5,
    ],
];

$scope = 'backend';
$cloverPath = null;
$minFileCoverage = null;
$minOverallCoverage = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--scope=')) {
        $scope = substr($arg, 8);

        continue;
    }

    if (str_starts_with($arg, '--min-file=')) {
        $minFileCoverage = (float) substr($arg, 11);

        continue;
    }

    if (str_starts_with($arg, '--min-overall=')) {
        $minOverallCoverage = (float) substr($arg, 14);

        continue;
    }

    if (str_starts_with($arg, '--min=')) {
        $minFileCoverage = (float) substr($arg, 6);

        continue;
    }

    if (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }

    if ($cloverPath !== null) {
        fwrite(STDERR, "Error: Multiple Clover paths provided.\n");
        exit(2);
    }

    $cloverPath = $arg;
}

if (! isset($policies[$scope])) {
    fwrite(STDERR, "Error: Invalid --scope value '{$scope}'. Use 'backend' or 'frontend'.\n");
    exit(2);
}

$policy = $policies[$scope];

$cloverPath ??= $policy['defaultReport'];
$minFileCoverage ??= $policy['minFile'];
$minOverallCoverage ??= $policy['minOverall'];

if ($minFileCoverage < 0 || $minFileCoverage > 100) {
    fwrite(STDERR, "Error: --min-file must be between 0 and 100.\n");
    exit(2);
}
if ($minOverallCoverage < 0 || $minOverallCoverage > 100) {
    fwrite(STDERR, "Error: --min-overall must be between 0 and 100.\n");
    exit(2);
}

if (! file_exists($cloverPath)) {
    fwrite(STDERR, "Error: Clover XML not found at {$cloverPath}\n");
    fwrite(STDERR, "Run the relevant coverage command first.\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Error: Failed to parse Clover XML at {$cloverPath}\n");
    exit(2);
}

/**
 * @param  list<string>  $attributes
 */
function extractCoveragePath(SimpleXMLElement $fileNode, array $attributes): ?string
{
    foreach ($attributes as $attribute) {
        $candidate = (string) ($fileNode[$attribute] ?? '');
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

/**
 * @param  list<string>  $prefixes
 */
function isWithinPrefixes(string $filePath, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if (str_starts_with($filePath, $prefix)) {
            return true;
        }
    }

    return false;
}

function toRelativePath(string $filePath, string $projectRoot): string
{
    return str_starts_with($filePath, $projectRoot)
        ? str_replace($projectRoot, '', $filePath)
        : $filePath;
}

function formatCoverage(float $pct): string
{
    return number_format($pct, 2).'%';
}

$failures = [];
$checked = 0;
$skipped = 0;
$fileNodesSeen = 0;
$totalStatements = 0;
$totalCoveredStatements = 0;

$fileNodes = $xml->xpath('//file') ?: [];

foreach ($fileNodes as $fileNode) {
    $filePath = extractCoveragePath($fileNode, $policy['pathAttributes']);
    if ($filePath === null) {
        continue;
    }

    $fileNodesSeen++;

    if (! isWithinPrefixes($filePath, $policy['prefixes'])) {
        $skipped++;

        continue;
    }

    $relativePath = toRelativePath($filePath, $projectRoot);

    $allMetrics = $fileNode->metrics;
    if (! $allMetrics || count($allMetrics) === 0) {
        $skipped++;

        continue;
    }

    $metrics = $allMetrics[count($allMetrics) - 1];
    $statements = (int) ($metrics['statements'] ?? 0);
    $coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);

    if ($statements === 0) {
        $skipped++;

        continue;
    }

    $coverage = ($coveredStatements / $statements) * 100;
    $totalStatements += $statements;
    $totalCoveredStatements += $coveredStatements;
    $checked++;

    if ($coverage < $minFileCoverage) {
        $failures[] = [
            'file' => $relativePath,
            'coverage' => $coverage,
            'statements' => $statements,
            'missing' => $statements - $coveredStatements,
        ];
    }
}

if ($checked === 0 || $totalStatements === 0) {
    fwrite(STDERR, "Error: No executable files found for scope '{$scope}'.\n");
    exit(2);
}

$overallCoverage = ($totalCoveredStatements / $totalStatements) * 100;
$overallFailed = $overallCoverage < $minOverallCoverage;

usort($failures, fn ($a, $b) => $a['coverage'] <=> $b['coverage']);

echo "\n";
echo "Coverage Policy Check ({$scope})\n";
echo "Report: {$cloverPath}\n";
echo 'Overall minimum: '.formatCoverage($minOverallCoverage)."\n";
echo 'Per-file minimum: '.formatCoverage($minFileCoverage)."\n\n";

echo 'Overall coverage: '.formatCoverage($overallCoverage)." ({$totalCoveredStatements}/{$totalStatements})";
echo $overallFailed ? "  [FAIL]\n" : "  [PASS]\n";
echo "Files checked: {$checked}, skipped: {$skipped}, file nodes seen: {$fileNodesSeen}\n\n";

if (count($failures) > 0) {
    $maxFileLen = max(4, max(array_map(fn ($f) => strlen($f['file']), $failures)));
    echo count($failures)." file(s) below per-file minimum:\n\n";
    echo sprintf("  %-{$maxFileLen}s  %10s  %10s  %10s\n", 'File', 'Coverage', 'Stmts', 'Missing');
    echo '  '.str_repeat('-', $maxFileLen + 36)."\n";
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
}

if (! $overallFailed && count($failures) === 0) {
    echo "PASS: Coverage policy satisfied.\n\n";
    exit(0);
}

echo "FAIL: Coverage policy violation detected.\n\n";
exit(1);
