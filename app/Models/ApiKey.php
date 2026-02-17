<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property string|null $last_ip
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property bool $revoked
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 *
 * @method static Builder<static>|ApiKey active()
 * @method static \Database\Factories\ApiKeyFactory factory($count = null, $state = [])
 * @method static Builder<static>|ApiKey newModelQuery()
 * @method static Builder<static>|ApiKey newQuery()
 * @method static Builder<static>|ApiKey query()
 *
 * @mixin \Eloquent
 */
class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'key',
        'last_used_at',
        'last_ip',
        'expires_at',
        'revoked',
        'revoked_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('revoked', false)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function recordUsage(string $ip): void
    {
        $this->update([
            'last_used_at' => now(),
            'last_ip' => $ip,
        ]);
    }

    /**
     * @return array{
     *   last_used_at: 'datetime',
     *   expires_at: 'datetime',
     *   revoked: 'boolean',
     *   revoked_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }
}
