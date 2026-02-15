<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->string('alert_type');          // e.g. api_outage, high_failure_rate, queue_depth, infrastructure, auth_failure, disk_usage
            $table->string('status');               // active, resolved
            $table->string('severity');             // high, medium, info
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();       // when team chat was sent for detection
            $table->timestamp('recovery_notified_at')->nullable(); // when team chat was sent for recovery
            $table->timestamps();

            $table->index(['alert_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};
