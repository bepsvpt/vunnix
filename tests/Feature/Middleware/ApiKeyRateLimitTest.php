<?php

use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Use /api/ prefix to avoid SPA catch-all route in web.php
    // Register a test route with api.key + throttle middleware
    Route::middleware(['api.key', 'throttle:api_key'])->get('/api/test-rate-limit', function () {
        return response()->json(['ok' => true]);
    });
});

it('allows requests within rate limit', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $this->getJson('/api/test-rate-limit', [
        'Authorization' => 'Bearer ' . $result['plaintext'],
    ])->assertOk();
});

it('returns 429 when rate limit exceeded', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $headers = ['Authorization' => 'Bearer ' . $result['plaintext']];

    // Exhaust the rate limit (default 60/min)
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/test-rate-limit', $headers)->assertOk();
    }

    // 61st request should be throttled
    $this->getJson('/api/test-rate-limit', $headers)->assertStatus(429);
});

it('includes rate limit headers in response', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $response = $this->getJson('/api/test-rate-limit', [
        'Authorization' => 'Bearer ' . $result['plaintext'],
    ]);

    $response->assertOk();
    $response->assertHeader('X-RateLimit-Limit', '60');
    $response->assertHeader('X-RateLimit-Remaining');
});

it('rate limits per key not per user', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $key1 = $service->generate($user, 'Key 1');
    $key2 = $service->generate($user, 'Key 2');

    // Exhaust key1's limit
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/test-rate-limit', [
            'Authorization' => 'Bearer ' . $key1['plaintext'],
        ]);
    }

    // key1 is throttled
    $this->getJson('/api/test-rate-limit', [
        'Authorization' => 'Bearer ' . $key1['plaintext'],
    ])->assertStatus(429);

    // key2 should still work (separate limit)
    $this->getJson('/api/test-rate-limit', [
        'Authorization' => 'Bearer ' . $key2['plaintext'],
    ])->assertOk();
});
