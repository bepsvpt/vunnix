<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $task_id
 * @property int $project_id
 * @property int $mr_iid
 * @property string $finding_id
 * @property string $file
 * @property int $line
 * @property string $severity
 * @property string $title
 * @property string|null $gitlab_discussion_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property bool $code_change_correlated
 * @property string|null $correlated_commit_sha
 * @property bool $bulk_resolved
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $emoji_positive_count
 * @property int $emoji_negative_count
 * @property string $emoji_sentiment
 * @property string|null $category
 * @property-read \App\Models\Project $project
 * @property-read \App\Models\Task $task
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FindingAcceptance newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FindingAcceptance newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FindingAcceptance query()
 *
 * @mixin \Eloquent
 */
class FindingAcceptance extends Model
{
    protected $fillable = [
        'task_id',
        'project_id',
        'mr_iid',
        'finding_id',
        'file',
        'line',
        'severity',
        'title',
        'category',
        'gitlab_discussion_id',
        'status',
        'resolved_at',
        'code_change_correlated',
        'correlated_commit_sha',
        'bulk_resolved',
        'emoji_positive_count',
        'emoji_negative_count',
        'emoji_sentiment',
    ];

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

    /**
     * @return array{
     *   line: 'integer',
     *   emoji_positive_count: 'integer',
     *   emoji_negative_count: 'integer',
     *   code_change_correlated: 'boolean',
     *   bulk_resolved: 'boolean',
     *   resolved_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'emoji_positive_count' => 'integer',
            'emoji_negative_count' => 'integer',
            'code_change_correlated' => 'boolean',
            'bulk_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }
}
