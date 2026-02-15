<?php

use App\Models\AlertEvent;
use App\Models\GlobalSetting;
use App\Services\BotPatRotationService;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Http::fake([
        '*' => Http::response('ok', 200),
    ]);

    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');
});

// ─── Alert Triggering ───────────────────────────────────────────

it('triggers alert when PAT is 5.5 months old', function () {
    // 5.5 months ≈ 167 days
    GlobalSetting::updateOrCreate(
        ['key' => 'bot_pat_created_at'],
        ['bot_pat_created_at' => now()->subDays(168), 'value' => now()->subDays(168)->toIso8601String(), 'type' => 'string']
    );

    $service = app(BotPatRotationService::class);
    $alert = $service->evaluate();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('bot_pat_rotation');
    expect($alert->status)->toBe('active');
    expect($alert->severity)->toBe('high');
    expect($alert->message)->toContain('Bot PAT rotation needed');
    expect($alert->notified_at)->not->toBeNull();
    expect($alert->context)->toHaveKey('age_days');
    expect($alert->context)->toHaveKey('pat_created_at');
});

it('does not trigger alert when PAT is only 5 months old', function () {
    // 5 months ≈ 152 days — below threshold
    GlobalSetting::updateOrCreate(
        ['key' => 'bot_pat_created_at'],
        ['bot_pat_created_at' => now()->subDays(152), 'value' => now()->subDays(152)->toIso8601String(), 'type' => 'string']
    );

    $service = app(BotPatRotationService::class);
    $alert = $service->evaluate();

    expect($alert)->toBeNull();
    expect(AlertEvent::count())->toBe(0);
});

it('triggers alert when PAT is 6 months old', function () {
    // 6 months ≈ 183 days — well past threshold
    GlobalSetting::updateOrCreate(
        ['key' => 'bot_pat_created_at'],
        ['bot_pat_created_at' => now()->subDays(183), 'value' => now()->subDays(183)->toIso8601String(), 'type' => 'string']
    );

    $service = app(BotPatRotationService::class);
    $alert = $service->evaluate();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('bot_pat_rotation');
    expect($alert->context['age_days'])->toBe(183);
});

it('does not trigger when no PAT creation date is set', function () {
    // No bot_pat_created_at in global settings
    $service = app(BotPatRotationService::class);
    $alert = $service->evaluate();

    expect($alert)->toBeNull();
});

// ─── Deduplication ──────────────────────────────────────────────

it('does not create duplicate alert when one is already active', function () {
    GlobalSetting::updateOrCreate(
        ['key' => 'bot_pat_created_at'],
        ['bot_pat_created_at' => now()->subDays(180), 'value' => now()->subDays(180)->toIso8601String(), 'type' => 'string']
    );

    $service = app(BotPatRotationService::class);

    // First evaluation creates alert
    $alert1 = $service->evaluate();
    expect($alert1)->not->toBeNull();

    // Second evaluation — should not create another
    $alert2 = $service->evaluate();
    expect($alert2)->toBeNull();

    expect(AlertEvent::where('alert_type', 'bot_pat_rotation')->count())->toBe(1);
});

// ─── Acknowledgement ────────────────────────────────────────────

it('stops repeating after acknowledgement within 7 days', function () {
    GlobalSetting::updateOrCreate(
        ['key' => 'bot_pat_created_at'],
        ['bot_pat_created_at' => now()->subDays(180), 'value' => now()->subDays(180)->toIso8601String(), 'type' => 'string']
    );

    $service = app(BotPatRotationService::class);

    // Create and acknowledge alert
    $alert = $service->evaluate();
    expect($alert)->not->toBeNull();

    $service->acknowledge($alert);
    expect($alert->fresh()->status)->toBe('resolved');
    expect($alert->fresh()->resolved_at)->not->toBeNull();

    // Evaluate again immediately — should not re-alert (acknowledged < 7 days ago)
    $alert2 = $service->evaluate();
    expect($alert2)->toBeNull();
});

it('re-alerts after 7 days since acknowledgement', function () {
    GlobalSetting::updateOrCreate(
        ['key' => 'bot_pat_created_at'],
        ['bot_pat_created_at' => now()->subDays(200), 'value' => now()->subDays(200)->toIso8601String(), 'type' => 'string']
    );

    $service = app(BotPatRotationService::class);

    // Create and acknowledge alert, then simulate 8 days passing
    $alert = $service->evaluate();
    $service->acknowledge($alert);

    // Backdate the resolved_at to 8 days ago
    $alert->update(['resolved_at' => now()->subDays(8)]);

    // Now evaluate — should create a new alert
    $alert2 = $service->evaluate();
    expect($alert2)->not->toBeNull();
    expect($alert2->id)->not->toBe($alert->id);
    expect(AlertEvent::where('alert_type', 'bot_pat_rotation')->count())->toBe(2);
});

// ─── Team Chat Notification ─────────────────────────────────────

it('sends team chat notification when alert is created', function () {
    GlobalSetting::updateOrCreate(
        ['key' => 'bot_pat_created_at'],
        ['bot_pat_created_at' => now()->subDays(170), 'value' => now()->subDays(170)->toIso8601String(), 'type' => 'string']
    );

    $service = app(BotPatRotationService::class);
    $service->evaluate();

    Http::assertSent(fn ($r) => str_contains($r['text'] ?? json_encode($r->body()), 'PAT rotation'));
});
