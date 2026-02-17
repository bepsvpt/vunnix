<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_settings', function (Blueprint $table): void {
            $table->id();

            $table->string('key')->unique(); // Configuration key (e.g., 'ai_model', 'default_language', 'timeout_minutes')
            $table->jsonb('value')->nullable(); // Flexible value storage — supports strings, booleans, integers, JSON objects
            $table->string('type')->default('string'); // Data type hint: string, boolean, integer, json
            $table->text('description')->nullable(); // Human-readable description for admin UI

            // Bot PAT rotation tracking per D144
            $table->timestamp('bot_pat_created_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_settings');
    }
};
