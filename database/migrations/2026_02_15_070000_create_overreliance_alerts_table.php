<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overreliance_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('rule');          // high_acceptance_rate, critical_acceptance_rate, bulk_resolution, zero_reactions
            $table->string('severity');      // warning, info
            $table->text('message');
            $table->json('context');         // { acceptance_rate, threshold, weeks, project_id, etc. }
            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['acknowledged', 'created_at']);
            $table->index('rule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overreliance_alerts');
    }
};
