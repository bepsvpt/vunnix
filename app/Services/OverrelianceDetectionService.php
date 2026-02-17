<?php

namespace App\Services;

use App\Models\OverrelianceAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OverrelianceDetectionService
{
    private const ACCEPTANCE_RATE_THRESHOLD = 95.0;

    private const CRITICAL_ACCEPTANCE_THRESHOLD = 99.0;

    private const BULK_RESOLUTION_RATIO_THRESHOLD = 50.0; // >50% bulk-resolved

    private const MIN_FINDINGS_PER_WEEK = 5;

    private const MIN_FINDINGS_FOR_REACTIONS = 20;

    private const WEEKS_REQUIRED = 2;

    /**
     * Evaluate all over-reliance detection rules.
     *
     * @return OverrelianceAlert[]
     */
    public function evaluateAll(?Carbon $now = null): array
    {
        $now ??= now();
        $alerts = [];

        if ($alert = $this->evaluateHighAcceptanceRate($now)) {
            $alerts[] = $alert;
        }
        if ($alert = $this->evaluateCriticalAcceptanceRate($now)) {
            $alerts[] = $alert;
        }
        if ($alert = $this->evaluateBulkResolution($now)) {
            $alerts[] = $alert;
        }
        if ($alert = $this->evaluateZeroReactions($now)) {
            $alerts[] = $alert;
        }

        return $alerts;
    }

    /**
     * Rule 1: Overall acceptance rate >95% for 2+ consecutive weeks.
     */
    public function evaluateHighAcceptanceRate(?Carbon $now = null): ?OverrelianceAlert
    {
        $now ??= now();

        if ($this->isDuplicateThisWeek('high_acceptance_rate', $now)) {
            return null;
        }

        $consecutiveWeeks = 0;
        $weeklyRates = [];

        for ($w = 1; $w <= self::WEEKS_REQUIRED; $w++) {
            $weekStart = $now->copy()->subWeeks($w)->startOfWeek();
            $weekEnd = $weekStart->copy()->endOfWeek();

            $total = DB::table('finding_acceptances')
                ->where('created_at', '>=', $weekStart)
                ->where('created_at', '<=', $weekEnd)
                ->count();

            if ($total < self::MIN_FINDINGS_PER_WEEK) {
                return null; // Not enough data for this week
            }

            $accepted = DB::table('finding_acceptances')
                ->where('created_at', '>=', $weekStart)
                ->where('created_at', '<=', $weekEnd)
                ->whereIn('status', ['accepted', 'accepted_auto'])
                ->count();

            $rate = ($accepted / $total) * 100;
            $weeklyRates[] = ['week' => $weekStart->toDateString(), 'rate' => round($rate, 1), 'total' => $total];

            if ($rate > self::ACCEPTANCE_RATE_THRESHOLD) {
                $consecutiveWeeks++;
            } else {
                return null; // Chain broken
            }
        }

        if ($consecutiveWeeks < self::WEEKS_REQUIRED) {
            return null;
        }

        $overallRate = collect($weeklyRates)->avg('rate');

        return OverrelianceAlert::create([
            'rule' => 'high_acceptance_rate',
            'severity' => 'warning',
            'message' => sprintf(
                'Acceptance rate has been above %.0f%% for %d consecutive weeks (avg: %.1f%%). Engineers may be rubber-stamping reviews.',
                self::ACCEPTANCE_RATE_THRESHOLD,
                $consecutiveWeeks,
                $overallRate,
            ),
            'context' => [
                'weekly_rates' => $weeklyRates,
                'consecutive_weeks' => $consecutiveWeeks,
                'threshold' => self::ACCEPTANCE_RATE_THRESHOLD,
            ],
        ]);
    }

    /**
     * Rule 2: Critical finding acceptance >99% — almost nothing disputed.
     */
    public function evaluateCriticalAcceptanceRate(?Carbon $now = null): ?OverrelianceAlert
    {
        $now ??= now();

        if ($this->isDuplicateThisWeek('critical_acceptance_rate', $now)) {
            return null;
        }

        $lookback = $now->copy()->subDays(30);

        $total = DB::table('finding_acceptances')
            ->where('severity', 'critical')
            ->where('created_at', '>=', $lookback)
            ->count();

        if ($total < self::MIN_FINDINGS_FOR_REACTIONS) {
            return null; // Not enough critical findings
        }

        $accepted = DB::table('finding_acceptances')
            ->where('severity', 'critical')
            ->where('created_at', '>=', $lookback)
            ->whereIn('status', ['accepted', 'accepted_auto'])
            ->count();

        $rate = ($accepted / $total) * 100;

        if ($rate <= self::CRITICAL_ACCEPTANCE_THRESHOLD) {
            return null;
        }

        return OverrelianceAlert::create([
            'rule' => 'critical_acceptance_rate',
            'severity' => 'warning',
            'message' => sprintf(
                'Critical finding acceptance rate is %.1f%% (last 30 days). Engineers may not be scrutinizing critical findings.',
                $rate,
            ),
            'context' => [
                'acceptance_rate' => round($rate, 1),
                'total_critical' => $total,
                'accepted_critical' => $accepted,
                'threshold' => self::CRITICAL_ACCEPTANCE_THRESHOLD,
                'lookback_days' => 30,
            ],
        ]);
    }

    /**
     * Rule 3: Bulk resolution pattern — many threads resolved within seconds.
     */
    public function evaluateBulkResolution(?Carbon $now = null): ?OverrelianceAlert
    {
        $now ??= now();

        if ($this->isDuplicateThisWeek('bulk_resolution', $now)) {
            return null;
        }

        $lookback = $now->copy()->subDays(14);

        $total = DB::table('finding_acceptances')
            ->where('created_at', '>=', $lookback)
            ->count();

        if ($total < self::MIN_FINDINGS_PER_WEEK) {
            return null;
        }

        $bulkCount = DB::table('finding_acceptances')
            ->where('created_at', '>=', $lookback)
            ->where('bulk_resolved', true)
            ->count();

        $ratio = ($bulkCount / $total) * 100;

        if ($ratio <= self::BULK_RESOLUTION_RATIO_THRESHOLD) {
            return null;
        }

        return OverrelianceAlert::create([
            'rule' => 'bulk_resolution',
            'severity' => 'warning',
            'message' => sprintf(
                '%.0f%% of findings (%d/%d) were bulk-resolved in the last 14 days. Possible "resolve all" behavior.',
                $ratio,
                $bulkCount,
                $total,
            ),
            'context' => [
                'bulk_count' => $bulkCount,
                'total_findings' => $total,
                'ratio' => round($ratio, 1),
                'threshold' => self::BULK_RESOLUTION_RATIO_THRESHOLD,
                'lookback_days' => 14,
            ],
        ]);
    }

    /**
     * Rule 4: Zero negative reactions across many reviews.
     */
    public function evaluateZeroReactions(?Carbon $now = null): ?OverrelianceAlert
    {
        $now ??= now();

        if ($this->isDuplicateThisWeek('zero_reactions', $now)) {
            return null;
        }

        $lookback = $now->copy()->subDays(30);

        $total = DB::table('finding_acceptances')
            ->where('created_at', '>=', $lookback)
            ->count();

        if ($total < self::MIN_FINDINGS_FOR_REACTIONS) {
            return null;
        }

        $negativeCount = (int) DB::table('finding_acceptances')
            ->where('created_at', '>=', $lookback)
            ->sum('emoji_negative_count');

        if ($negativeCount > 0) {
            return null;
        }

        return OverrelianceAlert::create([
            'rule' => 'zero_reactions',
            'severity' => 'info',
            'message' => sprintf(
                'Zero negative (👎) reactions across %d findings in the last 30 days. Feedback mechanism may not be used.',
                $total,
            ),
            'context' => [
                'total_findings' => $total,
                'negative_count' => 0,
                'lookback_days' => 30,
            ],
        ]);
    }

    /**
     * Check if an alert of the same rule was already created this week (dedup).
     * Over-reliance rules run weekly, so dedup window is 7 days.
     */
    private function isDuplicateThisWeek(string $rule, Carbon $now): bool
    {
        return OverrelianceAlert::where('rule', $rule)
            ->where('acknowledged', false)
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->exists();
    }
}
