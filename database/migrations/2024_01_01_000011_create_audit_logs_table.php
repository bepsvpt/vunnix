<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Event classification
            $table->string('event_type'); // conversation_turn, task_execution, action_dispatch, configuration_change, webhook_received, auth_event

            // Actor (nullable — webhook and system events may have no user)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Related entities (nullable — depends on event type)
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->string('conversation_id', 36)->nullable();

            // Full content — never truncated per D98
            $table->text('summary'); // Human-readable event summary
            $table->jsonb('properties')->nullable(); // Structured event data (prompts, responses, tool calls, tokens, cost, old/new values, etc.)

            // Source context
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('created_at'); // Time-range queries per spec
            $table->index('event_type');
            $table->index('project_id');
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
