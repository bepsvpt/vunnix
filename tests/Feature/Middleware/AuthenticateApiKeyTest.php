<?php

use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Register test route under /api/ prefix to avoid SPA catch-all route (/{any})
    Route::middleware('api.key')->get('/api/test-api-key', function () {
        return response()->json([
            'user_id' => request()->user()->id,
            'auth_via' => request()->attributes->get('auth_via'),
        ]);
    });
});

it('authenticates with a valid API key', function (): void {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $this->getJson('/api/test-api-key', [
        'Authorization' => 'Bearer '.$result['plaintext'],
    ])
        ->assertOk()
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('auth_via', 'api_key');
});

it('rejects request with no bearer token', function (): void {
    $this->getJson('/api/test-api-key')
        ->assertStatus(401)
        ->assertJsonPath('error', 'Missing API key.');
});

it('rejects request with invalid bearer token', function (): void {
    $this->getJson('/api/test-api-key', [
        'Authorization' => 'Bearer invalid-token-here',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error', 'Invalid or expired API key.');
});

it('rejects revoked API key', function (): void {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');
    $service->revoke($result['api_key']);

    $this->getJson('/api/test-api-key', [
        'Authorization' => 'Bearer '.$result['plaintext'],
    ])
        ->assertStatus(401)
        ->assertJsonPath('error', 'Invalid or expired API key.');
});

it('rejects expired API key', function (): void {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key', now()->subDay());

    $this->getJson('/api/test-api-key', [
        'Authorization' => 'Bearer '.$result['plaintext'],
    ])
        ->assertStatus(401)
        ->assertJsonPath('error', 'Invalid or expired API key.');
});

it('sets auth_via attribute on request', function (): void {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $this->getJson('/api/test-api-key', [
        'Authorization' => 'Bearer '.$result['plaintext'],
    ])
        ->assertOk()
        ->assertJsonPath('auth_via', 'api_key');
});
