<?php

return [
    'enabled' => (bool) env('VUNNIX_HEALTH_ENABLED', true),

    'coverage_tracking' => (bool) env('VUNNIX_HEALTH_COVERAGE', true),

    'dependency_scanning' => (bool) env('VUNNIX_HEALTH_DEPENDENCIES', true),

    'complexity_tracking' => (bool) env('VUNNIX_HEALTH_COMPLEXITY', true),

    'analysis_directories' => ['app/', 'resources/js/'],

    'max_file_reads' => (int) env('VUNNIX_HEALTH_MAX_FILE_READS', 20),

    'snapshot_retention_days' => (int) env('VUNNIX_HEALTH_RETENTION_DAYS', 180),

    'thresholds' => [
        'coverage' => [
            'warning' => (float) env('VUNNIX_HEALTH_COVERAGE_WARNING', 70),
            'critical' => (float) env('VUNNIX_HEALTH_COVERAGE_CRITICAL', 50),
        ],
        'dependency' => [
            'warning' => (int) env('VUNNIX_HEALTH_DEPENDENCY_WARNING', 1),
            'critical' => (int) env('VUNNIX_HEALTH_DEPENDENCY_CRITICAL', 3),
        ],
        'complexity' => [
            'warning' => (float) env('VUNNIX_HEALTH_COMPLEXITY_WARNING', 50),
            'critical' => (float) env('VUNNIX_HEALTH_COMPLEXITY_CRITICAL', 30),
        ],
    ],
];
