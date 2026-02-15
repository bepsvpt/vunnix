<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('rule');          // monthly_anomaly, daily_spike, single_task_outlier, approaching_projection
            $table->string('severity');      // warning, critical
            $table->text('message');
            $table->json('context');         // { threshold, actual, period, task_id, etc. }
            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['acknowledged', 'created_at']);
            $table->index('rule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_alerts');
    }
};
