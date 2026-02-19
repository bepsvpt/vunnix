<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $dimension
 * @property float $score
 * @property array<array-key, mixed> $details
 * @property string|null $source_ref
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read \App\Models\Project $project
 *
 * @method static Builder<static>|HealthSnapshot forProject(int $projectId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HealthSnapshot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HealthSnapshot newQuery()
 * @method static Builder<static>|HealthSnapshot ofDimension(string $dimension)
 * @method static Builder<static>|HealthSnapshot query()
 * @method static Builder<static>|HealthSnapshot recent(int $days = 30)
 *
 * @mixin \Eloquent
 */
class HealthSnapshot extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'dimension',
        'score',
        'details',
        'source_ref',
        'created_at',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfDimension(Builder $query, string $dimension): Builder
    {
        return $query->where('dimension', $dimension);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * @return array{
     *   details: 'array',
     *   score: 'float',
     *   created_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'score' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
