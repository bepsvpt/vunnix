<?php

namespace App\Services;

use App\Models\CostAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CostAlertService
{
    /**
     * Evaluate all aggregate alert rules (monthly, daily, projection).
     * Does NOT include single-task outlier — that runs per-task.
     *
     * @return CostAlert[]
     */
    public function evaluateAll(?Carbon $now = null): array
    {
        $now ??= now();
        $alerts = [];

        if (($alert = $this->evaluateMonthlyAnomaly($now)) instanceof \App\Models\CostAlert) {
            $alerts[] = $alert;
        }
        if (($alert = $this->evaluateDailySpike($now)) instanceof \App\Models\CostAlert) {
            $alerts[] = $alert;
        }
        if (($alert = $this->evaluateApproachingProjection($now)) instanceof \App\Models\CostAlert) {
            $alerts[] = $alert;
        }

        return $alerts;
    }

    /**
     * Rule 1: Monthly anomaly — current month spend > 2× rolling 3-month average.
     */
    public function evaluateMonthlyAnomaly(?Carbon $now = null): ?CostAlert
    {
        $now ??= now();

        if ($this->isDuplicateToday('monthly_anomaly', $now)) {
            return null;
        }

        $currentMonthStart = $now->copy()->startOfMonth();
        $currentMonthSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $currentMonthStart)
            ->sum('cost');

        // Rolling 3-month average (excluding current month)
        $threeMonthsAgo = $currentMonthStart->copy()->subMonths(3);
        $historicalSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $threeMonthsAgo)
            ->where('created_at', '<', $currentMonthStart)
            ->sum('cost');

        $monthsWithData = DB::table('task_metrics')
            ->where('created_at', '>=', $threeMonthsAgo)
            ->where('created_at', '<', $currentMonthStart)
            ->selectRaw('COUNT(DISTINCT '.$this->monthExpression().') as months')
            ->value('months');

        if ($monthsWithData < 1) {
            return null; // Not enough history
        }

        $avgMonthly = $historicalSpend / $monthsWithData;
        $threshold = $avgMonthly * 2;

        if ($currentMonthSpend <= $threshold) {
            return null;
        }

        return CostAlert::create([
            'rule' => 'monthly_anomaly',
            'severity' => 'critical',
            'message' => sprintf(
                'Monthly spend ($%.2f) exceeds 2× the rolling 3-month average ($%.2f).',
                $currentMonthSpend,
                $avgMonthly,
            ),
            'context' => [
                'current_spend' => $currentMonthSpend,
                'avg_monthly' => $avgMonthly,
                'threshold' => $threshold,
                'period' => $now->format('Y-m'),
            ],
        ]);
    }

    /**
     * Rule 2: Daily spike — today's spend > 5× daily average.
     */
    public function evaluateDailySpike(?Carbon $now = null): ?CostAlert
    {
        $now ??= now();

        if ($this->isDuplicateToday('daily_spike', $now)) {
            return null;
        }

        $todayStart = $now->copy()->startOfDay();
        $todaySpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $todayStart)
            ->sum('cost');

        // Average daily spend over last 30 days (excluding today)
        $thirtyDaysAgo = $todayStart->copy()->subDays(30);
        $historicalSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where('created_at', '<', $todayStart)
            ->sum('cost');

        $daysWithData = DB::table('task_metrics')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where('created_at', '<', $todayStart)
            ->selectRaw('COUNT(DISTINCT '.$this->dateExpression().') as days')
            ->value('days');

        if ($daysWithData < 1) {
            return null; // Not enough history
        }

        $avgDaily = $historicalSpend / $daysWithData;
        $threshold = $avgDaily * 5;

        if ($todaySpend <= $threshold) {
            return null;
        }

        return CostAlert::create([
            'rule' => 'daily_spike',
            'severity' => 'critical',
            'message' => sprintf(
                'Daily spend ($%.2f) exceeds 5× the daily average ($%.2f).',
                $todaySpend,
                $avgDaily,
            ),
            'context' => [
                'today_spend' => $todaySpend,
                'avg_daily' => $avgDaily,
                'threshold' => $threshold,
                'date' => $now->toDateString(),
            ],
        ]);
    }

    /**
     * Rule 3: Single task outlier — task cost > 3× average for its type.
     * Called per-task from TaskObserver, not from evaluateAll().
     */
    public function evaluateSingleTaskOutlier(int $taskId, string $taskType, float $taskCost, ?Carbon $now = null): ?CostAlert
    {
        $now ??= now();

        $avgCostForType = (float) DB::table('task_metrics')
            ->where('task_type', $taskType)
            ->where('task_id', '!=', $taskId)
            ->avg('cost');

        if ($avgCostForType <= 0) {
            return null; // No history for this type
        }

        $threshold = $avgCostForType * 3;

        if ($taskCost <= $threshold) {
            return null;
        }

        return CostAlert::create([
            'rule' => 'single_task_outlier',
            'severity' => 'warning',
            'message' => sprintf(
                'Task #%d (%s) cost $%.4f exceeds 3× the type average ($%.4f).',
                $taskId,
                $taskType,
                $taskCost,
                $avgCostForType,
            ),
            'context' => [
                'task_id' => $taskId,
                'task_type' => $taskType,
                'task_cost' => $taskCost,
                'avg_cost_for_type' => $avgCostForType,
                'threshold' => $threshold,
            ],
        ]);
    }

    /**
     * Rule 4: Approaching projection — projected month-end > 2× last month.
     */
    public function evaluateApproachingProjection(?Carbon $now = null): ?CostAlert
    {
        $now ??= now();

        if ($this->isDuplicateToday('approaching_projection', $now)) {
            return null;
        }

        $currentMonthStart = $now->copy()->startOfMonth();
        $daysElapsed = $now->day;
        $daysInMonth = $now->daysInMonth;

        if ($daysElapsed < 3) {
            return null; // Too early to project meaningfully
        }

        $currentSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $currentMonthStart)
            ->sum('cost');

        $projectedSpend = ($currentSpend / $daysElapsed) * $daysInMonth;

        // Last month's total
        $lastMonthStart = $currentMonthStart->copy()->subMonth();
        $lastMonthSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->sum('cost');

        if ($lastMonthSpend <= 0) {
            return null; // No last month data to compare
        }

        $threshold = $lastMonthSpend * 2;

        if ($projectedSpend <= $threshold) {
            return null;
        }

        return CostAlert::create([
            'rule' => 'approaching_projection',
            'severity' => 'warning',
            'message' => sprintf(
                'Projected month-end spend ($%.2f) exceeds 2× last month ($%.2f).',
                $projectedSpend,
                $lastMonthSpend,
            ),
            'context' => [
                'projected_spend' => round($projectedSpend, 6),
                'last_month_spend' => $lastMonthSpend,
                'threshold' => $threshold,
                'days_elapsed' => $daysElapsed,
                'days_in_month' => $daysInMonth,
                'period' => $now->format('Y-m'),
            ],
        ]);
    }

    /**
     * Check if an alert of the same rule was already created today (dedup).
     */
    private function isDuplicateToday(string $rule, Carbon $now): bool
    {
        return CostAlert::where('rule', $rule)
            ->where('acknowledged', false)
            ->where('created_at', '>=', $now->copy()->startOfDay())
            ->exists();
    }

    /**
     * SQL expression for extracting month from created_at (SQLite/PG compatible).
     */
    private function monthExpression(): string
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "TO_CHAR(created_at, 'YYYY-MM')";
    }

    /**
     * SQL expression for extracting date from created_at (SQLite/PG compatible).
     */
    private function dateExpression(): string
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'sqlite'
            ? 'date(created_at)'
            : 'DATE(created_at)';
    }
}
