<?php

use App\Jobs\AnalyzeProjectHealth;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['health.enabled' => true]);
});

it('queues analysis jobs for enabled projects', function (): void {
    Queue::fake([AnalyzeProjectHealth::class]);

    $enabled = Project::factory()->enabled()->create();
    Project::factory()->create(['enabled' => false]);

    $skipped = Project::factory()->enabled()->create();
    $skipped->projectConfig()->create([
        'settings' => [
            'health' => ['enabled' => false],
        ],
    ]);

    $this->artisan('health:analyze')
        ->expectsOutput('Queued health analysis for 1 project.')
        ->assertSuccessful();

    Queue::assertPushed(AnalyzeProjectHealth::class, function (AnalyzeProjectHealth $job) use ($enabled): bool {
        return $job->projectId === $enabled->id;
    });
});

it('queues a single project when --project is provided', function (): void {
    Queue::fake([AnalyzeProjectHealth::class]);

    $project = Project::factory()->enabled()->create();

    $this->artisan('health:analyze', ['--project' => $project->id])
        ->expectsOutput("Queued health analysis for project {$project->id}.")
        ->assertSuccessful();

    Queue::assertPushed(AnalyzeProjectHealth::class, 1);
});

it('returns early when health analysis is disabled globally', function (): void {
    config(['health.enabled' => false]);
    Queue::fake([AnalyzeProjectHealth::class]);

    $this->artisan('health:analyze')
        ->expectsOutput('Health analysis is disabled by feature flag.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('fails when provided project does not exist', function (): void {
    Queue::fake([AnalyzeProjectHealth::class]);

    $this->artisan('health:analyze', ['--project' => 999999])
        ->expectsOutput('Project not found.')
        ->assertFailed();

    Queue::assertNothingPushed();
});

it('fails when provided project is disabled', function (): void {
    Queue::fake([AnalyzeProjectHealth::class]);
    $project = Project::factory()->create(['enabled' => false]);

    $this->artisan('health:analyze', ['--project' => $project->id])
        ->expectsOutput('Project is disabled.')
        ->assertFailed();

    Queue::assertNothingPushed();
});

it('skips single project when project-level health flag is disabled', function (): void {
    Queue::fake([AnalyzeProjectHealth::class]);
    $project = Project::factory()->enabled()->create();
    $project->projectConfig()->create([
        'settings' => [
            'health' => ['enabled' => false],
        ],
    ]);

    $this->artisan('health:analyze', ['--project' => $project->id])
        ->expectsOutput("Project {$project->id} has health.enabled = false. Skipping.")
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
