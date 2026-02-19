<?php

use App\Models\GlobalSetting;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

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

function createSettingsAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Can edit global Vunnix settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

function createNonSettingsAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $perm = Permission::firstOrCreate(
        ['name' => 'chat.access'],
        ['description' => 'Chat access', 'group' => 'chat']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

// ─── GET /admin/settings ────────────────────────────────────────

it('returns settings list for admin', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    GlobalSetting::set('ai_model', 'opus', 'string', 'Default AI model');
    GlobalSetting::set('ai_language', 'en', 'string', 'AI response language');

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['key', 'value', 'type', 'description'],
            ],
            'api_key_configured',
            'defaults',
        ]);
});

it('includes api_key_configured status', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertOk()
        ->assertJsonPath('api_key_configured', fn ($v): bool => is_bool($v));
});

it('includes defaults for reference', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertOk()
        ->assertJsonPath('defaults.ai_model', 'opus')
        ->assertJsonPath('defaults.ai_language', 'en')
        ->assertJsonPath('defaults.timeout_minutes', 10)
        ->assertJsonPath('defaults.max_tokens', 8192);
});

it('returns 403 for non-admin user', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createNonSettingsAdmin($project);

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertForbidden();
});

it('returns 401 for unauthenticated request', function (): void {
    $response = $this->getJson('/api/v1/admin/settings');

    $response->assertUnauthorized();
});

// ─── PUT /admin/settings ────────────────────────────────────────

it('updates a single setting', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect(GlobalSetting::get('ai_model'))->toBe('sonnet');
});

it('updates multiple settings at once', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
            ['key' => 'timeout_minutes', 'value' => 15, 'type' => 'integer'],
            ['key' => 'ai_language', 'value' => 'ja', 'type' => 'string'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect(GlobalSetting::get('ai_model'))->toBe('sonnet');
    expect(GlobalSetting::get('timeout_minutes'))->toBe(15);
    expect(GlobalSetting::get('ai_language'))->toBe('ja');
});

it('updates json-type settings', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_prices', 'value' => ['input' => 3.0, 'output' => 15.0], 'type' => 'json'],
        ],
    ]);

    $response->assertOk();

    $prices = GlobalSetting::get('ai_prices');
    expect($prices)->toBeArray();
    expect($prices['input'])->toEqual(3.0);
    expect($prices['output'])->toEqual(15.0);
});

it('updates bot_pat_created_at via settings', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'bot_pat_created_at', 'value' => '2026-01-15T00:00:00Z'],
        ],
    ]);

    $response->assertOk();

    $setting = GlobalSetting::where('key', 'bot_pat_created_at')->first();
    expect($setting)->not->toBeNull();
});

it('updates team chat webhook settings', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'team_chat_webhook_url', 'value' => 'https://hooks.slack.com/services/T00/B00/xxx', 'type' => 'string'],
            ['key' => 'team_chat_platform', 'value' => 'slack', 'type' => 'string'],
        ],
    ]);

    $response->assertOk();
    expect(GlobalSetting::get('team_chat_webhook_url'))->toBe('https://hooks.slack.com/services/T00/B00/xxx');
    expect(GlobalSetting::get('team_chat_platform'))->toBe('slack');
});

it('rejects update with empty settings array', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [],
    ]);

    $response->assertUnprocessable();
});

it('rejects update without settings key', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', []);

    $response->assertUnprocessable();
});

it('returns 403 for non-admin on update', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createNonSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
        ],
    ]);

    $response->assertForbidden();
});

it('returns updated settings list after update', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['key', 'value', 'type'],
            ],
        ]);
});

// ─── Integration: settings update → config reads ────────────────

it('change AI model → GlobalSetting::get returns new value', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    // Set initial value
    GlobalSetting::set('ai_model', 'opus', 'string');

    // Update via API
    $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
        ],
    ])->assertOk();

    // Verify model reads new value (cache should be invalidated)
    expect(GlobalSetting::get('ai_model'))->toBe('sonnet');
});

it('change language → GlobalSetting::get returns new language', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    GlobalSetting::set('ai_language', 'en', 'string');

    $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_language', 'value' => 'ja', 'type' => 'string'],
        ],
    ])->assertOk();

    expect(GlobalSetting::get('ai_language'))->toBe('ja');
});

it('settings persist across index calls', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    // Set settings
    $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'haiku', 'type' => 'string'],
            ['key' => 'timeout_minutes', 'value' => 20, 'type' => 'integer'],
        ],
    ])->assertOk();

    // Fetch and verify
    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');
    $response->assertOk();

    $data = collect($response->json('data'));
    $model = $data->firstWhere('key', 'ai_model');
    $timeout = $data->firstWhere('key', 'timeout_minutes');

    expect($model['value'])->toBe('haiku');
    expect($timeout['value'])->toBe(20);
});

it('continues settings update when audit logging fails', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createSettingsAdmin($project);

    $this->mock(AuditLogService::class)
        ->shouldReceive('logConfigurationChange')
        ->andThrow(new RuntimeException('audit failed'));

    $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
        ],
    ])->assertOk()->assertJsonPath('success', true);
});
