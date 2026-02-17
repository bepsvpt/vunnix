<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finding_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mr_iid');

            // Finding identification (from task result)
            $table->string('finding_id');     // e.g. "1", "2" — the finding's id field
            $table->string('file');
            $table->unsignedInteger('line');
            $table->string('severity');       // critical, major, minor
            $table->string('title');

            // GitLab thread state
            $table->string('gitlab_discussion_id')->nullable(); // GitLab discussion ID
            $table->string('status')->default('pending');       // pending, accepted, accepted_auto, dismissed
            $table->timestamp('resolved_at')->nullable();

            // Code change correlation
            $table->boolean('code_change_correlated')->default(false);
            $table->string('correlated_commit_sha', 40)->nullable();

            // Bulk resolution detection (over-reliance signal D113)
            $table->boolean('bulk_resolved')->default(false);

            $table->timestamps();

            // Indexes
            $table->index(['project_id', 'mr_iid']);
            $table->index(['task_id', 'finding_id']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_acceptances');
    }
};
