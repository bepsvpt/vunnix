<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('returns healthy when all checks pass', function (): void {
    // Set a fresh queue worker heartbeat
    Cache::put('health:queue_worker:heartbeat', now()->toIso8601String(), 3600);

    // Use a high disk threshold so the test doesn't depend on the dev machine's actual disk usage
    config(['health.disk_usage_threshold' => 99]);

    // Mock the Redis health check (Redis may not be available in CI)
    $connectionMock = Mockery::mock();
    $connectionMock->shouldReceive('ping')->andReturn(true);

    $redisManagerMock = Mockery::mock();
    $redisManagerMock->shouldReceive('connection')->andReturn($connectionMock);

    $storeMock = Mockery::mock(\Illuminate\Cache\RedisStore::class);
    $storeMock->shouldReceive('getRedis')->andReturn($redisManagerMock);

    Cache::shouldReceive('store')->with('redis')->andReturn($storeMock);
    // Allow other Cache calls to pass through for queue worker heartbeat check
    Cache::shouldReceive('get')->with('health:queue_worker:heartbeat')->andReturn(now()->toIso8601String());

    // Fake the Reverb HTTP check to return a successful response
    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    $response = $this->getJson('/health');

    $response->assertOk();
    $response->assertJsonPath('status', 'healthy');
});

it('returns unhealthy when Redis ping returns falsy', function (): void {
    // Set queue worker heartbeat so that check passes
    Cache::put('health:queue_worker:heartbeat', now()->toIso8601String(), 3600);

    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    // Mock the Redis store to return false on ping
    $redisMock = Mockery::mock();
    $redisMock->shouldReceive('ping')->andReturn(false);

    $connectionMock = Mockery::mock();
    $connectionMock->shouldReceive('ping')->andReturn(false);

    $redisManagerMock = Mockery::mock();
    $redisManagerMock->shouldReceive('connection')->andReturn($connectionMock);

    $storeMock = Mockery::mock(\Illuminate\Cache\RedisStore::class);
    $storeMock->shouldReceive('getRedis')->andReturn($redisManagerMock);

    Cache::shouldReceive('store')->with('redis')->andReturn($storeMock);
    // Allow other Cache calls to pass through for queue worker heartbeat check
    Cache::shouldReceive('get')->with('health:queue_worker:heartbeat')->andReturn(now()->toIso8601String());

    $response = $this->getJson('/health');

    $response->assertStatus(503);
    $data = $response->json();
    expect($data['checks']['redis']['status'])->toBe('fail');
    expect($data['checks']['redis']['error'])->toBe('Redis ping returned falsy');
});

it('returns unhealthy when queue worker heartbeat is stale', function (): void {
    // Set a stale heartbeat (10 minutes ago, threshold is 5 minutes)
    Cache::put('health:queue_worker:heartbeat', now()->subMinutes(10)->toIso8601String(), 3600);

    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    $response = $this->getJson('/health');

    $response->assertStatus(503);
    $data = $response->json();
    expect($data['checks']['queue_worker']['status'])->toBe('fail');
    expect($data['checks']['queue_worker']['error'])->toBe('Queue worker heartbeat is stale');
});

it('returns unhealthy when queue worker heartbeat is missing', function (): void {
    // Don't set any heartbeat at all
    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    $response = $this->getJson('/health');

    $response->assertStatus(503);
    $data = $response->json();
    expect($data['checks']['queue_worker']['status'])->toBe('fail');
    expect($data['checks']['queue_worker']['error'])->toBe('No queue worker heartbeat detected');
});

it('returns unhealthy when Reverb check throws an exception', function (): void {
    Cache::put('health:queue_worker:heartbeat', now()->toIso8601String(), 3600);

    // Make Reverb HTTP request throw an exception
    Http::fake([
        '*' => Http::response('Connection refused', 500),
    ]);

    // Override to actually throw — Http::fake with throw
    Http::fake(function (): void {
        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    });

    $response = $this->getJson('/health');

    $response->assertStatus(503);
    $data = $response->json();
    expect($data['checks']['reverb']['status'])->toBe('fail');
    expect($data['checks']['reverb']['error'])->toContain('Connection refused');
});

it('returns unhealthy when disk usage threshold is exceeded', function (): void {
    Cache::put('health:queue_worker:heartbeat', now()->toIso8601String(), 3600);

    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    // Set a very low threshold (0.1%) so actual disk usage exceeds it
    config(['health.disk_usage_threshold' => 0.1]);

    $response = $this->getJson('/health');

    $response->assertStatus(503);
    $data = $response->json();
    expect($data['checks']['disk']['status'])->toBe('fail');
    expect($data['checks']['disk']['error'])->toContain('Disk usage at');
    expect($data['checks']['disk']['error'])->toContain('threshold: 0.1%');
});
