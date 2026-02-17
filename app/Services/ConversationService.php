<?php

namespace App\Services;

use App\Agents\VunnixAgent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Laravel\Ai\Responses\StreamableAgentResponse;

class ConversationService
{
    /**
     * List conversations accessible by the user with filters and cursor pagination.
     *
     * @return CursorPaginator<int, Conversation>
     */
    public function listForUser(
        User $user,
        ?int $projectId = null,
        ?string $search = null,
        bool $archived = false,
        int $perPage = 25,
    ): CursorPaginator {
        $query = Conversation::accessibleBy($user)
            ->with(['latestMessage', 'projects']);

        if ($archived) {
            $query->archived();
        } else {
            $query->notArchived();
        }

        if ($projectId !== null) {
            $query->forProject($projectId);
        }

        if ($search !== null && $search !== '') {
            // Full-text search on title (LIKE fallback for SQLite in tests)
            $query->whereRaw('LOWER(title) LIKE ?', ['%'.strtolower($search).'%']);
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
        return $conversation->load(['messages' => function ($query): void {
            $query->orderBy('created_at', 'asc');
        }, 'projects']);
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
     * Stream an AI response for a user message in a conversation.
     *
     * Configures the agent with conversation context and streams the response.
     * The SDK's RememberConversation middleware automatically persists both the
     * user message and the assistant response to agent_conversation_messages
     * after the stream completes.
     *
     * Note: We do NOT manually save the user message here — the SDK middleware
     * handles it. The frontend displays the user message optimistically before
     * the API call, so there's no delay in the UI.
     *
     * Always uses continue() to link to the existing conversation record.
     */
    public function streamResponse(Conversation $conversation, User $user, string $content): StreamableAgentResponse
    {
        $agent = VunnixAgent::make();

        // Inject project context for per-project config (T93: PRD template)
        if ($conversation->project_id !== null) {
            $project = Project::find($conversation->project_id);
            if ($project !== null) {
                $agent->setProject($project);
            }
        }

        $agent->continue($conversation->id, $user);

        return $agent->stream($content);
    }

    /**
     * Add a project to an existing conversation (cross-project support D28).
     * Validates user has access to the project being added.
     */
    public function addProject(Conversation $conversation, User $user, int $projectId): Conversation
    {
        $project = Project::findOrFail($projectId);

        // Verify user has access to the project being added
        if (! $user->projects()->where('projects.id', $project->id)->exists()) {
            abort(403, 'You do not have access to this project.');
        }

        // Don't add duplicates (primary project or already in pivot)
        if ($conversation->project_id === $project->id) {
            return $conversation;
        }

        if ($conversation->projects()->where('projects.id', $project->id)->exists()) {
            return $conversation;
        }

        $conversation->projects()->attach($project->id);

        return $conversation->load('projects');
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
