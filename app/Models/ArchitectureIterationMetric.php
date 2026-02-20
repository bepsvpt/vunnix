<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $snapshot_date
 * @property int $module_touch_breadth
 * @property float|null $median_files_changed
 * @property float|null $fast_lane_minutes_p50
 * @property int $reopened_regressions_count
 * @property float|null $lead_time_hours_p50
 * @property array<array-key, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ArchitectureIterationMetric query()
 *
 * @mixin \Eloquent
 */
class ArchitectureIterationMetric extends Model
{
    protected $fillable = [
        'snapshot_date',
        'module_touch_breadth',
        'median_files_changed',
        'fast_lane_minutes_p50',
        'reopened_regressions_count',
        'lead_time_hours_p50',
        'metadata',
    ];

    /**
     * @return array{
     *   snapshot_date: 'date',
     *   module_touch_breadth: 'integer',
     *   median_files_changed: 'decimal:2',
     *   fast_lane_minutes_p50: 'decimal:2',
     *   reopened_regressions_count: 'integer',
     *   lead_time_hours_p50: 'decimal:2',
     *   metadata: 'array',
     * }
     */
    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'module_touch_breadth' => 'integer',
            'median_files_changed' => 'decimal:2',
            'fast_lane_minutes_p50' => 'decimal:2',
            'reopened_regressions_count' => 'integer',
            'lead_time_hours_p50' => 'decimal:2',
            'metadata' => 'array',
        ];
    }
}
