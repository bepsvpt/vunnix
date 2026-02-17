<?php

use App\Events\MetricsUpdated;
use App\Services\MetricsAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('returns zero views refreshed on non-pgsql database', function (): void {
    Event::fake([MetricsUpdated::class]);

    $service = app(MetricsAggregationService::class);
    $result = $service->aggregate();

    // SQLite test environment should return 0 views refreshed
    expect($result)->toHaveKey('views_refreshed', 0)
        ->toHaveKey('duration_ms');
    expect($result['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('refreshes all materialized views on pgsql', function (): void {
    Event::fake([MetricsUpdated::class]);

    // Mock DB connection to report pgsql driver
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getDriverName')->andReturn('pgsql');

    // Expect 3 REFRESH MATERIALIZED VIEW statements (one per view)
    DB::shouldReceive('statement')
        ->with('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_metrics_by_project')
        ->once()
        ->andReturnTrue();
    DB::shouldReceive('statement')
        ->with('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_metrics_by_type')
        ->once()
        ->andReturnTrue();
    DB::shouldReceive('statement')
        ->with('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_metrics_by_period')
        ->once()
        ->andReturnTrue();

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Metrics aggregation completed'
                && $context['views_refreshed'] === 3;
        });

    $service = app(MetricsAggregationService::class);
    $result = $service->aggregate();

    expect($result['views_refreshed'])->toBe(3);
});

it('continues refreshing views when one fails and logs error', function (): void {
    Event::fake([MetricsUpdated::class]);

    // Mock DB connection to report pgsql driver
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getDriverName')->andReturn('pgsql');

    // First view succeeds
    DB::shouldReceive('statement')
        ->with('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_metrics_by_project')
        ->once()
        ->andReturnTrue();

    // Second view fails
    DB::shouldReceive('statement')
        ->with('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_metrics_by_type')
        ->once()
        ->andThrow(new RuntimeException('relation does not exist'));

    // Third view succeeds
    DB::shouldReceive('statement')
        ->with('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_metrics_by_period')
        ->once()
        ->andReturnTrue();

    // Expect error log for the failed view
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'mv_metrics_by_type')
                && str_contains($context['error'], 'relation does not exist');
        });

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Metrics aggregation completed'
                && $context['views_refreshed'] === 2;
        });

    $service = app(MetricsAggregationService::class);
    $result = $service->aggregate();

    // Only 2 views refreshed (first and third)
    expect($result['views_refreshed'])->toBe(2);
});
