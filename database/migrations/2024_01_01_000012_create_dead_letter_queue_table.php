<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dead_letter_queue', function (Blueprint $table): void {
            $table->id();

            // Original task snapshot — full JSONB copy of the task at failure time
            $table->jsonb('task_record');

            // Failure classification
            $table->string('failure_reason'); // max_retries_exceeded, expired, invalid_request, context_exceeded, scheduling_timeout

            // Error context
            $table->text('error_details')->nullable(); // Last error message, HTTP status, response body

            // Retry history — JSONB array of {attempt, timestamp, error}
            $table->jsonb('attempts')->default('[]');

            // Admin actions
            $table->boolean('dismissed')->default(false);
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();

            // Timeline
            $table->timestamp('originally_queued_at'); // When the task was first queued
            $table->timestamp('dead_lettered_at'); // When it entered the DLQ
            $table->timestamps();

            // Indexes for admin browsing
            $table->index('failure_reason');
            $table->index('dead_lettered_at');
            $table->index('dismissed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letter_queue');
    }
};
