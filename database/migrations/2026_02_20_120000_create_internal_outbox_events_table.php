<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type');
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->unsignedSmallInteger('schema_version')->default(1);

            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->jsonb('payload');
            } else {
                $table->json('payload');
            }

            $table->timestamp('occurred_at');
            $table->string('idempotency_key')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'outbox_status_available_idx');
            $table->index('idempotency_key', 'outbox_idempotency_idx');
            $table->index(['aggregate_type', 'aggregate_id'], 'outbox_aggregate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_outbox_events');
    }
};
