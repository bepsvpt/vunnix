<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MetricsQueryService
{
    /**
     * Query aggregated metrics by project, filtering to allowed project IDs.
     *
     * @param  Collection<int, int>  $projectIds
     * @return Collection<int, object>
     */
    public function byProject(Collection $projectIds): Collection
    {
        if ($projectIds->isEmpty()) {
            return collect();
        }

        if ($this->hasMaterializedViews()) {
            return DB::table('mv_metrics_by_project')
                ->whereIn('project_id', $projectIds)
                ->get();
        }

        return DB::table('task_metrics')
            ->whereIn('project_id', $projectIds)
            ->select(
                'project_id',
                DB::raw('COUNT(*) AS task_count'),
                DB::raw('SUM(input_tokens) AS total_input_tokens'),
                DB::raw('SUM(output_tokens) AS total_output_tokens'),
                DB::raw('SUM(input_tokens + output_tokens) AS total_tokens'),
                DB::raw('SUM(cost) AS total_cost'),
                DB::raw('AVG(cost) AS avg_cost'),
                DB::raw('AVG(duration) AS avg_duration'),
                DB::raw('SUM(severity_critical) AS total_severity_critical'),
                DB::raw('SUM(severity_high) AS total_severity_high'),
                DB::raw('SUM(severity_medium) AS total_severity_medium'),
                DB::raw('SUM(severity_low) AS total_severity_low'),
                DB::raw('SUM(findings_count) AS total_findings'),
            )
            ->groupBy('project_id')
            ->get();
    }

    /**
     * Query aggregated metrics by task type, filtered to allowed projects.
     *
     * @param  Collection<int, int>  $projectIds
     * @return Collection<int, object>
     */
    public function byType(Collection $projectIds): Collection
    {
        if ($projectIds->isEmpty()) {
            return collect();
        }

        if ($this->hasMaterializedViews()) {
            return DB::table('mv_metrics_by_type')
                ->whereIn('project_id', $projectIds)
                ->get();
        }

        return DB::table('task_metrics')
            ->whereIn('project_id', $projectIds)
            ->select(
                'project_id',
                'task_type',
                DB::raw('COUNT(*) AS task_count'),
                DB::raw('SUM(input_tokens) AS total_input_tokens'),
                DB::raw('SUM(output_tokens) AS total_output_tokens'),
                DB::raw('SUM(input_tokens + output_tokens) AS total_tokens'),
                DB::raw('SUM(cost) AS total_cost'),
                DB::raw('AVG(cost) AS avg_cost'),
                DB::raw('AVG(duration) AS avg_duration'),
                DB::raw('SUM(severity_critical) AS total_severity_critical'),
                DB::raw('SUM(severity_high) AS total_severity_high'),
                DB::raw('SUM(severity_medium) AS total_severity_medium'),
                DB::raw('SUM(severity_low) AS total_severity_low'),
                DB::raw('SUM(findings_count) AS total_findings'),
            )
            ->groupBy('project_id', 'task_type')
            ->get();
    }

    /**
     * Query aggregated metrics by time period (month), filtered to allowed projects.
     *
     * @param  Collection<int, int>  $projectIds
     * @param  int  $months  Number of months to look back
     * @return Collection<int, object>
     */
    public function byPeriod(Collection $projectIds, int $months = 12): Collection
    {
        if ($projectIds->isEmpty()) {
            return collect();
        }

        if ($this->hasMaterializedViews()) {
            return DB::table('mv_metrics_by_period')
                ->whereIn('project_id', $projectIds)
                ->where('period_month', '>=', now()->subMonths($months)->format('Y-m'))
                ->orderBy('period_month')
                ->get();
        }

        $driver = DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "TO_CHAR(created_at, 'YYYY-MM')";

        return DB::table('task_metrics')
            ->whereIn('project_id', $projectIds)
            ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
            ->select(
                'project_id',
                'task_type',
                DB::raw("{$monthExpr} AS period_month"),
                DB::raw('COUNT(*) AS task_count'),
                DB::raw('SUM(input_tokens) AS total_input_tokens'),
                DB::raw('SUM(output_tokens) AS total_output_tokens'),
                DB::raw('SUM(input_tokens + output_tokens) AS total_tokens'),
                DB::raw('SUM(cost) AS total_cost'),
                DB::raw('AVG(cost) AS avg_cost'),
                DB::raw('AVG(duration) AS avg_duration'),
                DB::raw('SUM(severity_critical) AS total_severity_critical'),
                DB::raw('SUM(severity_high) AS total_severity_high'),
                DB::raw('SUM(severity_medium) AS total_severity_medium'),
                DB::raw('SUM(severity_low) AS total_severity_low'),
                DB::raw('SUM(findings_count) AS total_findings'),
            )
            ->groupBy('project_id', 'task_type', DB::raw($monthExpr))
            ->orderBy(DB::raw($monthExpr))
            ->get();
    }

    /**
     * Check if PostgreSQL materialized views are available.
     */
    public function hasMaterializedViews(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
