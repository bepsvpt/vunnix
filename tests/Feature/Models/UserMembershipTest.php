<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has a projects relationship via project_user pivot', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    expect($user->projects)->toBeInstanceOf(Collection::class)
        ->and($user->projects)->toHaveCount(1)
        ->and($user->projects->first()->id)->toBe($project->id);
});

it('includes pivot data on the projects relationship', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 50,
        'synced_at' => now(),
    ]);

    $pivotProject = $user->projects->first();
    expect($pivotProject->pivot->gitlab_access_level)->toBe(50)
        ->and($pivotProject->pivot->synced_at)->not->toBeNull();
});

it('returns accessible projects for a user', function (): void {
    $user = User::factory()->create();
    $projectA = Project::factory()->create(['enabled' => true]);
    $projectB = Project::factory()->create(['enabled' => true]);
    $projectC = Project::factory()->create(['enabled' => false]);

    $user->projects()->attach($projectA->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $user->projects()->attach($projectB->id, ['gitlab_access_level' => 20, 'synced_at' => now()]);
    $user->projects()->attach($projectC->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // accessibleProjects returns only enabled projects the user belongs to
    $accessible = $user->accessibleProjects();
    expect($accessible)->toHaveCount(2)
        ->and($accessible->pluck('id')->toArray())->toContain($projectA->id, $projectB->id);
});

it('returns the gitlab access level for a specific project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 40,
        'synced_at' => now(),
    ]);

    expect($user->gitlabAccessLevel($project))->toBe(40);
});

it('returns null access level for a project the user does not belong to', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    expect($user->gitlabAccessLevel($project))->toBeNull();
});
