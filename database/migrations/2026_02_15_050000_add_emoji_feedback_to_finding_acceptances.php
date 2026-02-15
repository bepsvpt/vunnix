<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finding_acceptances')) {
            return;
        }

        Schema::table('finding_acceptances', function (Blueprint $table) {
            $table->unsignedSmallInteger('emoji_positive_count')->default(0);
            $table->unsignedSmallInteger('emoji_negative_count')->default(0);
            $table->string('emoji_sentiment', 20)->default('neutral');
            $table->string('category')->nullable();
            $table->index('emoji_sentiment');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('finding_acceptances')) {
            return;
        }

        Schema::table('finding_acceptances', function (Blueprint $table) {
            $table->dropIndex(['emoji_sentiment']);
            $table->dropColumn([
                'emoji_positive_count',
                'emoji_negative_count',
                'emoji_sentiment',
                'category',
            ]);
        });
    }
};
