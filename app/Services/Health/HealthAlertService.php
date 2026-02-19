<?php

namespace App\Services\Health;

use App\Enums\HealthDimension;
use App\Models\AlertEvent;
use App\Models\HealthSnapshot;
use App\Models\Project;
use App\Services\GitLabClient;
use App\Services\ProjectConfigService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthAlertService
{
    public function __construct(
        private readonly ProjectConfigService $projectConfigService,
        private readonly GitLabClient $gitLab,
    ) {}

    /**
     * @param  Collection<int, HealthSnapshot>  $snapshots
     */
    public function evaluateThresholds(Project $project, Collection $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            $severity = $this->evaluateSeverity($project, $snapshot);
            $activeAlert = $this->findActiveAlert($project, $snapshot);

            if ($severity === null) {
                if ($activeAlert instanceof AlertEvent) {
                    $activeAlert->update([
                        'status' => 'resolved',
                        'resolved_at' => now(),
                    ]);
                }

                continue;
            }

            $thresholds = $this->thresholds($project, HealthDimension::from($snapshot->dimension));
            $message = $this->buildMessage($snapshot, $severity, $thresholds);

            if ($activeAlert instanceof AlertEvent) {
                $activeAlert->update([
                    'severity' => $severity,
                    'message' => $message,
                    'context' => $this->buildContext($project, $snapshot, $thresholds),
                ]);

                continue;
            }

            $alert = AlertEvent::query()->create([
                'alert_type' => HealthDimension::from($snapshot->dimension)->alertType(),
                'status' => 'active',
                'severity' => $severity,
                'message' => $message,
                'context' => $this->buildContext($project, $snapshot, $thresholds),
                'detected_at' => now(),
            ]);

            if (in_array($severity, ['warning', 'critical'], true)) {
                $this->createGitLabIssue($project, $snapshot, $alert, $severity);
            }
        }
    }

    private function evaluateSeverity(Project $project, HealthSnapshot $snapshot): ?string
    {
        $dimension = HealthDimension::from($snapshot->dimension);
        $thresholds = $this->thresholds($project, $dimension);

        if ($dimension === HealthDimension::Dependency) {
            $totalCount = (int) ($snapshot->details['total_count'] ?? 0);
            if ($totalCount >= $thresholds['critical']) {
                return 'critical';
            }

            if ($totalCount >= $thresholds['warning']) {
                return 'warning';
            }

            return null;
        }

        if ($snapshot->score <= $thresholds['critical']) {
            return 'critical';
        }

        if ($snapshot->score <= $thresholds['warning']) {
            return 'warning';
        }

        $delta = (float) ($snapshot->details['compared_to_previous'] ?? 0);
        if ($dimension === HealthDimension::Coverage && $delta <= -5) {
            return 'warning';
        }

        return null;
    }

    /**
     * @return array{warning: float, critical: float}
     */
    private function thresholds(Project $project, HealthDimension $dimension): array
    {
        $warning = (float) $this->projectConfigService->get(
            $project,
            "health.thresholds.{$dimension->value}.warning",
            config("health.thresholds.{$dimension->value}.warning", $dimension->defaultWarningThreshold()),
        );

        $critical = (float) $this->projectConfigService->get(
            $project,
            "health.thresholds.{$dimension->value}.critical",
            config("health.thresholds.{$dimension->value}.critical", $dimension->defaultCriticalThreshold()),
        );

        return ['warning' => $warning, 'critical' => $critical];
    }

    /**
     * @param  array{warning: float, critical: float}  $thresholds
     * @return array<string, mixed>
     */
    private function buildContext(Project $project, HealthSnapshot $snapshot, array $thresholds): array
    {
        return [
            'project_id' => $project->id,
            'dimension' => $snapshot->dimension,
            'score' => $snapshot->score,
            'thresholds' => $thresholds,
            'details' => $snapshot->details,
            'source_ref' => $snapshot->source_ref,
            'snapshot_id' => $snapshot->id,
        ];
    }

    /**
     * @param  array{warning: float, critical: float}  $thresholds
     */
    private function buildMessage(HealthSnapshot $snapshot, string $severity, array $thresholds): string
    {
        $dimension = HealthDimension::from($snapshot->dimension)->label();

        if ($snapshot->dimension === HealthDimension::Dependency->value) {
            $count = (int) ($snapshot->details['total_count'] ?? 0);

            return "{$dimension} alert ({$severity}) — {$count} vulnerability findings exceed threshold ({$thresholds['warning']}/{$thresholds['critical']}).";
        }

        $score = round($snapshot->score, 1);

        return "{$dimension} alert ({$severity}) — score {$score} is below threshold ({$thresholds['warning']}/{$thresholds['critical']}).";
    }

    private function findActiveAlert(Project $project, HealthSnapshot $snapshot): ?AlertEvent
    {
        $alerts = AlertEvent::query()
            ->active()
            ->ofType(HealthDimension::from($snapshot->dimension)->alertType())
            ->orderByDesc('id')
            ->get();

        return $alerts->first(function (AlertEvent $alert) use ($project): bool {
            $projectId = (int) ($alert->context['project_id'] ?? 0);

            return $projectId === $project->id;
        });
    }

    private function createGitLabIssue(Project $project, HealthSnapshot $snapshot, AlertEvent $alert, string $severity): void
    {
        try {
            $issue = $this->gitLab->createIssue($project->gitlab_project_id, [
                'title' => 'Health Alert: '.HealthDimension::from($snapshot->dimension)->label().' threshold crossed',
                'description' => $this->buildIssueDescription($snapshot, $severity),
                'labels' => 'ai::health-alert,ai::health-'.$snapshot->dimension,
            ]);
        } catch (Throwable $e) {
            Log::warning('HealthAlertService: failed to create GitLab issue', [
                'project_id' => $project->id,
                'dimension' => $snapshot->dimension,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $context = $alert->context ?? [];
        $context['gitlab_issue_url'] = $issue['web_url'] ?? null;
        $context['gitlab_issue_iid'] = isset($issue['iid']) ? (int) $issue['iid'] : null;
        $alert->context = $context;
        $alert->save();
    }

    private function buildIssueDescription(HealthSnapshot $snapshot, string $severity): string
    {
        $lines = [
            '# Proactive Health Alert',
            '',
            '- Dimension: '.HealthDimension::from($snapshot->dimension)->label(),
            '- Severity: '.$severity,
            '- Score: '.round($snapshot->score, 1),
            '- Source Ref: '.($snapshot->source_ref ?? 'n/a'),
            '',
            '## Details',
            '```json',
            '',
            '```',
            '',
            'Suggested action: review impacted files and recover this metric above warning threshold.',
        ];

        $detailsJson = json_encode($snapshot->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lines[9] = is_string($detailsJson) ? $detailsJson : '{}';

        return implode("\n", $lines);
    }
}
