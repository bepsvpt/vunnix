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
 *
 * @property int $id
 * @property string $gitlab_event_uuid
 * @property int $project_id
 * @property string $event_type
 * @property string|null $intent
 * @property int|null $mr_iid
 * @property string|null $commit_sha
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Models\Project $project
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEventLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEventLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEventLog query()
 *
 * @mixin \Eloquent
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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

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
}
