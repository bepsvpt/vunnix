<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_configs', function (Blueprint $table): void {
            $table->id();

            // One config per project
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();

            // Webhook security — encrypted at rest via Laravel's encrypted cast
            $table->text('webhook_secret')->nullable();
            $table->boolean('webhook_token_validation')->default(true);

            // Per-project settings — JSONB for flexible .vunnix.toml-style config
            // Keys: trigger_phrase, model, max_tokens, timeout_minutes,
            //        code_review.*, feature_dev.*, conversation.*, ui_adjustment.*, labels.*
            $table->jsonb('settings')->default('{}');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_configs');
    }
};
