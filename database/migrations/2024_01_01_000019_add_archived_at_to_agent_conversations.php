<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard for SQLite test environment and missing table
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('agent_conversations')) {
            return;
        }

        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('agent_conversations')) {
            return;
        }

        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
