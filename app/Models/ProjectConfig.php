<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'webhook_token_validation' => 'boolean',
            'ci_trigger_token' => 'encrypted',
            'settings' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
