<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string|null $webhook_secret
 * @property bool $webhook_token_validation
 * @property array<array-key, mixed> $settings
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $ci_trigger_token
 * @property-read \App\Models\Project $project
 *
 * @method static \Database\Factories\ProjectConfigFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectConfig newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectConfig newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectConfig query()
 *
 * @mixin \Eloquent
 */
class ProjectConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'webhook_secret',
        'webhook_token_validation',
        'ci_trigger_token',
        'settings',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array{
     *   webhook_secret: 'encrypted',
     *   webhook_token_validation: 'boolean',
     *   ci_trigger_token: 'encrypted',
     *   settings: 'array',
     * }
     */
    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'webhook_token_validation' => 'boolean',
            'ci_trigger_token' => 'encrypted',
            'settings' => 'array',
        ];
    }
}
