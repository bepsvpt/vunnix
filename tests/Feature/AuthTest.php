<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

it('redirects to gitlab oauth with correct scopes', function () {
    config([
        'services.gitlab.client_id' => 'test-client-id',
        'services.gitlab.client_secret' => 'test-client-secret',
        'services.gitlab.redirect' => 'http://localhost/auth/gitlab/callback',
    ]);

    $response = $this->get('/auth/redirect');

    $response->assertRedirectContains('oauth/authorize');
    $response->assertRedirectContains('read_user');
    $response->assertRedirectContains('read_api');
});

it('creates a new user on first oauth callback', function () {
    $socialiteUser = mockSocialiteUser();

    Socialite::shouldReceive('driver')
        ->with('gitlab')
        ->once()
        ->andReturnSelf();
    Socialite::shouldReceive('user')
        ->once()
        ->andReturn($socialiteUser);

    $response = $this->get('/auth/gitlab/callback');

    $response->assertRedirect('/');

    $this->assertDatabaseHas('users', [
        'gitlab_id' => 12345,
        'name' => 'Kevin Test',
        'email' => 'kevin@example.com',
        'username' => 'kevintest',
        'oauth_provider' => 'gitlab',
    ]);

    $this->assertAuthenticated();
});

it('updates existing user on subsequent oauth callback', function () {
    $existingUser = User::factory()->create([
        'gitlab_id' => 12345,
        'name' => 'Old Name',
        'email' => 'old@example.com',
        'username' => 'oldusername',
    ]);

    $socialiteUser = mockSocialiteUser();

    Socialite::shouldReceive('driver')
        ->with('gitlab')
        ->once()
        ->andReturnSelf();
    Socialite::shouldReceive('user')
        ->once()
        ->andReturn($socialiteUser);

    $response = $this->get('/auth/gitlab/callback');

    $response->assertRedirect('/');

    $existingUser->refresh();
    expect($existingUser->name)->toBe('Kevin Test')
        ->and($existingUser->email)->toBe('kevin@example.com')
        ->and($existingUser->username)->toBe('kevintest');

    expect(User::count())->toBe(1);
});

it('stores oauth tokens on login', function () {
    $socialiteUser = mockSocialiteUser();

    Socialite::shouldReceive('driver')
        ->with('gitlab')
        ->once()
        ->andReturnSelf();
    Socialite::shouldReceive('user')
        ->once()
        ->andReturn($socialiteUser);

    $this->get('/auth/gitlab/callback');

    $user = User::where('gitlab_id', 12345)->first();
    expect($user->oauth_token)->toBe('mock-access-token')
        ->and($user->oauth_refresh_token)->toBe('mock-refresh-token')
        ->and($user->oauth_token_expires_at)->not->toBeNull();
});

it('establishes a session after oauth callback', function () {
    $socialiteUser = mockSocialiteUser();

    Socialite::shouldReceive('driver')
        ->with('gitlab')
        ->once()
        ->andReturnSelf();
    Socialite::shouldReceive('user')
        ->once()
        ->andReturn($socialiteUser);

    $this->get('/auth/gitlab/callback');

    $this->assertAuthenticated();
});

it('logs out and invalidates session', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->post('/auth/logout');

    $response->assertRedirect('/');
    $this->assertGuest();
});

it('redirects unauthenticated users to auth redirect', function () {
    $response = $this->post('/auth/logout');

    $response->assertRedirect('/auth/redirect');
});

// Helper function to create a mock Socialite user
function mockSocialiteUser(): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = 12345;
    $user->name = 'Kevin Test';
    $user->email = 'kevin@example.com';
    $user->nickname = 'kevintest';
    $user->avatar = 'https://gitlab.com/uploads/-/system/user/avatar/12345/avatar.png';
    $user->token = 'mock-access-token';
    $user->refreshToken = 'mock-refresh-token';
    $user->expiresIn = 7200;

    return $user;
}
