<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Logs every processed webhook event by its GitLab-assigned UUID.
 *
 * Used for deduplication: if X-Gitlab-Event-UUID already exists in this
 * table, the webhook is a replay/retry and should be rejected.
 *
 * Also stores MR IID and commit SHA to support latest-wins superseding
 * lookups (D140).
 */
class WebhookEventLog extends Model
{
    public $timestamps = false;

    protected $table = 'webhook_events';

    protected $fillable = [
        'gitlab_event_uuid',
        'project_id',
        'event_type',
        'intent',
        'mr_iid',
        'commit_sha',
    ];

    /**
     * @return array{
     *   mr_iid: 'integer',
     *   created_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'mr_iid' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
