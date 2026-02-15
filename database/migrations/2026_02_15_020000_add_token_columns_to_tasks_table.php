<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->nullable()->after('tokens_used');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('duration_seconds')->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['input_tokens', 'output_tokens', 'duration_seconds']);
        });
    }
};
