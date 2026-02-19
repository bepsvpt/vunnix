<?php

use App\Models\AlertEvent;
use App\Models\HealthSnapshot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function memberUser(Project $project): User
{
    $user = User::factory()->create();
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    return $user;
}

it('rejects unauthenticated requests', function (): void {
    $project = Project::factory()->create();

    $this->getJson("/api/v1/projects/{$project->id}/health/trends")
        ->assertUnauthorized();
});

it('rejects non-member requests', function (): void {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/health/summary")
        ->assertForbidden();
});

it('returns trends filtered by dimension and date range', function (): void {
    $project = Project::factory()->create();
    $user = memberUser($project);

    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 80,
        'created_at' => now()->subDays(2),
    ]);
    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'dependency',
        'score' => 95,
        'created_at' => now()->subDays(2),
    ]);
    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 77,
        'created_at' => now()->subDays(45),
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/health/trends?dimension=coverage&from=".now()->subDays(30)->toDateString())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.dimension', 'coverage');
});

it('returns summary with latest metric values', function (): void {
    $project = Project::factory()->create();
    $user = memberUser($project);

    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 75,
        'created_at' => now()->subDays(10),
    ]);
    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 82,
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/health/summary")
        ->assertOk()
        ->assertJsonPath('data.coverage.score', 82)
        ->assertJsonPath('data.coverage.trend_direction', 'up');
});

it('returns down trend when latest score drops significantly', function (): void {
    $project = Project::factory()->create();
    $user = memberUser($project);

    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 90,
        'created_at' => now()->subDays(10),
    ]);
    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 80,
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/health/summary")
        ->assertOk()
        ->assertJsonPath('data.coverage.trend_direction', 'down');
});

it('returns stable trend when score change is within threshold', function (): void {
    $project = Project::factory()->create();
    $user = memberUser($project);

    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 80,
        'created_at' => now()->subDays(10),
    ]);
    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 80.5,
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/health/summary")
        ->assertOk()
        ->assertJsonPath('data.coverage.trend_direction', 'stable');
});

it('returns active project health alerts only', function (): void {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = memberUser($project);

    AlertEvent::factory()->create([
        'alert_type' => 'health_coverage_decline',
        'status' => 'active',
        'context' => ['project_id' => $project->id],
    ]);

    AlertEvent::factory()->create([
        'alert_type' => 'health_coverage_decline',
        'status' => 'active',
        'context' => ['project_id' => $otherProject->id],
    ]);

    AlertEvent::factory()->create([
        'alert_type' => 'queue_depth',
        'status' => 'active',
        'context' => ['project_id' => $project->id],
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/health/alerts")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.alert_type', 'health_coverage_decline');
});
