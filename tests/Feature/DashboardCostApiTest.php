<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─── Setup ─────────────────────────────────────────────────────

beforeEach(function (): void {
    if (! Schema::hasTable('agent_conversations')) {
        Schema::create('agent_conversations', function ($table): void {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! Schema::hasColumn('agent_conversations', 'project_id')) {
        Schema::table('agent_conversations', function ($table): void {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        Schema::create('agent_conversation_messages', function ($table): void {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->foreignId('user_id');
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->timestamps();
        });
    }
});

/**
 * Helper: create an admin user with admin.global_config permission on a project.
 */
function createAdminUser(Project $project): User
{
    $user = User::factory()->create();
    grantGlobalConfigPermission($user, $project);

    return $user;
}

function grantGlobalConfigPermission(User $user, Project $project): void
{
    $project->users()->syncWithoutDetaching([$user->id => ['gitlab_access_level' => 30, 'synced_at' => now()]]);

    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Can edit global Vunnix settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);
}

/**
 * Helper: create a non-admin user on a project (no admin permissions).
 */
function createRegularUser(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $perm = Permission::firstOrCreate(
        ['name' => 'review.view'],
        ['description' => 'Can view AI review results', 'group' => 'review']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

// ─── Authorization Tests ──────────────────────────────────────

it('returns 401 for unauthenticated users', function (): void {
    $response = $this->getJson('/api/v1/dashboard/cost');
    $response->assertUnauthorized();
});

it('returns 403 for non-admin users', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createRegularUser($project);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');
    $response->assertForbidden();
});

it('allows admin users to access cost data', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');
    $response->assertOk();
});

// ─── Response Structure ───────────────────────────────────────

it('returns correct response structure', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'total_cost',
            'total_tokens',
            'token_usage_by_type',
            'cost_per_type',
            'cost_per_project',
            'monthly_trend',
        ],
    ]);
});

it('returns empty data when no tasks exist', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    $response->assertJsonPath('data.total_cost', 0);
    $response->assertJsonPath('data.total_tokens', 0);
    $response->assertJsonPath('data.token_usage_by_type', []);
    $response->assertJsonPath('data.cost_per_type', []);
    $response->assertJsonPath('data.cost_per_project', []);
    $response->assertJsonPath('data.monthly_trend', []);
});

// ─── Token Usage by Type ──────────────────────────────────────

it('returns token usage grouped by task type', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'tokens_used' => 50000,
        'cost' => 1.50,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'tokens_used' => 30000,
        'cost' => 0.90,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'tokens_used' => 100000,
        'cost' => 3.00,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    $response->assertJsonPath('data.token_usage_by_type.code_review', 80000);
    $response->assertJsonPath('data.token_usage_by_type.feature_dev', 100000);
});

// ─── Cost per Type ────────────────────────────────────────────

it('returns cost per task type with avg and total', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'tokens_used' => 50000,
        'cost' => 1.00,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'tokens_used' => 30000,
        'cost' => 2.00,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    // avg: (1.00 + 2.00) / 2 = 1.50, total: 3.00, count: 2
    $response->assertJsonPath('data.cost_per_type.code_review.avg_cost', 1.5);
    $response->assertJsonPath('data.cost_per_type.code_review.total_cost', 3);
    $response->assertJsonPath('data.cost_per_type.code_review.task_count', 2);
});

// ─── Cost per Project ─────────────────────────────────────────

it('returns cost grouped by project', function (): void {
    $projectA = Project::factory()->enabled()->create(['name' => 'Project Alpha']);
    $projectB = Project::factory()->enabled()->create(['name' => 'Project Beta']);
    $user = createAdminUser($projectA);
    grantGlobalConfigPermission($user, $projectB);

    Task::factory()->create([
        'project_id' => $projectA->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'cost' => 1.50,
    ]);

    Task::factory()->create([
        'project_id' => $projectB->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'cost' => 2.50,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    $costPerProject = $response->json('data.cost_per_project');
    expect($costPerProject)->toHaveCount(2);

    $alphaEntry = collect($costPerProject)->firstWhere('project_name', 'Project Alpha');
    $betaEntry = collect($costPerProject)->firstWhere('project_name', 'Project Beta');

    expect($alphaEntry['total_cost'])->toBe(1.5)
        ->and($alphaEntry['task_count'])->toBe(1)
        ->and($betaEntry['total_cost'])->toBe(2.5)
        ->and($betaEntry['task_count'])->toBe(1);
});

// ─── Project Scoping ──────────────────────────────────────────

it('denies cost dashboard when user is not global admin', function (): void {
    $project = Project::factory()->enabled()->create();
    $otherProject = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'tokens_used' => 10000,
        'cost' => 1.00,
    ]);

    // Task in other project — user has no access
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'tokens_used' => 90000,
        'cost' => 9.00,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertForbidden();
});

it('excludes tasks from disabled projects', function (): void {
    $project = Project::factory()->enabled()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $user = createAdminUser($project);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'cost' => 1.00,
    ]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'cost' => 5.00,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    $response->assertJsonPath('data.total_cost', 1);
});

// ─── Total Summaries ──────────────────────────────────────────

it('computes total cost and tokens correctly', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'tokens_used' => 50000,
        'cost' => 1.50,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'tokens_used' => 100000,
        'cost' => 3.00,
    ]);

    // Failed task — tokens counted, cost not counted (null cost)
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'status' => TaskStatus::Failed,
        'tokens_used' => 20000,
        'cost' => null,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    // total_cost: only completed tasks with cost = 1.50 + 3.00 = 4.50
    $response->assertJsonPath('data.total_cost', 4.5);
    // total_tokens: completed + failed = 50000 + 100000 + 20000 = 170000
    $response->assertJsonPath('data.total_tokens', 170000);
});

// ─── Null Cost/Token Handling ─────────────────────────────────

it('ignores tasks with null tokens_used in token aggregation', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'tokens_used' => 50000,
        'cost' => 1.00,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'tokens_used' => null,
        'cost' => 0.50,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    $response->assertJsonPath('data.token_usage_by_type.code_review', 50000);
});

// ─── Materialized View / task_metrics Path ────────────────────

it('uses pre-aggregated metrics when task_metrics data exists', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    // Create tasks for task_metrics foreign key constraint
    $task1 = Task::factory()->create(['project_id' => $project->id]);
    $task2 = Task::factory()->create(['project_id' => $project->id]);

    // Seed task_metrics — this makes MetricsQueryService::byType() return data,
    // which triggers the fromMaterializedViews() code path in the controller
    DB::table('task_metrics')->insert([
        [
            'task_id' => $task1->id,
            'project_id' => $project->id,
            'task_type' => 'code_review',
            'input_tokens' => 10000,
            'output_tokens' => 5000,
            'cost' => 1.50,
            'duration' => 45,
            'severity_critical' => 1,
            'severity_high' => 2,
            'severity_medium' => 3,
            'severity_low' => 0,
            'findings_count' => 6,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ],
        [
            'task_id' => $task2->id,
            'project_id' => $project->id,
            'task_type' => 'feature_dev',
            'input_tokens' => 20000,
            'output_tokens' => 10000,
            'cost' => 3.00,
            'duration' => 120,
            'severity_critical' => 0,
            'severity_high' => 0,
            'severity_medium' => 0,
            'severity_low' => 0,
            'findings_count' => 0,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ],
    ]);

    // Refresh materialized views so PostgreSQL CI sees the seeded data
    $this->artisan('metrics:aggregate');

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'total_cost',
            'total_tokens',
            'token_usage_by_type',
            'cost_per_type',
            'cost_per_project',
            'monthly_trend',
        ],
    ]);

    // Verify token_usage_by_type from task_metrics aggregation
    // code_review: input_tokens(10000) + output_tokens(5000) = 15000
    // feature_dev: input_tokens(20000) + output_tokens(10000) = 30000
    $response->assertJsonPath('data.token_usage_by_type.code_review', 15000);
    $response->assertJsonPath('data.token_usage_by_type.feature_dev', 30000);

    // Verify cost_per_type
    $data = $response->json('data');
    expect($data['cost_per_type'])->toHaveKey('code_review');
    expect($data['cost_per_type']['code_review']['task_count'])->toBe(1);
    expect((float) $data['cost_per_type']['code_review']['total_cost'])->toBe(1.5);
    expect((float) $data['cost_per_type']['code_review']['avg_cost'])->toBe(1.5);

    expect($data['cost_per_type'])->toHaveKey('feature_dev');
    expect($data['cost_per_type']['feature_dev']['task_count'])->toBe(1);
    expect((float) $data['cost_per_type']['feature_dev']['total_cost'])->toEqual(3.0);

    // Verify cost_per_project (both task_metrics rows belong to same project)
    expect($data['cost_per_project'])->toHaveCount(1);
    expect($data['cost_per_project'][0]['project_name'])->toBe($project->name);
    expect($data['cost_per_project'][0]['total_cost'])->toBe(4.5);
    expect($data['cost_per_project'][0]['task_count'])->toBe(2);

    // Verify totals come from byProject aggregation
    $response->assertJsonPath('data.total_cost', 4.5);
    $response->assertJsonPath('data.total_tokens', 45000);

    // Verify monthly_trend from byPeriod aggregation
    expect($data['monthly_trend'])->toBeArray();
    expect($data['monthly_trend'])->not->toBeEmpty();
});

it('returns correct monthly trend from task_metrics data', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    $task1 = Task::factory()->create(['project_id' => $project->id]);
    $task2 = Task::factory()->create(['project_id' => $project->id]);

    // Two metrics in different months
    DB::table('task_metrics')->insert([
        [
            'task_id' => $task1->id,
            'project_id' => $project->id,
            'task_type' => 'code_review',
            'input_tokens' => 5000,
            'output_tokens' => 2000,
            'cost' => 1.00,
            'duration' => 30,
            'severity_critical' => 0,
            'severity_high' => 0,
            'severity_medium' => 0,
            'severity_low' => 0,
            'findings_count' => 0,
            'created_at' => now()->subMonth()->startOfMonth()->addDay(),
            'updated_at' => now()->subMonth()->startOfMonth()->addDay(),
        ],
        [
            'task_id' => $task2->id,
            'project_id' => $project->id,
            'task_type' => 'code_review',
            'input_tokens' => 8000,
            'output_tokens' => 3000,
            'cost' => 2.00,
            'duration' => 60,
            'severity_critical' => 0,
            'severity_high' => 0,
            'severity_medium' => 0,
            'severity_low' => 0,
            'findings_count' => 0,
            'created_at' => now()->startOfMonth()->addDay(),
            'updated_at' => now()->startOfMonth()->addDay(),
        ],
    ]);

    // Refresh materialized views so PostgreSQL CI sees the seeded data
    $this->artisan('metrics:aggregate');

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertOk();
    $data = $response->json('data');

    // Should have 2 monthly entries sorted by month
    expect($data['monthly_trend'])->toHaveCount(2);
    expect($data['monthly_trend'][0]['month'])->toBeLessThan($data['monthly_trend'][1]['month']);

    // First month: cost 1.00, tokens 7000, count 1
    expect((float) $data['monthly_trend'][0]['total_cost'])->toEqual(1.0);
    expect($data['monthly_trend'][0]['total_tokens'])->toBe(7000);
    expect($data['monthly_trend'][0]['task_count'])->toBe(1);

    // Second month: cost 2.00, tokens 11000, count 1
    expect((float) $data['monthly_trend'][1]['total_cost'])->toEqual(2.0);
    expect($data['monthly_trend'][1]['total_tokens'])->toBe(11000);
    expect($data['monthly_trend'][1]['task_count'])->toBe(1);
});

it('denies task_metrics-backed cost dashboard when user is not global admin', function (): void {
    $project = Project::factory()->enabled()->create();
    $otherProject = Project::factory()->enabled()->create();
    $user = createAdminUser($project);

    $task1 = Task::factory()->create(['project_id' => $project->id]);
    $task2 = Task::factory()->create(['project_id' => $otherProject->id]);

    DB::table('task_metrics')->insert([
        [
            'task_id' => $task1->id,
            'project_id' => $project->id,
            'task_type' => 'code_review',
            'input_tokens' => 5000,
            'output_tokens' => 2000,
            'cost' => 1.00,
            'duration' => 30,
            'severity_critical' => 0,
            'severity_high' => 0,
            'severity_medium' => 0,
            'severity_low' => 0,
            'findings_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'task_id' => $task2->id,
            'project_id' => $otherProject->id,
            'task_type' => 'code_review',
            'input_tokens' => 50000,
            'output_tokens' => 20000,
            'cost' => 9.00,
            'duration' => 300,
            'severity_critical' => 0,
            'severity_high' => 0,
            'severity_medium' => 0,
            'severity_low' => 0,
            'findings_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Refresh materialized views so PostgreSQL CI sees the seeded data
    $this->artisan('metrics:aggregate');

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/cost');

    $response->assertForbidden();
});
