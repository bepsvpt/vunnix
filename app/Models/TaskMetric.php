<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskMetric extends Model
{
    protected $fillable = [
        'task_id',
        'project_id',
        'task_type',
        'input_tokens',
        'output_tokens',
        'cost',
        'duration',
        'severity_critical',
        'severity_high',
        'severity_medium',
        'severity_low',
        'findings_count',
    ];

    /**
     * @return array{
     *   input_tokens: 'integer',
     *   output_tokens: 'integer',
     *   cost: 'decimal:6',
     *   duration: 'integer',
     *   severity_critical: 'integer',
     *   severity_high: 'integer',
     *   severity_medium: 'integer',
     *   severity_low: 'integer',
     *   findings_count: 'integer',
     * }
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'decimal:6',
            'duration' => 'integer',
            'severity_critical' => 'integer',
            'severity_high' => 'integer',
            'severity_medium' => 'integer',
            'severity_low' => 'integer',
            'findings_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
