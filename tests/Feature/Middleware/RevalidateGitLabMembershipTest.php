<?php

use App\Http\Middleware\RevalidateGitLabMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Register a test route with the middleware
    Route::middleware(['web', RevalidateGitLabMembership::class])
        ->get('/test-revalidate', fn () => response('ok'));
});

it('revalidates membership on authenticated request when cache is empty', function () {
    $user = User::factory()->create(['oauth_token' => 'test-token']);
    $project = Project::factory()->create(['gitlab_project_id' => 101]);

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

    $this->actingAs($user)->get('/test-revalidate');

    // Membership should be synced
    expect($user->fresh()->projects)->toHaveCount(1);

    // Cache key should be set
    expect(Cache::has("membership_revalidated:{$user->id}"))->toBeTrue();
});

it('skips revalidation when cache key exists (within 15 min window)', function () {
    $user = User::factory()->create(['oauth_token' => 'test-token']);

    // Set cache as if recently revalidated
    Cache::put("membership_revalidated:{$user->id}", true, 900);

    // Should NOT call GitLab API
    Http::fake([
        '*/api/v4/projects*' => Http::response([], 200),
    ]);

    $this->actingAs($user)->get('/test-revalidate');

    // API should not have been called
    Http::assertNothingSent();
});

it('revalidates again after cache expires', function () {
    $user = User::factory()->create(['oauth_token' => 'test-token']);
    $project = Project::factory()->create(['gitlab_project_id' => 101]);

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

    // No cache key = should revalidate
    $this->actingAs($user)->get('/test-revalidate');

    Http::assertSentCount(1);
});

it('does not revalidate for unauthenticated requests', function () {
    Http::fake([
        '*/api/v4/projects*' => Http::response([], 200),
    ]);

    // Guest request — middleware should not call GitLab
    $this->get('/test-revalidate');

    Http::assertNothingSent();
});
