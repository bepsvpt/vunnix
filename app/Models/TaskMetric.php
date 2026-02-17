<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $task_id
 * @property int $project_id
 * @property string $task_type
 * @property int $input_tokens
 * @property int $output_tokens
 * @property numeric $cost
 * @property int $duration
 * @property int $severity_critical
 * @property int $severity_high
 * @property int $severity_medium
 * @property int $severity_low
 * @property int $findings_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Project $project
 * @property-read \App\Models\Task $task
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskMetric newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskMetric newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskMetric query()
 *
 * @mixin \Eloquent
 */
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
