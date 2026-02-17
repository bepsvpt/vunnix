<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessTask;
use App\Models\FindingAcceptance;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['enabled' => true]);
    $this->project->users()->attach($this->user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $this->otherProject = Project::factory()->create(['enabled' => true]);

    $this->service = app(ApiKeyService::class);
    $this->apiKeyResult = $this->service->generate($this->user, 'Test Key');
    $this->headers = ['Authorization' => 'Bearer '.$this->apiKeyResult['plaintext']];
});

// ─── GET /tasks — list with filters ─────────────────────────

it('lists tasks scoped to accessible projects', function (): void {
    Task::factory()->create(['project_id' => $this->project->id]);
    Task::factory()->create(['project_id' => $this->otherProject->id]);

    $response = $this->getJson('/api/v1/ext/tasks', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters tasks by type', function (): void {
    Task::factory()->create([
        'project_id' => $this->project->id,
        'type' => TaskType::CodeReview,
    ]);
    Task::factory()->create([
        'project_id' => $this->project->id,
        'type' => TaskType::FeatureDev,
    ]);

    $response = $this->getJson('/api/v1/ext/tasks?type=code_review', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'code_review');
});

it('filters tasks by status', function (): void {
    Task::factory()->completed()->create(['project_id' => $this->project->id]);
    Task::factory()->failed()->create(['project_id' => $this->project->id]);

    $response = $this->getJson('/api/v1/ext/tasks?status=completed', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'completed');
});

it('filters tasks by project_id', function (): void {
    $secondProject = Project::factory()->create(['enabled' => true]);
    $secondProject->users()->attach($this->user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    Task::factory()->create(['project_id' => $this->project->id]);
    Task::factory()->create(['project_id' => $secondProject->id]);

    $response = $this->getJson('/api/v1/ext/tasks?project_id='.$secondProject->id, $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.project_id', $secondProject->id);
});

it('returns empty when filtering by inaccessible project', function (): void {
    Task::factory()->create(['project_id' => $this->project->id]);

    $response = $this->getJson('/api/v1/ext/tasks?project_id='.$this->otherProject->id, $this->headers);

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters tasks by date range', function (): void {
    Task::factory()->create([
        'project_id' => $this->project->id,
        'created_at' => '2026-01-15 10:00:00',
    ]);
    Task::factory()->create([
        'project_id' => $this->project->id,
        'created_at' => '2026-02-10 10:00:00',
    ]);

    $response = $this->getJson('/api/v1/ext/tasks?date_from=2026-02-01&date_to=2026-02-28', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('validates invalid filter values', function (): void {
    $this->getJson('/api/v1/ext/tasks?type=invalid_type', $this->headers)
        ->assertStatus(422);

    $this->getJson('/api/v1/ext/tasks?status=bogus', $this->headers)
        ->assertStatus(422);
});

it('paginates tasks with cursor', function (): void {
    Task::factory()->count(3)->create(['project_id' => $this->project->id]);

    $response = $this->getJson('/api/v1/ext/tasks?per_page=2', $this->headers);

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data', 'meta', 'links']);
});

it('returns correct ExternalTaskResource shape', function (): void {
    Task::factory()->completed()->create([
        'project_id' => $this->project->id,
        'cost' => 0.05,
        'tokens_used' => 5000,
    ]);

    $response = $this->getJson('/api/v1/ext/tasks', $this->headers);

    $response->assertOk()
        ->assertJsonStructure(['data' => [['id', 'type', 'status', 'priority', 'project_id', 'project_name', 'created_at']]]);
});

// ─── GET /tasks/:id — show ──────────────────────────────────

it('shows a single task', function (): void {
    $task = Task::factory()->completed()->create([
        'project_id' => $this->project->id,
        'cost' => 0.05,
    ]);

    $response = $this->getJson('/api/v1/ext/tasks/'.$task->id, $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.id', $task->id)
        ->assertJsonPath('data.status', 'completed');
});

it('returns 403 for task in inaccessible project', function (): void {
    $task = Task::factory()->create(['project_id' => $this->otherProject->id]);

    $this->getJson('/api/v1/ext/tasks/'.$task->id, $this->headers)
        ->assertStatus(403);
});

// ─── POST /tasks/review — trigger on-demand review ──────────

it('triggers an on-demand review', function (): void {
    Queue::fake([ProcessTask::class]);

    $response = $this->postJson('/api/v1/ext/tasks/review', [
        'project_id' => $this->project->id,
        'mr_iid' => 42,
    ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'code_review')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.mr_iid', 42);

    Queue::assertPushed(ProcessTask::class);
});

it('rejects review for inaccessible project', function (): void {
    $this->postJson('/api/v1/ext/tasks/review', [
        'project_id' => $this->otherProject->id,
        'mr_iid' => 42,
    ], $this->headers)->assertStatus(403);
});

it('validates required fields for review trigger', function (): void {
    $this->postJson('/api/v1/ext/tasks/review', [], $this->headers)
        ->assertStatus(422);

    $this->postJson('/api/v1/ext/tasks/review', [
        'project_id' => $this->project->id,
    ], $this->headers)->assertStatus(422);
});

// ─── prompt_version in task responses ────────────────────────

it('includes prompt_version in task detail response', function (): void {
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => [
            'skill' => 'frontend-review:1.0',
            'claude_md' => 'executor:1.0',
            'schema' => 'review:1.0',
        ],
    ]);

    $this->getJson('/api/v1/ext/tasks/'.$task->id, $this->headers)
        ->assertOk()
        ->assertJsonPath('data.prompt_version.skill', 'frontend-review:1.0')
        ->assertJsonPath('data.prompt_version.claude_md', 'executor:1.0')
        ->assertJsonPath('data.prompt_version.schema', 'review:1.0');
});

it('filters tasks by prompt_version skill', function (): void {
    Task::factory()->create([
        'project_id' => $this->project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => [
            'skill' => 'frontend-review:1.0',
            'claude_md' => 'executor:1.0',
            'schema' => 'review:1.0',
        ],
    ]);
    Task::factory()->create([
        'project_id' => $this->project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => [
            'skill' => 'frontend-review:1.1',
            'claude_md' => 'executor:1.0',
            'schema' => 'review:1.0',
        ],
    ]);

    $this->getJson('/api/v1/ext/tasks?prompt_version=frontend-review:1.0', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.prompt_version.skill', 'frontend-review:1.0');
});

// ─── GET /metrics/summary ───────────────────────────────────

it('returns metrics summary', function (): void {
    Task::factory()->completed()->create([
        'project_id' => $this->project->id,
        'cost' => 0.10,
    ]);
    Task::factory()->failed()->create([
        'project_id' => $this->project->id,
    ]);

    $response = $this->getJson('/api/v1/ext/metrics/summary', $this->headers);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'total_completed',
                'total_failed',
                'active_tasks',
                'success_rate',
                'tasks_by_type',
                'total_cost',
                'acceptance_rate',
            ],
        ])
        ->assertJsonPath('data.total_completed', 1)
        ->assertJsonPath('data.total_failed', 1)
        ->assertJsonPath('data.success_rate', 50);
});

it('returns null acceptance_rate when no findings exist', function (): void {
    $response = $this->getJson('/api/v1/ext/metrics/summary', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.acceptance_rate', null);
});

it('calculates acceptance rate from finding acceptances', function (): void {
    $task = Task::factory()->completed()->create([
        'project_id' => $this->project->id,
    ]);

    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $this->project->id,
        'mr_iid' => 1,
        'finding_id' => 'f-1',
        'file' => 'app.php',
        'line' => 10,
        'severity' => 'major',
        'title' => 'Test finding',
        'status' => 'accepted',
    ]);
    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $this->project->id,
        'mr_iid' => 1,
        'finding_id' => 'f-2',
        'file' => 'app.php',
        'line' => 20,
        'severity' => 'minor',
        'title' => 'Other finding',
        'status' => 'dismissed',
    ]);

    $response = $this->getJson('/api/v1/ext/metrics/summary', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.acceptance_rate', 50);
});

it('scopes metrics to accessible projects only', function (): void {
    Task::factory()->completed()->create([
        'project_id' => $this->project->id,
        'cost' => 0.10,
    ]);
    Task::factory()->completed()->create([
        'project_id' => $this->otherProject->id,
        'cost' => 0.50,
    ]);

    $response = $this->getJson('/api/v1/ext/metrics/summary', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.total_completed', 1);
});

// ─── GET /activity — activity feed ──────────────────────────

it('returns activity feed scoped to accessible projects', function (): void {
    Task::factory()->create(['project_id' => $this->project->id]);
    Task::factory()->create(['project_id' => $this->otherProject->id]);

    $response = $this->getJson('/api/v1/ext/activity', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters activity by type', function (): void {
    Task::factory()->create([
        'project_id' => $this->project->id,
        'type' => TaskType::CodeReview,
    ]);
    Task::factory()->create([
        'project_id' => $this->project->id,
        'type' => TaskType::FeatureDev,
    ]);

    $response = $this->getJson('/api/v1/ext/activity?type=feature_dev', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'feature_dev');
});

it('filters activity by project_id', function (): void {
    $secondProject = Project::factory()->create(['enabled' => true]);
    $secondProject->users()->attach($this->user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    Task::factory()->create(['project_id' => $this->project->id]);
    Task::factory()->create(['project_id' => $secondProject->id]);

    $response = $this->getJson('/api/v1/ext/activity?project_id='.$secondProject->id, $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('paginates activity with cursor', function (): void {
    Task::factory()->count(3)->create(['project_id' => $this->project->id]);

    $response = $this->getJson('/api/v1/ext/activity?per_page=2', $this->headers);

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('returns ActivityResource shape for activity feed', function (): void {
    Task::factory()->completed()->create(['project_id' => $this->project->id]);

    $response = $this->getJson('/api/v1/ext/activity', $this->headers);

    $response->assertOk()
        ->assertJsonStructure(['data' => [['task_id', 'type', 'status', 'project_id', 'project_name', 'created_at']]]);
});

// ─── GET /projects — list enabled projects ──────────────────

it('lists accessible projects via API key', function (): void {
    $response = $this->getJson('/api/v1/ext/projects', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('does not include projects the user cannot access', function (): void {
    $response = $this->getJson('/api/v1/ext/projects', $this->headers);

    $response->assertOk();

    $projectIds = collect($response->json('data'))->pluck('id');
    expect($projectIds)->not->toContain($this->otherProject->id);
});

// ─── Session auth on external endpoints ─────────────────────

it('allows session auth on all external endpoints', function (): void {
    Task::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/ext/tasks')
        ->assertOk();

    $this->actingAs($this->user)
        ->getJson('/api/v1/ext/metrics/summary')
        ->assertOk();

    $this->actingAs($this->user)
        ->getJson('/api/v1/ext/activity')
        ->assertOk();

    $this->actingAs($this->user)
        ->getJson('/api/v1/ext/projects')
        ->assertOk();
});
