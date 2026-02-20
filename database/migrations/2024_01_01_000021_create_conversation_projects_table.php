<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('conversation_id');
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'project_id']);

            // Only add FK if agent_conversations exists and we're on PostgreSQL.
            if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('agent_conversations')) {
                $table->foreign('conversation_id')
                    ->references('id')
                    ->on('agent_conversations')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_projects');
    }
};
