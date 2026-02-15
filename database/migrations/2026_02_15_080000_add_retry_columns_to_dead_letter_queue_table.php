<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('dead_letter_queue', function (Blueprint $table) {
            // Direct FK to the original task for easy lookup
            $table->foreignId('task_id')->nullable()->after('id')->constrained('tasks')->nullOnDelete();

            // Retry tracking
            $table->boolean('retried')->default(false)->after('dismissed_by');
            $table->timestamp('retried_at')->nullable()->after('retried');
            $table->foreignId('retried_by')->nullable()->after('retried_at')->constrained('users')->nullOnDelete();
            $table->foreignId('retried_task_id')->nullable()->after('retried_by')->constrained('tasks')->nullOnDelete();

            // Indexes for admin filtering
            $table->index('task_id');
            $table->index('retried');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('dead_letter_queue', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropForeign(['retried_by']);
            $table->dropForeign(['retried_task_id']);
            $table->dropIndex(['task_id']);
            $table->dropIndex(['retried']);
            $table->dropColumn(['task_id', 'retried', 'retried_at', 'retried_by', 'retried_task_id']);
        });
    }
};
