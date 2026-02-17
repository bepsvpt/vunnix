<?php

namespace App\Services;

/**
 * Map code review results to GitLab labels and commit status.
 *
 * Computes labels and commit status independently from the findings
 * structure — does NOT use the executor-suggested values.
 *
 * @see §4.5 3-Layer Comment Pattern — Layer 3
 * @see §4.6 Severity Classification & Labels
 */
class LabelMapper
{
    /**
     * Map a code review result to GitLab labels.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    public function mapLabels(array $result): array
    {
        $labels = ['ai::reviewed'];

        // Risk level label from summary
        $riskLevel = $result['summary']['risk_level'] ?? 'low';
        $labels[] = match ($riskLevel) {
            'high' => 'ai::risk-high',
            'medium' => 'ai::risk-medium',
            default => 'ai::risk-low',
        };

        // Security label if any finding has security category
        if ($this->hasSecurityFinding($result['findings'] ?? [])) {
            $labels[] = 'ai::security';
        }

        return $labels;
    }

    /**
     * Map a code review result to a GitLab commit status.
     *
     * @param  array<string, mixed>  $result
     * @return string 'success' or 'failed'
     */
    public function mapCommitStatus(array $result): string
    {
        return $this->hasCriticalFinding($result['findings'] ?? [])
            ? 'failed'
            : 'success';
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function hasSecurityFinding(array $findings): bool
    {
        return collect($findings)
            ->contains(fn (array $f) => ($f['category'] ?? '') === 'security');
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function hasCriticalFinding(array $findings): bool
    {
        return collect($findings)
            ->contains(fn (array $f) => ($f['severity'] ?? '') === 'critical');
    }
}
