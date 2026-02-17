<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

it('syncs memberships on oauth login callback', function (): void {
    // Create a project in Vunnix that matches one in GitLab
    $project = Project::factory()->create(['gitlab_project_id' => 999, 'enabled' => true]);

    // Mock the GitLab API to return this project in the membership list
    Http::fake([
        '*/api/v4/projects?membership=true&per_page=100&page=1' => Http::response([
            [
                'id' => 999,
                'name' => 'My Registered Project',
                'path_with_namespace' => 'group/my-project',
                'description' => null,
                'permissions' => [
                    'project_access' => ['access_level' => 30],
                    'group_access' => null,
                ],
            ],
            [
                'id' => 888,
                'name' => 'Unregistered Project',
                'path_with_namespace' => 'group/unregistered',
                'description' => null,
                'permissions' => [
                    'project_access' => ['access_level' => 20],
                    'group_access' => null,
                ],
            ],
        ], 200),
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 12345;
    $socialiteUser->name = 'Kevin Test';
    $socialiteUser->email = 'kevin@example.com';
    $socialiteUser->nickname = 'kevintest';
    $socialiteUser->avatar = 'https://gitlab.com/avatar.png';
    $socialiteUser->token = 'mock-access-token';
    $socialiteUser->refreshToken = 'mock-refresh-token';
    $socialiteUser->expiresIn = 7200;

    Socialite::shouldReceive('driver')
        ->with('gitlab')
        ->once()
        ->andReturnSelf();
    Socialite::shouldReceive('user')
        ->once()
        ->andReturn($socialiteUser);

    $response = $this->get('/auth/gitlab/callback');

    $response->assertRedirect('/');
    $this->assertAuthenticated();

    // Verify the user's membership was synced
    $user = User::where('gitlab_id', 12345)->first();
    expect($user->projects)->toHaveCount(1)
        ->and($user->projects->first()->id)->toBe($project->id)
        ->and($user->projects->first()->pivot->gitlab_access_level)->toBe(30);
});

it('updates memberships on re-login', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 999]);
    $newProject = Project::factory()->create(['gitlab_project_id' => 888]);

    // User already exists with one project membership
    $user = User::factory()->create([
        'gitlab_id' => 12345,
        'oauth_token' => 'old-token',
    ]);
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now()->subDay(),
    ]);

    // Now GitLab returns a different set — user was added to newProject, removed from project
    Http::fake([
        '*/api/v4/projects?membership=true&per_page=100&page=1' => Http::response([
            [
                'id' => 888,
                'name' => 'New Project',
                'path_with_namespace' => 'group/new-project',
                'description' => null,
                'permissions' => [
                    'project_access' => ['access_level' => 40],
                    'group_access' => null,
                ],
            ],
        ], 200),
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 12345;
    $socialiteUser->name = 'Kevin Test';
    $socialiteUser->email = 'kevin@example.com';
    $socialiteUser->nickname = 'kevintest';
    $socialiteUser->avatar = 'https://gitlab.com/avatar.png';
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 7200;

    Socialite::shouldReceive('driver')
        ->with('gitlab')
        ->once()
        ->andReturnSelf();
    Socialite::shouldReceive('user')
        ->once()
        ->andReturn($socialiteUser);

    $this->get('/auth/gitlab/callback');

    $user->refresh();
    // Old project removed, new project added
    expect($user->projects)->toHaveCount(1)
        ->and($user->projects->first()->id)->toBe($newProject->id)
        ->and($user->projects->first()->pivot->gitlab_access_level)->toBe(40);
});
