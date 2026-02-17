<?php

use App\Models\GlobalSetting;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Services\ProjectConfigService;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

// Create the agent_conversations table if needed (same beforeEach as other tests)
beforeEach(function (): void {
    if (! \Illuminate\Support\Facades\Schema::hasTable('agent_conversations')) {
        \Illuminate\Support\Facades\Schema::create('agent_conversations', function ($table): void {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! \Illuminate\Support\Facades\Schema::hasColumn('agent_conversations', 'project_id')) {
        \Illuminate\Support\Facades\Schema::table('agent_conversations', function ($table): void {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! \Illuminate\Support\Facades\Schema::hasTable('agent_conversation_messages')) {
        \Illuminate\Support\Facades\Schema::create('agent_conversation_messages', function ($table): void {
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

it('returns project override when set', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService;
    expect($service->get($project, 'ai_model'))->toBe('sonnet');
});

it('falls back to global setting when no project override', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    GlobalSetting::set('ai_model', 'haiku', 'string');

    $service = new ProjectConfigService;
    expect($service->get($project, 'ai_model'))->toBe('haiku');
});

it('falls back to hardcoded default when neither project nor global set', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService;
    // GlobalSetting::defaults() has ai_model => 'opus'
    expect($service->get($project, 'ai_model'))->toBe('opus');
});

it('returns explicit default when key has no value anywhere', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService;
    expect($service->get($project, 'nonexistent_key', 'fallback'))->toBe('fallback');
});

it('supports dot-notation for nested settings', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['code_review' => ['auto_review' => false]],
    ]);

    $service = new ProjectConfigService;
    expect($service->get($project, 'code_review.auto_review'))->toBe(false);
});

it('caches resolved settings per project', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService;
    $service->get($project, 'ai_model');

    // Second call should use cache
    expect(Cache::has("project_config:{$project->id}"))->toBeTrue();
});

it('invalidates cache when settings are updated', function (): void {
    $project = Project::factory()->create();
    $config = ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService;
    $service->get($project, 'ai_model'); // populate cache

    $service->set($project, 'ai_model', 'haiku');

    expect($service->get($project, 'ai_model'))->toBe('haiku');
});

it('returns all effective settings for a project', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet', 'timeout_minutes' => 20],
    ]);
    GlobalSetting::set('ai_language', 'ja', 'string');

    $service = new ProjectConfigService;
    $effective = $service->allEffective($project);

    expect($effective['ai_model'])->toEqual(['value' => 'sonnet', 'source' => 'project']);
    expect($effective['ai_language'])->toEqual(['value' => 'ja', 'source' => 'global']);
    expect($effective['timeout_minutes'])->toEqual(['value' => 20, 'source' => 'project']);
    expect($effective['max_tokens'])->toEqual(['value' => 8192, 'source' => 'default']);
});

it('removes a project override via set with null', function (): void {
    $project = Project::factory()->create();
    $config = ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService;
    $service->set($project, 'ai_model', null);

    // Should fall back to global/default
    expect($service->get($project, 'ai_model'))->toBe('opus');
});

// ─── T92: File config layer ─────────────────────────────────────

it('uses file config when no project override exists', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService;
    $result = $service->getWithFileConfig($project, 'ai_model', ['ai_model' => 'sonnet']);

    expect($result)->toBe('sonnet');
});

it('project DB override takes precedence over file config', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'haiku'],
    ]);

    $service = new ProjectConfigService;
    $result = $service->getWithFileConfig($project, 'ai_model', ['ai_model' => 'sonnet']);

    expect($result)->toBe('haiku');
});

it('file config takes precedence over global setting', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    GlobalSetting::set('ai_model', 'haiku', 'string');

    $service = new ProjectConfigService;
    $result = $service->getWithFileConfig($project, 'ai_model', ['ai_model' => 'sonnet']);

    expect($result)->toBe('sonnet');
});

it('file config for nested key works with dot-notation', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService;
    $result = $service->getWithFileConfig($project, 'code_review.auto_review', [
        'code_review.auto_review' => false,
    ]);

    expect($result)->toBe(false);
});

// ─── T93: PRD template setting key ──────────────────────────────

it('includes prd_template in setting keys', function (): void {
    $keys = ProjectConfigService::settingKeys();
    expect($keys)->toHaveKey('prd_template');
    expect($keys['prd_template'])->toBe('text');
});

it('allEffective includes file source indicator', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'haiku'], // DB override
    ]);

    $service = new ProjectConfigService;
    $effective = $service->allEffective($project, [
        'code_review.auto_review' => false,  // file config (no DB override)
        'ai_model' => 'sonnet',              // file config (but DB overrides)
    ]);

    // DB override wins over file
    expect($effective['ai_model'])->toEqual(['value' => 'haiku', 'source' => 'project']);
    // File config used (no DB override)
    expect($effective['code_review.auto_review'])->toEqual(['value' => false, 'source' => 'file']);
});
