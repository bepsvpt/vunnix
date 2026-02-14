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

];
