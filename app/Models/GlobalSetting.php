<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class GlobalSetting extends Model
{
    use HasFactory;

    private const CACHE_PREFIX = 'global_setting:';
    private const CACHE_TTL_MINUTES = 60;

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'bot_pat_created_at',
    ];

    /**
     * @return array{
     *   value: 'json',
     *   bot_pat_created_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'bot_pat_created_at' => 'datetime',
        ];
    }

    /**
     * Default PRD template used when no project or global override exists.
     * Matches the template structure from docs/spec/vunnix-v1.md §4.4.
     */
    public static function defaultPrdTemplate(): string
    {
        return <<<'TEMPLATE'
# [Feature Title]

## Problem
What problem does this solve? Who is affected?

## Proposed Solution
High-level description of the feature.

## User Stories
- As a [role], I want [action] so that [benefit]

## Acceptance Criteria
- [ ] Criterion 1
- [ ] Criterion 2

## Out of Scope
What this feature does NOT include.

## Technical Notes
Architecture considerations, dependencies, related existing code.

## Open Questions
Unresolved items from the conversation.
TEMPLATE;
    }

    /**
     * Default settings applied when no DB record exists for a key.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'ai_model' => 'opus',
            'ai_language' => 'en',
            'timeout_minutes' => 10,
            'max_tokens' => 8192,
            'ai_prices' => ['input' => 5.0, 'output' => 25.0],
            'team_chat_enabled' => false,
            'team_chat_webhook_url' => '',
            'team_chat_platform' => 'slack',
            'team_chat_categories' => [
                'task_completed' => true,
                'task_failed' => true,
                'alert' => true,
            ],
        ];
    }

    /**
     * Get a setting value by key with caching and type casting.
     * Falls back to defaults(), then to the provided $default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember(
            self::CACHE_PREFIX . $key,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($key) {
                $setting = static::where('key', $key)->first();

                if (! $setting) {
                    return null;
                }

                return [
                    'value' => $setting->value,
                    'type' => $setting->type,
                ];
            }
        );

        if ($value === null) {
            $defaults = static::defaults();

            return $defaults[$key] ?? $default;
        }

        return static::castValue($value['value'], $value['type']);
    }

    /**
     * Set a setting value, creating or updating as needed.
     * Invalidates the cache for this key.
     */
    public static function set(string $key, mixed $value, string $type = 'string', ?string $description = null): static
    {
        Cache::forget(self::CACHE_PREFIX . $key);

        $attributes = ['value' => $value, 'type' => $type];
        if ($description !== null) {
            $attributes['description'] = $description;
        }

        return static::updateOrCreate(['key' => $key], $attributes);
    }

    /**
     * Cast a raw value based on its type hint.
     */
    protected static function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'json' => is_array($value) ? $value : json_decode($value, true),
            default => (string) $value,
        };
    }

    /**
     * Boot the model — register cache invalidation on save and delete.
     */
    protected static function booted(): void
    {
        static::saved(function (GlobalSetting $setting) {
            Cache::forget(self::CACHE_PREFIX . $setting->key);
        });

        static::deleted(function (GlobalSetting $setting) {
            Cache::forget(self::CACHE_PREFIX . $setting->key);
        });
    }
}
