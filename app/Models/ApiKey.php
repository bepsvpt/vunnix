<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            ->where(function (Builder $q) {
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
}
