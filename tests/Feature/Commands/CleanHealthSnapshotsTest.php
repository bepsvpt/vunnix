<?php

use App\Models\HealthSnapshot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes snapshots older than retention window', function (): void {
    config(['health.snapshot_retention_days' => 30]);

    $project = Project::factory()->create();

    $old = HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'created_at' => now()->subDays(40),
    ]);

    $recent = HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'created_at' => now()->subDays(2),
    ]);

    $this->artisan('health:clean-snapshots')
        ->assertSuccessful();

    expect(HealthSnapshot::query()->whereKey($old->id)->exists())->toBeFalse();
    expect(HealthSnapshot::query()->whereKey($recent->id)->exists())->toBeTrue();
});
