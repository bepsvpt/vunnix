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

        Schema::create('health_snapshots', function (Blueprint $table) use ($isPostgres): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 32);
            $table->decimal('score', 5, 2);

            if ($isPostgres) {
                $table->jsonb('details');
            } else {
                $table->json('details');
            }

            $table->string('source_ref')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'dimension', 'created_at'], 'health_snapshots_project_dimension_created_idx');
            $table->index('created_at');
        });

        if ($isPostgres) {
            DB::statement('DROP INDEX IF EXISTS health_snapshots_project_dimension_created_idx');
            DB::statement('CREATE INDEX health_snapshots_project_dimension_created_idx ON health_snapshots (project_id, dimension, created_at DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_snapshots');
    }
};
