<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Task classification
            $table->string('task_type'); // code_review, issue_discussion, feature_dev, etc.

            // Token & cost tracking
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 10, 6)->default(0);

            // Duration (seconds)
            $table->unsignedInteger('duration')->default(0);

            // Review-specific metrics (nullable for non-review tasks)
            $table->unsignedSmallInteger('severity_critical')->default(0);
            $table->unsignedSmallInteger('severity_high')->default(0);
            $table->unsignedSmallInteger('severity_medium')->default(0);
            $table->unsignedSmallInteger('severity_low')->default(0);
            $table->unsignedSmallInteger('findings_count')->default(0);

            $table->timestamps();

            // Indexes for dashboard aggregation queries
            $table->index(['project_id', 'task_type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_metrics');
    }
};
