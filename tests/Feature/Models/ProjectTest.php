<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has a users relationship via project_user pivot', function (): void {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $project->users()->attach($user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    expect($project->users)->toBeInstanceOf(Collection::class)
        ->and($project->users)->toHaveCount(1)
        ->and($project->users->first()->id)->toBe($user->id);
});

it('includes pivot data on the users relationship', function (): void {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $project->users()->attach($user->id, [
        'gitlab_access_level' => 40,
        'synced_at' => now(),
    ]);

    $pivotUser = $project->users->first();
    expect($pivotUser->pivot->gitlab_access_level)->toBe(40)
        ->and($pivotUser->pivot->synced_at)->not->toBeNull();
});

it('scopes enabled projects', function (): void {
    Project::factory()->create(['enabled' => true]);
    Project::factory()->create(['enabled' => false]);
    Project::factory()->create(['enabled' => true]);

    expect(Project::enabled()->count())->toBe(2);
});

it('can find a project by gitlab_project_id', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 42]);

    $found = Project::where('gitlab_project_id', 42)->first();
    expect($found->id)->toBe($project->id);
});
