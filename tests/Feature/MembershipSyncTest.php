<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeGitLabProjects(array $projects): void
{
    Http::fake([
        '*/api/v4/projects?membership=true&per_page=100&page=1' => Http::response(
            collect($projects)->map(fn ($p) => [
                'id' => $p['gitlab_id'],
                'name' => $p['name'],
                'path_with_namespace' => $p['path'] ?? 'group/' . strtolower($p['name']),
                'description' => $p['description'] ?? null,
                'permissions' => [
                    'project_access' => isset($p['access_level'])
                        ? ['access_level' => $p['access_level']]
                        : null,
                    'group_access' => null,
                ],
            ])->toArray(),
            200,
        ),
    ]);
}

it('syncs memberships for projects registered in vunnix', function () {
    $user = User::factory()->create(['oauth_token' => 'test-token']);

    // Create some projects in Vunnix that match GitLab
    $projectA = Project::factory()->create(['gitlab_project_id' => 101, 'enabled' => true]);
    $projectB = Project::factory()->create(['gitlab_project_id' => 202, 'enabled' => true]);

    // GitLab returns 3 projects, but only 2 are registered in Vunnix
    fakeGitLabProjects([
        ['gitlab_id' => 101, 'name' => 'Project A', 'access_level' => 30],
        ['gitlab_id' => 202, 'name' => 'Project B', 'access_level' => 40],
        ['gitlab_id' => 303, 'name' => 'Unregistered Project', 'access_level' => 30],
    ]);

    $user->syncMemberships();

    // Only the 2 registered projects should be in the pivot
    expect($user->projects)->toHaveCount(2);

    $pivotA = $user->projects->firstWhere('id', $projectA->id);
    expect($pivotA->pivot->gitlab_access_level)->toBe(30)
        ->and($pivotA->pivot->synced_at)->not->toBeNull();

    $pivotB = $user->projects->firstWhere('id', $projectB->id);
    expect($pivotB->pivot->gitlab_access_level)->toBe(40);
});

it('updates access level on re-sync', function () {
    $user = User::factory()->create(['oauth_token' => 'test-token']);
    $project = Project::factory()->create(['gitlab_project_id' => 101]);

    // Initial sync at level 30
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now()->subHour(),
    ]);

    // GitLab now reports level 40
    fakeGitLabProjects([
        ['gitlab_id' => 101, 'name' => 'Project A', 'access_level' => 40],
    ]);

    $user->syncMemberships();
    $user->refresh();

    $pivot = $user->projects->first();
    expect($pivot->pivot->gitlab_access_level)->toBe(40);
});

it('removes memberships for projects no longer in gitlab', function () {
    $user = User::factory()->create(['oauth_token' => 'test-token']);
    $projectA = Project::factory()->create(['gitlab_project_id' => 101]);
    $projectB = Project::factory()->create(['gitlab_project_id' => 202]);

    // User currently has both projects
    $user->projects()->attach($projectA->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $user->projects()->attach($projectB->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // GitLab now only returns project A (user was removed from project B)
    fakeGitLabProjects([
        ['gitlab_id' => 101, 'name' => 'Project A', 'access_level' => 30],
    ]);

    $user->syncMemberships();
    $user->refresh();

    expect($user->projects)->toHaveCount(1)
        ->and($user->projects->first()->id)->toBe($projectA->id);
});

it('handles empty gitlab response gracefully', function () {
    $user = User::factory()->create(['oauth_token' => 'test-token']);
    $project = Project::factory()->create(['gitlab_project_id' => 101]);

    // User currently has a project
    $user->projects()->attach($project->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // GitLab returns empty (API error)
    Http::fake([
        '*/api/v4/projects*' => Http::response('Unauthorized', 401),
    ]);

    // Should not remove existing memberships on API failure
    $user->syncMemberships();
    $user->refresh();

    expect($user->projects)->toHaveCount(1);
});
