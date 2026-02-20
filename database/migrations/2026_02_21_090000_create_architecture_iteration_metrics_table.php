<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('architecture_iteration_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->unsignedInteger('module_touch_breadth')->default(0);
            $table->decimal('median_files_changed', 8, 2)->nullable();
            $table->decimal('fast_lane_minutes_p50', 8, 2)->nullable();
            $table->unsignedInteger('reopened_regressions_count')->default(0);
            $table->decimal('lead_time_hours_p50', 8, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('architecture_iteration_metrics');
    }
};
