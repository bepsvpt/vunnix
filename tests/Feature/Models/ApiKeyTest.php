<?php

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a user', function () {
    $user = User::factory()->create();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

    expect($apiKey->user->id)->toBe($user->id);
});

it('can check if revoked', function () {
    $key = ApiKey::factory()->create(['revoked' => false]);
    expect($key->isRevoked())->toBeFalse();

    $key->update(['revoked' => true, 'revoked_at' => now()]);
    expect($key->isRevoked())->toBeTrue();
});

it('can check if expired', function () {
    $key = ApiKey::factory()->create(['expires_at' => null]);
    expect($key->isExpired())->toBeFalse();

    $key->update(['expires_at' => now()->subDay()]);
    expect($key->isExpired())->toBeTrue();

    $key->update(['expires_at' => now()->addDay()]);
    expect($key->isExpired())->toBeFalse();
});

it('can check if active (not revoked and not expired)', function () {
    $key = ApiKey::factory()->create(['revoked' => false, 'expires_at' => null]);
    expect($key->isActive())->toBeTrue();

    $key->update(['revoked' => true, 'revoked_at' => now()]);
    expect($key->isActive())->toBeFalse();
});

it('has an active scope', function () {
    ApiKey::factory()->create(['revoked' => false, 'expires_at' => null]);
    ApiKey::factory()->create(['revoked' => true, 'revoked_at' => now()]);
    ApiKey::factory()->create(['revoked' => false, 'expires_at' => now()->subDay()]);

    expect(ApiKey::active()->count())->toBe(1);
});

it('records last used info', function () {
    $key = ApiKey::factory()->create();
    $key->recordUsage('192.168.1.1');

    $key->refresh();
    expect($key->last_ip)->toBe('192.168.1.1');
    expect($key->last_used_at)->not->toBeNull();
});
