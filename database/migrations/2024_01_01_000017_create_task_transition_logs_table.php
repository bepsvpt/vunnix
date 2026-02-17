<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_transition_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->timestamp('transitioned_at')->useCurrent();

            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_transition_logs');
    }
};
