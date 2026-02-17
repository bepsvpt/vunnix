<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dead_letter_queue')) {
            return;
        }

        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::table('dead_letter_queue', function (Blueprint $table) use ($isPgsql): void {
            // Direct FK to the original task for easy lookup
            if ($isPgsql) {
                $table->foreignId('task_id')->nullable()->after('id')->constrained('tasks')->nullOnDelete();
            } else {
                $table->unsignedBigInteger('task_id')->nullable();
            }

            // Retry tracking
            $table->boolean('retried')->default(false);
            $table->timestamp('retried_at')->nullable();

            if ($isPgsql) {
                $table->foreignId('retried_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('retried_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            } else {
                $table->unsignedBigInteger('retried_by')->nullable();
                $table->unsignedBigInteger('retried_task_id')->nullable();
            }

            // Indexes for admin filtering
            $table->index('task_id');
            $table->index('retried');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dead_letter_queue')) {
            return;
        }

        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::table('dead_letter_queue', function (Blueprint $table) use ($isPgsql): void {
            if ($isPgsql) {
                $table->dropForeign(['task_id']);
                $table->dropForeign(['retried_by']);
                $table->dropForeign(['retried_task_id']);
            }
            $table->dropIndex(['task_id']);
            $table->dropIndex(['retried']);
            $table->dropColumn(['task_id', 'retried', 'retried_at', 'retried_by', 'retried_task_id']);
        });
    }
};
