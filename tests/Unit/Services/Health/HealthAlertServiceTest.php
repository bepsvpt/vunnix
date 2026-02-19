<?php

use App\Models\AlertEvent;
use App\Models\HealthSnapshot;
use App\Models\Project;
use App\Services\GitLabClient;
use App\Services\Health\HealthAlertService;
use App\Services\ProjectConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'health.thresholds.coverage.warning' => 70,
        'health.thresholds.coverage.critical' => 50,
        'health.thresholds.dependency.warning' => 1,
        'health.thresholds.dependency.critical' => 3,
        'health.thresholds.complexity.warning' => 50,
        'health.thresholds.complexity.critical' => 30,
    ]);
});

it('creates alert event when snapshot is below threshold', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 999]);
    $snapshot = HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 45.0,
        'details' => ['coverage_percent' => 45.0],
    ]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('createIssue')->once()->withArgs(function (int $projectId, array $payload): bool {
        return $projectId === 999
            && str_contains((string) ($payload['title'] ?? ''), 'Health Alert');
    })->andReturn([
        'iid' => 42,
        'web_url' => 'https://gitlab.example.com/group/project/-/issues/42',
    ]);

    $service = new HealthAlertService(app(ProjectConfigService::class), $gitLab);
    $service->evaluateThresholds($project, collect([$snapshot]));

    $alert = AlertEvent::query()->first();
    expect($alert)->not->toBeNull();
    expect($alert?->alert_type)->toBe('health_coverage_decline');
    expect($alert?->severity)->toBe('critical');
    expect($alert?->context['project_id'])->toBe($project->id);
    expect($alert?->context['gitlab_issue_url'])->toContain('/issues/42');
});

it('resolves existing active alert when metric recovers', function (): void {
    $project = Project::factory()->create();
    AlertEvent::factory()->create([
        'alert_type' => 'health_coverage_decline',
        'status' => 'active',
        'context' => ['project_id' => $project->id],
    ]);

    $snapshot = HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 90.0,
        'details' => ['coverage_percent' => 90.0],
    ]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldNotReceive('createIssue');

    $service = new HealthAlertService(app(ProjectConfigService::class), $gitLab);
    $service->evaluateThresholds($project, collect([$snapshot]));

    $alert = AlertEvent::query()->first();
    expect($alert?->status)->toBe('resolved');
    expect($alert?->resolved_at)->not->toBeNull();
});

it('does not create duplicate active alert for same project and type', function (): void {
    $project = Project::factory()->create();
    AlertEvent::factory()->create([
        'alert_type' => 'health_coverage_decline',
        'status' => 'active',
        'context' => ['project_id' => $project->id],
    ]);

    $snapshot = HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 40.0,
        'details' => ['coverage_percent' => 40.0],
    ]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldNotReceive('createIssue');

    $service = new HealthAlertService(app(ProjectConfigService::class), $gitLab);
    $service->evaluateThresholds($project, collect([$snapshot]));

    expect(AlertEvent::query()->where('alert_type', 'health_coverage_decline')->count())->toBe(1);
});
