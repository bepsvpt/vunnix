<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('revalidates membership on authenticated api routes when cache is empty', function (): void {
    $user = User::factory()->create(['oauth_token' => 'test-token']);
    Project::factory()->create(['gitlab_project_id' => 101]);

    Http::fake([
        '*/api/v4/projects?membership=true&per_page=100&page=1' => Http::response([
            [
                'id' => 101,
                'name' => 'Project',
                'path_with_namespace' => 'group/project',
                'description' => null,
                'permissions' => [
                    'project_access' => ['access_level' => 30],
                    'group_access' => null,
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($user)->getJson('/api/v1/user')->assertOk();

    Http::assertSentCount(1);
    expect(Cache::has("membership_revalidated:{$user->id}"))->toBeTrue();
    expect($user->fresh()->projects)->toHaveCount(1);
});

it('uses cache on subsequent authenticated api requests', function (): void {
    $user = User::factory()->create(['oauth_token' => 'test-token']);
    Project::factory()->create(['gitlab_project_id' => 101]);

    Http::fake([
        '*/api/v4/projects?membership=true&per_page=100&page=1' => Http::response([
            [
                'id' => 101,
                'name' => 'Project',
                'path_with_namespace' => 'group/project',
                'description' => null,
                'permissions' => [
                    'project_access' => ['access_level' => 30],
                    'group_access' => null,
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($user)->getJson('/api/v1/user')->assertOk();
    $this->actingAs($user)->getJson('/api/v1/user')->assertOk();

    Http::assertSentCount(1);
});

it('does not revalidate memberships for unauthenticated api requests', function (): void {
    Http::fake([
        '*/api/v4/projects*' => Http::response([], 200),
    ]);

    $this->getJson('/api/v1/user')->assertUnauthorized();

    Http::assertNothingSent();
});
