<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
