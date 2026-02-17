<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('pipeline_id')->nullable()->after('commit_sha');
        });

        Schema::table('project_configs', function (Blueprint $table): void {
            $table->text('ci_trigger_token')->nullable()->after('webhook_token_validation');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('pipeline_id');
        });

        Schema::table('project_configs', function (Blueprint $table): void {
            $table->dropColumn('ci_trigger_token');
        });
    }
};
