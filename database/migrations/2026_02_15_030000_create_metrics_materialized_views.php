<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // View 1: Aggregated metrics by project
        DB::statement('
            CREATE MATERIALIZED VIEW mv_metrics_by_project AS
            SELECT
                project_id,
                COUNT(*) AS task_count,
                SUM(input_tokens) AS total_input_tokens,
                SUM(output_tokens) AS total_output_tokens,
                SUM(input_tokens + output_tokens) AS total_tokens,
                SUM(cost) AS total_cost,
                AVG(cost) AS avg_cost,
                AVG(duration) AS avg_duration,
                SUM(severity_critical) AS total_severity_critical,
                SUM(severity_high) AS total_severity_high,
                SUM(severity_medium) AS total_severity_medium,
                SUM(severity_low) AS total_severity_low,
                SUM(findings_count) AS total_findings
            FROM task_metrics
            GROUP BY project_id
        ');

        DB::statement('CREATE UNIQUE INDEX mv_metrics_by_project_idx ON mv_metrics_by_project (project_id)');

        // View 2: Aggregated metrics by task type (also scoped by project for filtering)
        DB::statement('
            CREATE MATERIALIZED VIEW mv_metrics_by_type AS
            SELECT
                project_id,
                task_type,
                COUNT(*) AS task_count,
                SUM(input_tokens) AS total_input_tokens,
                SUM(output_tokens) AS total_output_tokens,
                SUM(input_tokens + output_tokens) AS total_tokens,
                SUM(cost) AS total_cost,
                AVG(cost) AS avg_cost,
                AVG(duration) AS avg_duration,
                SUM(severity_critical) AS total_severity_critical,
                SUM(severity_high) AS total_severity_high,
                SUM(severity_medium) AS total_severity_medium,
                SUM(severity_low) AS total_severity_low,
                SUM(findings_count) AS total_findings
            FROM task_metrics
            GROUP BY project_id, task_type
        ');

        DB::statement('CREATE UNIQUE INDEX mv_metrics_by_type_idx ON mv_metrics_by_type (project_id, task_type)');

        // View 3: Aggregated metrics by time period (month, scoped by project and type)
        DB::statement("
            CREATE MATERIALIZED VIEW mv_metrics_by_period AS
            SELECT
                project_id,
                task_type,
                TO_CHAR(created_at, 'YYYY-MM') AS period_month,
                COUNT(*) AS task_count,
                SUM(input_tokens) AS total_input_tokens,
                SUM(output_tokens) AS total_output_tokens,
                SUM(input_tokens + output_tokens) AS total_tokens,
                SUM(cost) AS total_cost,
                AVG(cost) AS avg_cost,
                AVG(duration) AS avg_duration,
                SUM(severity_critical) AS total_severity_critical,
                SUM(severity_high) AS total_severity_high,
                SUM(severity_medium) AS total_severity_medium,
                SUM(severity_low) AS total_severity_low,
                SUM(findings_count) AS total_findings
            FROM task_metrics
            GROUP BY project_id, task_type, TO_CHAR(created_at, 'YYYY-MM')
        ");

        DB::statement('CREATE UNIQUE INDEX mv_metrics_by_period_idx ON mv_metrics_by_period (project_id, task_type, period_month)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_metrics_by_period');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_metrics_by_type');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_metrics_by_project');
    }
};
