<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ApiKeyService::class);
    $this->user = User::factory()->create();
});

it('generates a new API key and returns plaintext once', function () {
    $result = $this->service->generate($this->user, 'My CI Key');

    expect($result)->toHaveKeys(['api_key', 'plaintext']);
    expect($result['api_key'])->toBeInstanceOf(ApiKey::class);
    expect($result['api_key']->name)->toBe('My CI Key');
    expect($result['api_key']->user_id)->toBe($this->user->id);
    expect($result['api_key']->revoked)->toBeFalse();

    // Plaintext is a 64-char hex string (SHA-256 input)
    expect(strlen($result['plaintext']))->toBe(64);

    // Stored key is SHA-256 hash of plaintext
    expect($result['api_key']->key)->toBe(hash('sha256', $result['plaintext']));
});

it('generates unique keys', function () {
    $result1 = $this->service->generate($this->user, 'Key 1');
    $result2 = $this->service->generate($this->user, 'Key 2');

    expect($result1['plaintext'])->not->toBe($result2['plaintext']);
    expect($result1['api_key']->key)->not->toBe($result2['api_key']->key);
});

it('resolves user from plaintext key', function () {
    $result = $this->service->generate($this->user, 'Test Key');

    $resolved = $this->service->resolveUser($result['plaintext']);
    expect($resolved->id)->toBe($this->user->id);
});

it('returns null for invalid plaintext key', function () {
    expect($this->service->resolveUser('invalid-key'))->toBeNull();
});

it('returns null for revoked key', function () {
    $result = $this->service->generate($this->user, 'Test Key');
    $result['api_key']->update(['revoked' => true, 'revoked_at' => now()]);

    expect($this->service->resolveUser($result['plaintext']))->toBeNull();
});

it('returns null for expired key', function () {
    $result = $this->service->generate($this->user, 'Test Key', now()->subDay());

    expect($this->service->resolveUser($result['plaintext']))->toBeNull();
});

it('revokes a key', function () {
    $result = $this->service->generate($this->user, 'Test Key');

    $this->service->revoke($result['api_key']);
    $result['api_key']->refresh();

    expect($result['api_key']->revoked)->toBeTrue();
    expect($result['api_key']->revoked_at)->not->toBeNull();
});

it('records usage on resolve', function () {
    $result = $this->service->generate($this->user, 'Test Key');

    $this->service->resolveUser($result['plaintext'], '10.0.0.1');
    $result['api_key']->refresh();

    expect($result['api_key']->last_ip)->toBe('10.0.0.1');
    expect($result['api_key']->last_used_at)->not->toBeNull();
});
