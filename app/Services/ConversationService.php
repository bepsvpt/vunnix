<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ConversationService
{
    /**
     * List conversations accessible by the user with filters and cursor pagination.
     */
    public function listForUser(
        User $user,
        ?int $projectId = null,
        ?string $search = null,
        bool $archived = false,
        int $perPage = 25,
    ): CursorPaginator {
        $query = Conversation::accessibleBy($user)
            ->with(['latestMessage']);

        if ($archived) {
            $query->archived();
        } else {
            $query->notArchived();
        }

        if ($projectId) {
            $query->forProject($projectId);
        }

        if ($search) {
            // Full-text search on title (LIKE fallback for SQLite in tests)
            $query->where('title', 'like', "%{$search}%");
        }

        return $query->orderByDesc('updated_at')->cursorPaginate($perPage);
    }

    /**
     * Create a new conversation.
     */
    public function create(User $user, int $projectId, ?string $title = null): Conversation
    {
        return Conversation::create([
            'user_id' => $user->id,
            'project_id' => $projectId,
            'title' => $title ?? 'New conversation',
        ]);
    }

    /**
     * Load a conversation with its messages.
     */
    public function loadWithMessages(Conversation $conversation): Conversation
    {
        return $conversation->load(['messages' => function ($query) {
            $query->orderBy('created_at', 'asc');
        }]);
    }

    /**
     * Add a user message to a conversation.
     */
    public function addUserMessage(Conversation $conversation, User $user, string $content): Message
    {
        $message = $conversation->messages()->create([
            'user_id' => $user->id,
            'agent' => '',
            'role' => 'user',
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ]);

        $conversation->touch();

        return $message;
    }

    /**
     * Toggle archive state on a conversation.
     */
    public function toggleArchive(Conversation $conversation): Conversation
    {
        $conversation->archived_at = $conversation->isArchived() ? null : now();
        $conversation->save();

        return $conversation;
    }
}
