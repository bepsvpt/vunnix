<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('gitlab_id')->unique()->after('id');
            $table->string('username')->index()->after('name');
            $table->string('avatar_url')->nullable()->after('email');
            $table->string('oauth_provider')->default('gitlab')->after('avatar_url');
            $table->text('oauth_token')->nullable()->after('oauth_provider');
            $table->text('oauth_refresh_token')->nullable()->after('oauth_token');
            $table->timestamp('oauth_token_expires_at')->nullable()->after('oauth_refresh_token');
        });

        // Make password nullable — login is OAuth-only
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['gitlab_id']);
            $table->dropIndex(['username']);
            $table->dropColumn([
                'gitlab_id',
                'username',
                'avatar_url',
                'oauth_provider',
                'oauth_token',
                'oauth_refresh_token',
                'oauth_token_expires_at',
            ]);
        });
    }
};
