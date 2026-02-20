<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();

            // GitLab's unique event delivery identifier (X-Gitlab-Event-UUID header)
            $table->uuid('gitlab_event_uuid');

            // Context
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('event_type'); // merge_request, note, issue, push
            $table->string('intent')->nullable(); // Resolved intent from EventRouter

            // GitLab context for superseding lookups
            $table->unsignedBigInteger('mr_iid')->nullable();
            $table->string('commit_sha', 40)->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Unique constraint: prevent duplicate event UUIDs per project
            $table->unique(['project_id', 'gitlab_event_uuid']);

            // Index for superseding lookups: find active tasks for same MR
            $table->index(['project_id', 'mr_iid']);
        });

        // Add superseded_by_id to tasks table for tracking which event superseded a task
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->unsignedBigInteger('superseded_by_id')->nullable()->after('status');
            });

            // Add composite index for dedup lookups: find existing tasks for same commit/MR
            Schema::table('tasks', function (Blueprint $table): void {
                $table->index(['project_id', 'mr_iid', 'status']);
                $table->index(['project_id', 'commit_sha']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropIndex(['project_id', 'mr_iid', 'status']);
                $table->dropIndex(['project_id', 'commit_sha']);
                $table->dropColumn('superseded_by_id');
            });
        }

        Schema::dropIfExists('webhook_events');
    }
};
