<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $type
 * @property string|null $category
 * @property array<array-key, mixed> $content
 * @property int $confidence
 * @property int|null $source_task_id
 * @property array<array-key, mixed>|null $source_meta
 * @property int $applied_count
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Project $project
 * @property-read \App\Models\Task|null $sourceTask
 *
 * @method static Builder<static>|MemoryEntry active()
 * @method static \Database\Factories\MemoryEntryFactory factory($count = null, $state = [])
 * @method static Builder<static>|MemoryEntry forProject(int $projectId)
 * @method static Builder<static>|MemoryEntry highConfidence(int $min = 40)
 * @method static Builder<static>|MemoryEntry newModelQuery()
 * @method static Builder<static>|MemoryEntry newQuery()
 * @method static Builder<static>|MemoryEntry ofType(string $type)
 * @method static Builder<static>|MemoryEntry query()
 *
 * @mixin \Eloquent
 */
class MemoryEntry extends Model
{
    /** @use HasFactory<\Database\Factories\MemoryEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'type',
        'category',
        'content',
        'confidence',
        'source_task_id',
        'source_meta',
        'applied_count',
        'archived_at',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function sourceTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'source_task_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
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
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHighConfidence(Builder $query, int $min = 40): Builder
    {
        return $query->where('confidence', '>=', $min);
    }

    /**
     * @return array{
     *   content: 'array',
     *   source_meta: 'array',
     *   confidence: 'integer',
     *   applied_count: 'integer',
     *   archived_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'source_meta' => 'array',
            'confidence' => 'integer',
            'applied_count' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
