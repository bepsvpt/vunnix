<?php

use App\Events\TaskStatusChanged;
use App\Listeners\DeliverTaskResultToConversation;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The SDK migration creates agent_conversations and agent_conversation_messages,
    // but with a 2026 timestamp that may sort after our migrations. Create the tables
    // with all columns if they don't exist yet.
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

test('inserts system message into conversation when task completes', function () {
    $conversation = Conversation::factory()->create();
    $task = Task::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => 'feature_dev',
        'status' => 'completed',
        'result' => ['mr_title' => 'Add payment'],
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $systemMsg = Message::where('conversation_id', $conversation->id)
        ->where('role', 'system')
        ->latest('created_at')
        ->first();

    expect($systemMsg)->not->toBeNull();
    expect($systemMsg->content)->toContain('[System: Task result delivered]');
    expect($systemMsg->content)->toContain("Task #{$task->id}");
});

test('does not insert message for non-terminal tasks', function () {
    $conversation = Conversation::factory()->create();
    $task = Task::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => 'feature_dev',
        'status' => 'running',
        'started_at' => now(),
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $count = Message::where('conversation_id', $conversation->id)
        ->where('role', 'system')
        ->count();

    expect($count)->toBe(0);
});

test('does not insert message for tasks without a conversation', function () {
    $task = Task::factory()->create([
        'conversation_id' => null,
        'type' => 'code_review',
        'status' => 'completed',
        'result' => ['summary' => 'Looks good'],
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $count = Message::where('role', 'system')
        ->where('content', 'like', '%Task result delivered%')
        ->count();
    expect($count)->toBe(0);
});

test('includes MR link and branch info in system message for feature_dev tasks', function () {
    $conversation = Conversation::factory()->create();
    $task = Task::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => 'ui_adjustment',
        'status' => 'completed',
        'mr_iid' => 456,
        'result' => [
            'mr_title' => 'Fix card padding',
            'branch' => 'ai/fix-card-padding',
            'target_branch' => 'main',
            'files_changed' => [
                ['path' => 'styles/card.css', 'action' => 'modified', 'summary' => 'Padding fix'],
            ],
        ],
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $systemMsg = Message::where('conversation_id', $conversation->id)
        ->where('role', 'system')
        ->latest('created_at')
        ->first();

    expect($systemMsg->content)->toContain('!456');
    expect($systemMsg->content)->toContain('ai/fix-card-padding');
    expect($systemMsg->content)->toContain('[System: Task result delivered]');
});

test('includes result summary and files count in system message', function () {
    $conversation = Conversation::factory()->create();
    $task = Task::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => 'feature_dev',
        'status' => 'completed',
        'mr_iid' => 123,
        'result' => [
            'mr_title' => 'Add payment flow',
            'branch' => 'ai/payment-feature',
            'files_changed' => [
                ['path' => 'app/Payment.php', 'action' => 'created', 'summary' => 'Payment controller'],
                ['path' => 'app/Stripe.php', 'action' => 'created', 'summary' => 'Stripe service'],
            ],
            'notes' => 'Implemented Stripe checkout with webhooks',
        ],
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $systemMsg = Message::where('conversation_id', $conversation->id)
        ->where('role', 'system')
        ->first();

    expect($systemMsg->content)->toContain('!123');
    expect($systemMsg->content)->toContain('2 files changed');
});

test('includes task title in system message for failed tasks', function () {
    $conversation = Conversation::factory()->create();
    $task = Task::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => 'feature_dev',
        'status' => 'failed',
        'error_reason' => 'Pipeline timeout',
        'result' => ['mr_title' => 'Implement auth'],
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $systemMsg = Message::where('conversation_id', $conversation->id)
        ->where('role', 'system')
        ->first();

    expect($systemMsg->content)->toContain('"Implement auth"');
    expect($systemMsg->content)->toContain('failed');
});
