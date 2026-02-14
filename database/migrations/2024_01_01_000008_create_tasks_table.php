<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            // Type & origin
            $table->string('type'); // code_review, issue_discussion, feature_dev, ui_adjustment, prd_creation, etc.
            $table->string('origin'); // webhook, conversation

            // Ownership
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Priority & status
            $table->string('priority')->default('normal'); // high, normal, low
            $table->string('status')->default('received'); // received, queued, running, completed, failed, superseded

            // GitLab context (nullable — not all tasks originate from GitLab events)
            $table->unsignedBigInteger('mr_iid')->nullable();
            $table->unsignedBigInteger('issue_iid')->nullable();
            $table->unsignedBigInteger('comment_id')->nullable();
            $table->string('commit_sha', 40)->nullable();

            // Conversation context (nullable — only for Path B tasks)
            $table->string('conversation_id', 36)->nullable();

            // Claude data
            $table->text('prompt')->nullable();
            $table->text('response')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->string('model')->nullable();

            // Structured result & prompt versioning (JSONB)
            $table->jsonb('result')->nullable();
            $table->jsonb('prompt_version')->nullable();

            // Cost
            $table->decimal('cost', 10, 6)->nullable();

            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes per spec: tasks(project_id, status), tasks(created_at)
            $table->index(['project_id', 'status']);
            $table->index('created_at');
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
