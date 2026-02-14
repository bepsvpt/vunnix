<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();

            // Owner
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Key identity
            $table->string('name'); // Human-readable label
            $table->string('key', 64)->unique(); // SHA-256 hash of the actual key (per D152)

            // Usage tracking
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();

            // Lifecycle
            $table->timestamp('expires_at')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
