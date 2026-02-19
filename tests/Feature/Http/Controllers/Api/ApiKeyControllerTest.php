<?php

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// ─── Index ─────────────────────────────────────────────────────

it('lists the authenticated user\'s API keys', function (): void {
    ApiKey::factory()->count(3)->create(['user_id' => $this->user->id]);
    ApiKey::factory()->create(); // another user's key

    $this->getJson('/api/v1/api-keys')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'last_used_at', 'last_ip', 'expires_at', 'revoked', 'created_at']]]);
});

it('does not expose the key hash in index response', function (): void {
    ApiKey::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson('/api/v1/api-keys')->assertOk();

    expect($response->json('data.0'))->not->toHaveKey('key');
});

// ─── Store ─────────────────────────────────────────────────────

it('creates a new API key and returns plaintext', function (): void {
    $response = $this->postJson('/api/v1/api-keys', [
        'name' => 'My CI Key',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'name', 'created_at'], 'plaintext']);

    // Plaintext is 64 hex chars
    expect(strlen($response->json('plaintext')))->toBe(64);

    // Key is in the database
    expect(ApiKey::where('user_id', $this->user->id)->count())->toBe(1);
});

it('creates a new API key with expiration date', function (): void {
    $expiresAt = now()->addDays(7)->toDateTimeString();

    $response = $this->postJson('/api/v1/api-keys', [
        'name' => 'Expiring Key',
        'expires_at' => $expiresAt,
    ]);

    $response->assertStatus(201);

    $apiKey = ApiKey::query()
        ->where('user_id', $this->user->id)
        ->where('name', 'Expiring Key')
        ->firstOrFail();

    expect($apiKey->expires_at)->not->toBeNull();
});

it('validates name is required', function (): void {
    $this->postJson('/api/v1/api-keys', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('validates name max length', function (): void {
    $this->postJson('/api/v1/api-keys', ['name' => str_repeat('a', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

// ─── Revoke ────────────────────────────────────────────────────

it('revokes the user\'s own API key', function (): void {
    $apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);

    $this->deleteJson("/api/v1/api-keys/{$apiKey->id}")
        ->assertOk()
        ->assertJsonPath('message', 'API key revoked.');

    $apiKey->refresh();
    expect($apiKey->revoked)->toBeTrue();
    expect($apiKey->revoked_at)->not->toBeNull();
});

it('cannot revoke another user\'s API key', function (): void {
    $otherKey = ApiKey::factory()->create();

    $this->deleteJson("/api/v1/api-keys/{$otherKey->id}")
        ->assertStatus(403);
});

// ─── Auth required ─────────────────────────────────────────────

it('requires authentication for all endpoints', function (): void {
    // Logout
    auth()->logout();

    $this->getJson('/api/v1/api-keys')->assertStatus(401);
    $this->postJson('/api/v1/api-keys', ['name' => 'test'])->assertStatus(401);
    $this->deleteJson('/api/v1/api-keys/1')->assertStatus(401);
});
