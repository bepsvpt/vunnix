<?php

use App\Models\AlertEvent;
use App\Services\AlertEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('system-alerts:evaluate outputs each event when alerts are triggered', function (): void {
    $event1 = AlertEvent::factory()->create([
        'alert_type' => 'api_outage',
        'status' => 'active',
        'severity' => 'high',
        'message' => 'API outage detected',
    ]);

    $event2 = AlertEvent::factory()->create([
        'alert_type' => 'high_failure_rate',
        'status' => 'active',
        'severity' => 'medium',
        'message' => 'High failure rate detected',
    ]);

    $this->mock(AlertEventService::class)
        ->shouldReceive('evaluateAll')
        ->once()
        ->andReturn([$event1, $event2]);

    $this->artisan('system-alerts:evaluate')
        ->expectsOutputToContain('api_outage: active')
        ->expectsOutputToContain('high_failure_rate: active')
        ->assertSuccessful();
});

test('system-alerts:evaluate outputs no changes message when no alerts', function (): void {
    $this->mock(AlertEventService::class)
        ->shouldReceive('evaluateAll')
        ->once()
        ->andReturn([]);

    $this->artisan('system-alerts:evaluate')
        ->expectsOutputToContain('No system alert changes.')
        ->assertSuccessful();
});

test('system-alerts:evaluate always returns success exit code', function (): void {
    $event = AlertEvent::factory()->create([
        'alert_type' => 'disk_usage',
        'status' => 'active',
        'severity' => 'medium',
        'message' => 'Disk usage warning — storage at 85%.',
    ]);

    $this->mock(AlertEventService::class)
        ->shouldReceive('evaluateAll')
        ->once()
        ->andReturn([$event]);

    $this->artisan('system-alerts:evaluate')
        ->assertExitCode(0);
});
