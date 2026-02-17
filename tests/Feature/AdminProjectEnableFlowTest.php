<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Ensure agent_conversations table exists (SQLite test env — AI SDK migration sorts after ours)
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

    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-bot-token']);
    config(['services.gitlab.bot_account_id' => 99]);
    config(['services.gitlab.vunnix_project_id' => 100]);
    config(['app.url' => 'https://vunnix.example.com']);
});

function setupAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Admin', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

it('creates webhook with correct URL, secret, and events on enable', function (): void {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = setupAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99, 'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100, 'visibility' => 'internal',
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 777,
        ], 201),
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
        ], 201),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertOk();

    // Verify webhook creation request
    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/hooks') || $request->method() !== 'POST') {
            return false;
        }

        $data = $request->data();

        return str_contains($data['url'] ?? '', '/webhook')
            && ! empty($data['token'])
            && ($data['merge_requests_events'] ?? false) === true
            && ($data['note_events'] ?? false) === true
            && ($data['issues_events'] ?? false) === true
            && ($data['push_events'] ?? false) === true;
    });

    // Verify DB state
    $project->refresh();
    expect($project->enabled)->toBeTrue();
    expect($project->webhook_configured)->toBeTrue();
    expect($project->webhook_id)->toBe(777);
    expect($project->projectConfig->webhook_secret)->not->toBeNull();
});

it('creates all 6 ai:: labels with correct names and colors', function (): void {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = setupAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99, 'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100, 'visibility' => 'internal',
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
        ], 201),
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
        ], 201),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertOk();

    $expectedLabels = [
        'ai::reviewed', 'ai::risk-high', 'ai::risk-medium',
        'ai::risk-low', 'ai::security', 'ai::created',
    ];

    $labelRequests = collect(Http::recorded())
        ->filter(fn ($pair): bool => str_contains($pair[0]->url(), '/labels') &&
            $pair[0]->method() === 'POST'
        )
        ->map(fn ($pair) => $pair[0]->data()['name']);

    foreach ($expectedLabels as $labelName) {
        expect($labelRequests->contains($labelName))->toBeTrue("Expected label {$labelName} to be created");
    }

    expect($labelRequests)->toHaveCount(6);
});

it('handles already-existing labels without error', function (): void {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = setupAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99, 'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100, 'visibility' => 'internal',
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
        ], 201),
        // All labels already exist
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response(
            ['message' => 'Label already exists'],
            409
        ),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('removes webhook and preserves data on disable', function (): void {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => true,
        'webhook_configured' => true,
        'webhook_id' => 555,
    ]);
    $admin = setupAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/projects/42/hooks/555' => Http::response(null, 204),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/disable")
        ->assertOk();

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/hooks/555') && $req->method() === 'DELETE'
    );

    $project->refresh();
    expect($project->enabled)->toBeFalse();
    expect($project->webhook_id)->toBeNull();
    // Project still exists (data preserved — D60)
    expect(Project::find($project->id))->not->toBeNull();
});
