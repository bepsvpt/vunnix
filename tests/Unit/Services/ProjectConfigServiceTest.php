<?php

use App\Models\GlobalSetting;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Services\ProjectConfigService;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

// Create the agent_conversations table if needed (same beforeEach as other tests)
beforeEach(function () {
    if (! \Illuminate\Support\Facades\Schema::hasTable('agent_conversations')) {
        \Illuminate\Support\Facades\Schema::create('agent_conversations', function ($table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! \Illuminate\Support\Facades\Schema::hasColumn('agent_conversations', 'project_id')) {
        \Illuminate\Support\Facades\Schema::table('agent_conversations', function ($table) {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! \Illuminate\Support\Facades\Schema::hasTable('agent_conversation_messages')) {
        \Illuminate\Support\Facades\Schema::create('agent_conversation_messages', function ($table) {
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

it('returns project override when set', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService();
    expect($service->get($project, 'ai_model'))->toBe('sonnet');
});

it('falls back to global setting when no project override', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    GlobalSetting::set('ai_model', 'haiku', 'string');

    $service = new ProjectConfigService();
    expect($service->get($project, 'ai_model'))->toBe('haiku');
});

it('falls back to hardcoded default when neither project nor global set', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService();
    // GlobalSetting::defaults() has ai_model => 'opus'
    expect($service->get($project, 'ai_model'))->toBe('opus');
});

it('returns explicit default when key has no value anywhere', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService();
    expect($service->get($project, 'nonexistent_key', 'fallback'))->toBe('fallback');
});

it('supports dot-notation for nested settings', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['code_review' => ['auto_review' => false]],
    ]);

    $service = new ProjectConfigService();
    expect($service->get($project, 'code_review.auto_review'))->toBe(false);
});

it('caches resolved settings per project', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService();
    $service->get($project, 'ai_model');

    // Second call should use cache
    expect(Cache::has("project_config:{$project->id}"))->toBeTrue();
});

it('invalidates cache when settings are updated', function () {
    $project = Project::factory()->create();
    $config = ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService();
    $service->get($project, 'ai_model'); // populate cache

    $service->set($project, 'ai_model', 'haiku');

    expect($service->get($project, 'ai_model'))->toBe('haiku');
});

it('returns all effective settings for a project', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet', 'timeout_minutes' => 20],
    ]);
    GlobalSetting::set('ai_language', 'ja', 'string');

    $service = new ProjectConfigService();
    $effective = $service->allEffective($project);

    expect($effective['ai_model'])->toEqual(['value' => 'sonnet', 'source' => 'project']);
    expect($effective['ai_language'])->toEqual(['value' => 'ja', 'source' => 'global']);
    expect($effective['timeout_minutes'])->toEqual(['value' => 20, 'source' => 'project']);
    expect($effective['max_tokens'])->toEqual(['value' => 8192, 'source' => 'default']);
});

it('removes a project override via set with null', function () {
    $project = Project::factory()->create();
    $config = ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService();
    $service->set($project, 'ai_model', null);

    // Should fall back to global/default
    expect($service->get($project, 'ai_model'))->toBe('opus');
});
