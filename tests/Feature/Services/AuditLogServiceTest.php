<?php

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure agent_conversations table exists (required by migrations ordering)
    if (! Schema::hasTable('agent_conversations')) {
        Schema::create('agent_conversations', function ($table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! Schema::hasColumn('agent_conversations', 'project_id')) {
        Schema::table('agent_conversations', function ($table) {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        Schema::create('agent_conversation_messages', function ($table) {
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

it('logs a conversation turn with full content', function () {
    $user = User::factory()->create();
    $service = new AuditLogService();

    $service->logConversationTurn(
        userId: $user->id,
        conversationId: 'conv-abc-123',
        userMessage: 'Review my code please',
        aiResponse: 'I found 2 issues in your code.',
        toolCalls: [['name' => 'ReadFile', 'result' => 'contents...']],
        tokensUsed: 250,
        model: 'claude-opus-4-6',
    );

    $log = AuditLog::where('event_type', 'conversation_turn')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->conversation_id)->toBe('conv-abc-123');
    expect($log->properties['user_message'])->toBe('Review my code please');
    expect($log->properties['ai_response'])->toBe('I found 2 issues in your code.');
    expect($log->properties['tool_calls'])->toHaveCount(1);
    expect($log->properties['tokens_used'])->toBe(250);
    expect($log->properties['model'])->toBe('claude-opus-4-6');
});

it('logs a task execution with prompt, response, cost', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $service = new AuditLogService();

    $service->logTaskExecution(
        taskId: 99,
        userId: $user->id,
        projectId: $project->id,
        taskType: 'code_review',
        gitlabContext: ['mr_iid' => 42],
        promptSent: 'Review this merge request diff...',
        aiResponse: 'Found 3 issues with severity high.',
        tokensUsed: 500,
        cost: 0.015,
        durationSeconds: 45,
        resultStatus: 'completed',
    );

    $log = AuditLog::where('event_type', 'task_execution')->first();

    expect($log)->not->toBeNull();
    expect($log->task_id)->toBe(99);
    expect($log->user_id)->toBe($user->id);
    expect($log->project_id)->toBe($project->id);
    expect($log->properties['prompt_sent'])->toBe('Review this merge request diff...');
    expect($log->properties['ai_response'])->toBe('Found 3 issues with severity high.');
    expect($log->properties['cost'])->toBe(0.015);
    expect($log->properties['duration_seconds'])->toBe(45);
    expect($log->properties['result_status'])->toBe('completed');
});

it('logs a configuration change with old and new values', function () {
    $user = User::factory()->create();
    $service = new AuditLogService();

    $service->logConfigurationChange(
        userId: $user->id,
        key: 'ai_model',
        oldValue: 'claude-opus-4-6',
        newValue: 'claude-sonnet-4-20250514',
    );

    $log = AuditLog::where('event_type', 'configuration_change')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->properties['key'])->toBe('ai_model');
    expect($log->properties['old_value'])->toBe('claude-opus-4-6');
    expect($log->properties['new_value'])->toBe('claude-sonnet-4-20250514');
});

it('logs an action dispatch', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $service = new AuditLogService();

    $service->logActionDispatch(
        userId: $user->id,
        conversationId: 'conv-xyz-789',
        actionType: 'code_review',
        projectId: $project->id,
        gitlabArtifactUrl: 'https://gitlab.example.com/project/-/merge_requests/42',
    );

    $log = AuditLog::where('event_type', 'action_dispatch')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->conversation_id)->toBe('conv-xyz-789');
    expect($log->project_id)->toBe($project->id);
    expect($log->properties['action_type'])->toBe('code_review');
    expect($log->properties['gitlab_artifact_url'])->toContain('merge_requests/42');
});

it('logs a webhook received event', function () {
    $project = Project::factory()->create();
    $service = new AuditLogService();

    $service->logWebhookReceived(
        projectId: $project->id,
        eventType: 'merge_request',
        relevantIds: ['mr_iid' => 42, 'action' => 'open'],
    );

    $log = AuditLog::where('event_type', 'webhook_received')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBeNull(); // webhooks have no user
    expect($log->project_id)->toBe($project->id);
    expect($log->properties['gitlab_event_type'])->toBe('merge_request');
    expect($log->properties['relevant_ids'])->toBe(['mr_iid' => 42, 'action' => 'open']);
});

it('logs an auth event', function () {
    $user = User::factory()->create();
    $service = new AuditLogService();

    $service->logAuthEvent(
        userId: $user->id,
        action: 'login',
        ipAddress: '192.168.1.100',
        userAgent: 'Mozilla/5.0',
    );

    $log = AuditLog::where('event_type', 'auth_event')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->properties['action'])->toBe('login');
    expect($log->ip_address)->toBe('192.168.1.100');
    expect($log->user_agent)->toBe('Mozilla/5.0');
});
