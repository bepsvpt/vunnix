<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task Budget (D127)
    |--------------------------------------------------------------------------
    |
    | Total scheduling + execution budget in minutes. This is the maximum time
    | from dispatch to result, including runner scheduling delays. Task-scoped
    | bearer tokens use this as their TTL.
    |
    | Default: 60 minutes (§19.3 Job Timeout & Retry Policy)
    |
    */

    'task_budget_minutes' => (int) env('VUNNIX_TASK_BUDGET_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | API URL
    |--------------------------------------------------------------------------
    |
    | The publicly-accessible URL of this Vunnix instance. Used to construct
    | the result callback URL passed to the executor as a pipeline variable.
    |
    */

    'api_url' => env('VUNNIX_API_URL', env('APP_URL', 'http://localhost:8000')),

    /*
    |--------------------------------------------------------------------------
    | Orchestration Migration Flags (ext-019)
    |--------------------------------------------------------------------------
    |
    | Feature gates for the orchestration kernel and outbox migration.
    | - kernel_enabled: enable registry-driven routing/dispatch selection.
    | - outbox_enabled: enable outbox delivery worker path.
    | - outbox_shadow_mode: write outbox events while still executing the
    |   existing direct-dispatch path (dual-path migration mode).
    |
    */

    'orchestration' => [
        'kernel_enabled' => (bool) env('VUNNIX_ORCHESTRATION_KERNEL_ENABLED', true),
    ],

    'events' => [
        'outbox_enabled' => (bool) env('VUNNIX_EVENTS_OUTBOX_ENABLED', false),
        'outbox_shadow_mode' => (bool) env('VUNNIX_EVENTS_OUTBOX_SHADOW_MODE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Memory (D195-D200)
    |--------------------------------------------------------------------------
    |
    | Learned per-project guidance extracted from review outcomes and
    | conversation summaries. Feature-flagged per sub-capability for safe
    | rollout and quick disablement.
    |
    */

    'memory' => [
        'enabled' => (bool) env('VUNNIX_MEMORY_ENABLED', true),
        'review_learning' => (bool) env('VUNNIX_MEMORY_REVIEW_LEARNING', true),
        'conversation_continuity' => (bool) env('VUNNIX_MEMORY_CONVERSATION_CONTINUITY', true),
        'cross_mr_patterns' => (bool) env('VUNNIX_MEMORY_CROSS_MR_PATTERNS', true),
        'retention_days' => (int) env('VUNNIX_MEMORY_RETENTION_DAYS', 90),
        'max_context_tokens' => (int) env('VUNNIX_MEMORY_MAX_CONTEXT_TOKENS', 2000),
        'min_confidence' => (int) env('VUNNIX_MEMORY_MIN_CONFIDENCE', 40),
        'min_sample_size' => (int) env('VUNNIX_MEMORY_MIN_SAMPLE_SIZE', 20),
    ],

];
