<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('returns 200 with all checks passing when all services are healthy', function (): void {
    // DB is already available in test environment (SQLite in-memory)

    // Set a high disk threshold so the test passes regardless of actual disk usage
    config(['health.disk_usage_threshold' => 99]);

    // Mock Redis: Cache::store('redis') chain
    Cache::shouldReceive('store')
        ->with('redis')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('getRedis')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('connection')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('ping')
        ->once()
        ->andReturn(true);

    // Queue worker heartbeat
    Cache::shouldReceive('get')
        ->with('health:queue_worker:heartbeat')
        ->once()
        ->andReturn(now()->toIso8601String());

    // Reverb responds with 426 (WebSocket upgrade expected — healthy signal)
    Http::fake([
        '*' => Http::response('', 426),
    ]);

    $response = $this->getJson('/health');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'healthy',
        ])
        ->assertJsonStructure([
            'status',
            'checks' => [
                'postgresql' => ['status'],
                'redis' => ['status'],
                'queue_worker' => ['status'],
                'reverb' => ['status'],
                'disk' => ['status', 'usage_percent'],
            ],
        ]);
});

it('returns 503 when postgresql is down', function (): void {
    // Swap to a broken database connection
    config(['database.connections.broken' => [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => '1', // unreachable port
        'database' => 'nonexistent',
        'username' => 'nobody',
        'password' => 'wrong',
    ]]);
    DB::purge('broken');
    config(['database.default' => 'broken']);

    // Keep other services healthy
    Cache::shouldReceive('store')
        ->with('redis')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('getRedis')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('connection')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('ping')
        ->once()
        ->andReturn(true);

    Cache::shouldReceive('get')
        ->with('health:queue_worker:heartbeat')
        ->once()
        ->andReturn(now()->toIso8601String());

    Http::fake([
        '*' => Http::response('', 426),
    ]);

    $response = $this->getJson('/health');

    $response->assertStatus(503)
        ->assertJson([
            'status' => 'unhealthy',
            'checks' => [
                'postgresql' => [
                    'status' => 'fail',
                ],
            ],
        ]);
});

it('returns 503 when redis is down', function (): void {
    // Redis check fails
    Cache::shouldReceive('store')
        ->with('redis')
        ->once()
        ->andThrow(new \RuntimeException('Redis connection refused'));

    // Queue worker heartbeat also fails (depends on Redis/Cache)
    Cache::shouldReceive('get')
        ->with('health:queue_worker:heartbeat')
        ->once()
        ->andThrow(new \RuntimeException('Redis connection refused'));

    Http::fake([
        '*' => Http::response('', 426),
    ]);

    $response = $this->getJson('/health');

    $response->assertStatus(503)
        ->assertJson([
            'status' => 'unhealthy',
            'checks' => [
                'redis' => [
                    'status' => 'fail',
                ],
            ],
        ]);
});

it('returns 503 when queue worker heartbeat is missing', function (): void {
    Cache::shouldReceive('store')
        ->with('redis')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('getRedis')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('connection')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('ping')
        ->once()
        ->andReturn(true);

    // No heartbeat found
    Cache::shouldReceive('get')
        ->with('health:queue_worker:heartbeat')
        ->once()
        ->andReturn(null);

    Http::fake([
        '*' => Http::response('', 426),
    ]);

    $response = $this->getJson('/health');

    $response->assertStatus(503)
        ->assertJson([
            'status' => 'unhealthy',
            'checks' => [
                'queue_worker' => [
                    'status' => 'fail',
                    'error' => 'Check failed',
                ],
            ],
        ]);
});

it('returns 503 when reverb is not responding', function (): void {
    Cache::shouldReceive('store')
        ->with('redis')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('getRedis')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('connection')
        ->once()
        ->andReturnSelf();
    Cache::shouldReceive('ping')
        ->once()
        ->andReturn(true);

    Cache::shouldReceive('get')
        ->with('health:queue_worker:heartbeat')
        ->once()
        ->andReturn(now()->toIso8601String());

    // Reverb returns 500
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $response = $this->getJson('/health');

    $response->assertStatus(503)
        ->assertJson([
            'status' => 'unhealthy',
            'checks' => [
                'reverb' => [
                    'status' => 'fail',
                ],
            ],
        ]);
});
