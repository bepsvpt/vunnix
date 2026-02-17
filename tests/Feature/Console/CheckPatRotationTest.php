<?php

use App\Models\AlertEvent;
use App\Models\GlobalSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        '*' => Http::response('ok', 200),
    ]);

    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');
});

test('security:check-pat-rotation creates alert when PAT is old', function (): void {
    GlobalSetting::updateOrCreate(
        ['key' => 'bot_pat_created_at'],
        ['bot_pat_created_at' => now()->subDays(170), 'value' => now()->subDays(170)->toIso8601String(), 'type' => 'string']
    );

    $this->artisan('security:check-pat-rotation')
        ->expectsOutputToContain('PAT rotation alert created')
        ->assertSuccessful();

    expect(AlertEvent::where('alert_type', 'bot_pat_rotation')->count())->toBe(1);
});

test('security:check-pat-rotation reports no alert when PAT is fresh', function (): void {
    GlobalSetting::updateOrCreate(
        ['key' => 'bot_pat_created_at'],
        ['bot_pat_created_at' => now()->subDays(30), 'value' => now()->subDays(30)->toIso8601String(), 'type' => 'string']
    );

    $this->artisan('security:check-pat-rotation')
        ->expectsOutputToContain('No PAT rotation alert needed')
        ->assertSuccessful();

    expect(AlertEvent::count())->toBe(0);
});

test('security:check-pat-rotation reports no alert when no PAT date configured', function (): void {
    $this->artisan('security:check-pat-rotation')
        ->expectsOutputToContain('No PAT rotation alert needed')
        ->assertSuccessful();

    expect(AlertEvent::count())->toBe(0);
});
