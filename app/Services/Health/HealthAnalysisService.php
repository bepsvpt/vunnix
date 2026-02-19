<?php

namespace App\Services\Health;

use App\Contracts\HealthAnalyzerContract;
use App\Enums\HealthDimension;
use App\Events\HealthSnapshotRecorded;
use App\Models\HealthSnapshot;
use App\Models\MemoryEntry;
use App\Models\Project;
use App\Services\ProjectConfigService;
use App\Services\ProjectMemoryService;
use Illuminate\Support\Collection;

class HealthAnalysisService
{
    /**
     * @param  iterable<HealthAnalyzerContract>  $analyzers
     */
    public function __construct(
        private readonly iterable $analyzers,
        private readonly HealthAlertService $alertService,
        private readonly ProjectConfigService $projectConfigService,
        private readonly ProjectMemoryService $projectMemoryService,
    ) {}

    /**
     * @return Collection<int, HealthSnapshot>
     */
    public function analyzeProject(Project $project): Collection
    {
        if (! $this->healthEnabled($project)) {
            return collect();
        }

        $snapshots = collect();
        $memoryUpdated = false;

        foreach ($this->analyzers as $analyzer) {
            $dimension = $analyzer->dimension();

            if (! $this->dimensionEnabled($project, $dimension)) {
                continue;
            }

            $previous = HealthSnapshot::query()
                ->forProject($project->id)
                ->ofDimension($dimension->value)
                ->orderByDesc('created_at')
                ->first();

            $result = $analyzer->analyze($project);
            if ($result === null) {
                continue;
            }

            $snapshot = HealthSnapshot::query()->create([
                'project_id' => $project->id,
                'dimension' => $result->dimension->value,
                'score' => round($result->score, 2),
                'details' => $result->details,
                'source_ref' => $result->sourceRef,
                'created_at' => now(),
            ]);

            $snapshots->push($snapshot);

            $previousScore = $previous instanceof HealthSnapshot ? $previous->score : null;
            $trend = $this->trendDirection($previousScore, $snapshot->score);
            HealthSnapshotRecorded::dispatch($snapshot, $trend);

            if ($this->isSignificantFinding($project, $snapshot, $previousScore)) {
                $this->upsertHealthSignal($project, $snapshot, $trend, $previousScore);
                $memoryUpdated = true;
            }
        }

        if ($snapshots->isNotEmpty()) {
            $this->alertService->evaluateThresholds($project, $snapshots);
        }

        if ($memoryUpdated) {
            $this->projectMemoryService->invalidateProjectCache($project->id);
        }

        return $snapshots;
    }

    private function healthEnabled(Project $project): bool
    {
        if (! (bool) config('health.enabled', true)) {
            return false;
        }

        return (bool) $this->projectConfigService->get(
            $project,
            'health.enabled',
            (bool) config('health.enabled', true),
        );
    }

    private function dimensionEnabled(Project $project, HealthDimension $dimension): bool
    {
        return (bool) $this->projectConfigService->get(
            $project,
            $dimension->configKey(),
            (bool) config($dimension->configKey(), true),
        );
    }

    private function warningThreshold(Project $project, HealthDimension $dimension): float
    {
        return (float) $this->projectConfigService->get(
            $project,
            "health.thresholds.{$dimension->value}.warning",
            config("health.thresholds.{$dimension->value}.warning", $dimension->defaultWarningThreshold()),
        );
    }

    private function isSignificantFinding(Project $project, HealthSnapshot $snapshot, ?float $previousScore): bool
    {
        $dimension = HealthDimension::from($snapshot->dimension);
        $warning = $this->warningThreshold($project, $dimension);

        if ($dimension === HealthDimension::Dependency) {
            $belowWarning = (int) ($snapshot->details['total_count'] ?? 0) >= (int) $warning;
        } else {
            $belowWarning = $snapshot->score <= $warning;
        }

        if ($belowWarning) {
            return true;
        }

        return $previousScore !== null && $previousScore - $snapshot->score >= 5;
    }

    private function upsertHealthSignal(Project $project, HealthSnapshot $snapshot, string $trend, ?float $previousScore): void
    {
        $dimension = HealthDimension::from($snapshot->dimension);
        $warning = $this->warningThreshold($project, $dimension);

        $signal = $this->buildSignal($snapshot, $warning, $trend, $previousScore);
        $detailsSummary = $this->buildDetailsSummary($snapshot);

        $entry = MemoryEntry::query()
            ->forProject($project->id)
            ->active()
            ->ofType('health_signal')
            ->where('category', $dimension->value)
            ->first();

        $payload = [
            'type' => 'health_signal',
            'category' => $dimension->value,
            'content' => [
                'signal' => $signal,
                'score' => round($snapshot->score, 1),
                'trend' => $trend,
                'details_summary' => $detailsSummary,
            ],
            'confidence' => $this->confidenceFor($dimension),
            'source_meta' => [
                'snapshot_id' => $snapshot->id,
                'analysis_timestamp' => now()->toIso8601String(),
            ],
            'archived_at' => null,
        ];

        if ($entry instanceof MemoryEntry) {
            $entry->update($payload);

            return;
        }

        $project->memoryEntries()->create($payload);
    }

    private function trendDirection(?float $previousScore, float $currentScore): string
    {
        if ($previousScore === null) {
            return 'stable';
        }

        $delta = $currentScore - $previousScore;

        if ($delta > 1.0) {
            return 'up';
        }

        if ($delta < -1.0) {
            return 'down';
        }

        return 'stable';
    }

    private function confidenceFor(HealthDimension $dimension): int
    {
        return match ($dimension) {
            HealthDimension::Coverage => 80,
            HealthDimension::Dependency => 70,
            HealthDimension::Complexity => 50,
        };
    }

    private function buildSignal(HealthSnapshot $snapshot, float $warning, string $trend, ?float $previousScore): string
    {
        $dimension = HealthDimension::from($snapshot->dimension);

        if ($dimension === HealthDimension::Coverage) {
            $text = 'Test coverage is at '.round($snapshot->score, 1)."% (warning {$warning}%).";
            if ($previousScore !== null) {
                $delta = round($snapshot->score - $previousScore, 1);
                if ($delta < 0) {
                    $text .= ' Dropped by '.abs($delta).' points from previous scan.';
                }
            }

            return $text;
        }

        if ($dimension === HealthDimension::Dependency) {
            $count = (int) ($snapshot->details['total_count'] ?? 0);

            return "{$count} dependency vulnerabilities detected (threshold {$warning}).";
        }

        $hotspots = $snapshot->details['hotspot_files'] ?? [];
        $top = null;
        if (is_array($hotspots) && isset($hotspots[0]) && is_array($hotspots[0])) {
            $top = $hotspots[0]['path'] ?? null;
        }

        $base = 'Complexity score is '.round($snapshot->score, 1)." (warning {$warning}).";
        if (is_string($top) && $top !== '') {
            $base .= " Top hotspot: {$top}.";
        }

        if ($trend === 'down') {
            $base .= ' Complexity trend is worsening.';
        }

        return $base;
    }

    private function buildDetailsSummary(HealthSnapshot $snapshot): string
    {
        $dimension = HealthDimension::from($snapshot->dimension);

        return match ($dimension) {
            HealthDimension::Coverage => 'Coverage '.round($snapshot->score, 1).'%.',
            HealthDimension::Dependency => (int) ($snapshot->details['total_count'] ?? 0).' vulnerability findings.',
            HealthDimension::Complexity => (int) ($snapshot->details['files_analyzed'] ?? 0).' files analyzed for hotspots.',
        };
    }
}
