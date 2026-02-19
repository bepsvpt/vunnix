<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPostgres = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('memory_entries', function (Blueprint $table) use ($isPostgres): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('category')->nullable();
            if ($isPostgres) {
                $table->jsonb('content');
                $table->jsonb('source_meta')->nullable();
            } else {
                $table->json('content');
                $table->json('source_meta')->nullable();
            }
            $table->smallInteger('confidence');
            $table->foreignId('source_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->unsignedInteger('applied_count')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'type', 'archived_at', 'confidence'], 'memory_entries_project_type_active_confidence_idx');
            $table->index(['project_id', 'created_at'], 'memory_entries_project_created_at_idx');
            $table->index('source_task_id');
        });

        if ($isPostgres) {
            DB::statement('DROP INDEX IF EXISTS memory_entries_project_type_active_confidence_idx');
            DB::statement('CREATE INDEX memory_entries_project_type_active_confidence_idx ON memory_entries (project_id, type, archived_at, confidence DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_entries');
    }
};
