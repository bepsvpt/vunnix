<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add project_id to agent_conversations (spec requires project-scoped conversations)
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        // Add tsvector column for full-text search on conversation title
        DB::statement('ALTER TABLE agent_conversations ADD COLUMN title_search tsvector');
        DB::statement('CREATE INDEX agent_conversations_title_search_idx ON agent_conversations USING GIN (title_search)');

        // Auto-update tsvector on INSERT/UPDATE
        DB::statement("
            CREATE FUNCTION agent_conversations_title_search_update() RETURNS trigger AS $$
            BEGIN
                NEW.title_search := to_tsvector('english', COALESCE(NEW.title, ''));
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");
        DB::statement("
            CREATE TRIGGER agent_conversations_title_search_trigger
            BEFORE INSERT OR UPDATE OF title ON agent_conversations
            FOR EACH ROW EXECUTE FUNCTION agent_conversations_title_search_update();
        ");

        // Backfill existing rows (if any)
        DB::statement("UPDATE agent_conversations SET title_search = to_tsvector('english', COALESCE(title, ''))");

        // Add tsvector column for full-text search on message content
        DB::statement('ALTER TABLE agent_conversation_messages ADD COLUMN content_search tsvector');
        DB::statement('CREATE INDEX agent_conversation_messages_content_search_idx ON agent_conversation_messages USING GIN (content_search)');

        // Auto-update tsvector on INSERT/UPDATE
        DB::statement("
            CREATE FUNCTION agent_conversation_messages_content_search_update() RETURNS trigger AS $$
            BEGIN
                NEW.content_search := to_tsvector('english', COALESCE(NEW.content, ''));
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");
        DB::statement("
            CREATE TRIGGER agent_conversation_messages_content_search_trigger
            BEFORE INSERT OR UPDATE OF content ON agent_conversation_messages
            FOR EACH ROW EXECUTE FUNCTION agent_conversation_messages_content_search_update();
        ");

        // Backfill existing rows (if any)
        DB::statement("UPDATE agent_conversation_messages SET content_search = to_tsvector('english', COALESCE(content, ''))");
    }

    public function down(): void
    {
        // Drop triggers and functions for messages
        DB::statement('DROP TRIGGER IF EXISTS agent_conversation_messages_content_search_trigger ON agent_conversation_messages');
        DB::statement('DROP FUNCTION IF EXISTS agent_conversation_messages_content_search_update()');
        DB::statement('DROP INDEX IF EXISTS agent_conversation_messages_content_search_idx');
        DB::statement('ALTER TABLE agent_conversation_messages DROP COLUMN IF EXISTS content_search');

        // Drop triggers and functions for conversations
        DB::statement('DROP TRIGGER IF EXISTS agent_conversations_title_search_trigger ON agent_conversations');
        DB::statement('DROP FUNCTION IF EXISTS agent_conversations_title_search_update()');
        DB::statement('DROP INDEX IF EXISTS agent_conversations_title_search_idx');
        DB::statement('ALTER TABLE agent_conversations DROP COLUMN IF EXISTS title_search');

        // Drop project_id from agent_conversations
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
