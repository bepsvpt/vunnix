<?php

use App\Models\GlobalSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// ── Basic CRUD ──────────────────────────────────────────────

it('creates a setting with key, value, and type', function (): void {
    $setting = GlobalSetting::create([
        'key' => 'ai_model',
        'value' => 'opus',
        'type' => 'string',
        'description' => 'Default AI model for all projects',
    ]);

    expect($setting->key)->toBe('ai_model')
        ->and($setting->value)->toBe('opus')
        ->and($setting->type)->toBe('string')
        ->and($setting->description)->toBe('Default AI model for all projects');
});

it('enforces unique keys', function (): void {
    GlobalSetting::create(['key' => 'ai_model', 'value' => 'opus', 'type' => 'string']);

    GlobalSetting::create(['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string']);
})->throws(\Illuminate\Database\QueryException::class);

// ── Static get() with caching ───────────────────────────────

it('retrieves a setting value by key', function (): void {
    GlobalSetting::create(['key' => 'ai_model', 'value' => 'opus', 'type' => 'string']);

    $value = GlobalSetting::get('ai_model');

    expect($value)->toBe('opus');
});

it('returns default when key does not exist', function (): void {
    $value = GlobalSetting::get('nonexistent', 'fallback');

    expect($value)->toBe('fallback');
});

it('returns null when key does not exist and no default given', function (): void {
    $value = GlobalSetting::get('nonexistent');

    expect($value)->toBeNull();
});

it('caches retrieved values', function (): void {
    GlobalSetting::create(['key' => 'ai_model', 'value' => 'opus', 'type' => 'string']);

    // First call — hits DB and caches
    GlobalSetting::get('ai_model');

    // Verify the cache key was populated
    expect(Cache::has('global_setting:ai_model'))->toBeTrue();

    // Remove the DB row via query builder (bypasses model events, so cache stays)
    \Illuminate\Support\Facades\DB::table('global_settings')->where('key', 'ai_model')->delete();

    // Second call should still return the cached value (not hit the now-empty DB)
    $value = GlobalSetting::get('ai_model');
    expect($value)->toBe('opus');
});

// ── Type casting ────────────────────────────────────────────

it('casts string type correctly', function (): void {
    GlobalSetting::create(['key' => 'ai_model', 'value' => 'opus', 'type' => 'string']);

    $value = GlobalSetting::get('ai_model');

    expect($value)->toBeString()->toBe('opus');
});

it('casts boolean type correctly for true', function (): void {
    GlobalSetting::create(['key' => 'auto_review', 'value' => true, 'type' => 'boolean']);

    $value = GlobalSetting::get('auto_review');

    expect($value)->toBeBool()->toBeTrue();
});

it('casts boolean type correctly for false', function (): void {
    GlobalSetting::create(['key' => 'auto_review', 'value' => false, 'type' => 'boolean']);

    $value = GlobalSetting::get('auto_review');

    expect($value)->toBeBool()->toBeFalse();
});

it('casts integer type correctly', function (): void {
    GlobalSetting::create(['key' => 'timeout_minutes', 'value' => 10, 'type' => 'integer']);

    $value = GlobalSetting::get('timeout_minutes');

    expect($value)->toBeInt()->toBe(10);
});

it('casts json type and returns array', function (): void {
    $jsonValue = ['severity_threshold' => 'major', 'max_retries' => 3];
    GlobalSetting::create(['key' => 'review_config', 'value' => $jsonValue, 'type' => 'json']);

    $value = GlobalSetting::get('review_config');

    expect($value)->toBeArray()
        ->and($value['severity_threshold'])->toBe('major')
        ->and($value['max_retries'])->toBe(3);
});

// ── Static set() with cache invalidation ────────────────────

it('sets a new setting value', function (): void {
    GlobalSetting::set('ai_model', 'opus', 'string');

    expect(GlobalSetting::get('ai_model'))->toBe('opus');
});

it('updates an existing setting value', function (): void {
    GlobalSetting::set('ai_model', 'opus', 'string');
    GlobalSetting::set('ai_model', 'sonnet', 'string');

    expect(GlobalSetting::get('ai_model'))->toBe('sonnet');
});

it('invalidates cache when setting is updated', function (): void {
    GlobalSetting::set('ai_model', 'opus', 'string');

    // Prime the cache
    GlobalSetting::get('ai_model');
    expect(Cache::has('global_setting:ai_model'))->toBeTrue();

    // Update should invalidate cache
    GlobalSetting::set('ai_model', 'sonnet', 'string');

    // After update, getting should return the new value
    expect(GlobalSetting::get('ai_model'))->toBe('sonnet');
});

it('invalidates cache when model is saved directly', function (): void {
    $setting = GlobalSetting::create(['key' => 'ai_model', 'value' => 'opus', 'type' => 'string']);

    // Prime the cache
    GlobalSetting::get('ai_model');
    expect(Cache::has('global_setting:ai_model'))->toBeTrue();

    // Direct model update
    $setting->value = 'sonnet';
    $setting->save();

    // Cache should be invalidated
    expect(Cache::has('global_setting:ai_model'))->toBeFalse();
});

it('invalidates cache when model is deleted', function (): void {
    $setting = GlobalSetting::create(['key' => 'ai_model', 'value' => 'opus', 'type' => 'string']);

    // Prime the cache
    GlobalSetting::get('ai_model');
    expect(Cache::has('global_setting:ai_model'))->toBeTrue();

    $setting->delete();

    // Cache should be invalidated
    expect(Cache::has('global_setting:ai_model'))->toBeFalse();
});

// ── Bulk retrieval ──────────────────────────────────────────

it('retrieves all settings as a keyed collection', function (): void {
    GlobalSetting::set('ai_model', 'opus', 'string');
    GlobalSetting::set('timeout_minutes', 10, 'integer');
    GlobalSetting::set('auto_review', true, 'boolean');

    $all = GlobalSetting::all();

    expect($all)->toHaveCount(3);
});

// ── Bot PAT tracking (D144) ─────────────────────────────────

it('stores and retrieves bot_pat_created_at timestamp', function (): void {
    $now = now();
    $setting = GlobalSetting::create([
        'key' => 'bot_pat_info',
        'value' => 'gitlab-bot',
        'type' => 'string',
        'bot_pat_created_at' => $now,
    ]);

    expect($setting->bot_pat_created_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

// ── Default values ──────────────────────────────────────────

it('provides default settings via a static method', function (): void {
    $defaults = GlobalSetting::defaults();

    expect($defaults)->toBeArray()
        ->and($defaults)->toHaveKey('ai_model')
        ->and($defaults)->toHaveKey('ai_language')
        ->and($defaults)->toHaveKey('timeout_minutes')
        ->and($defaults)->toHaveKey('max_tokens');
});

it('falls back to default values when key exists in defaults but not in DB', function (): void {
    // Don't create any settings in DB
    $defaults = GlobalSetting::defaults();
    $value = GlobalSetting::get('ai_model');

    expect($value)->toBe($defaults['ai_model']);
});
