<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddProjectToConversationRequest;
use App\Http\Requests\CreateConversationRequest;
use App\Http\Requests\SendMessageRequest;
use App\Http\Resources\ConversationDetailResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Project;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Ai\Responses\StreamableAgentResponse;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
    ) {}

    /**
     * GET /api/v1/conversations
     * List conversations with filters, search, and cursor pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'project_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'archived' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $conversations = $this->conversationService->listForUser(
            user: $request->user(),
            projectId: $request->integer('project_id') ?: null,
            search: $request->input('search'),
            archived: $request->boolean('archived'),
            perPage: $request->integer('per_page', 25),
        );

        return ConversationResource::collection($conversations);
    }

    /**
     * POST /api/v1/conversations
     * Create a new conversation with a primary project.
     */
    public function store(CreateConversationRequest $request): JsonResponse
    {
        $user = $request->user();
        $project = Project::findOrFail($request->validated('project_id'));

        // Verify user has chat.access permission on this project
        if (! $user->hasPermission('chat.access', $project)) {
            abort(403, 'You do not have chat access to this project.');
        }

        $conversation = $this->conversationService->create(
            user: $user,
            projectId: $project->id,
            title: $request->validated('title'),
        );

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/conversations/{conversation}
     * Load a conversation with all its messages.
     */
    public function show(Conversation $conversation): ConversationDetailResource
    {
        $this->authorize('view', $conversation);

        $this->conversationService->loadWithMessages($conversation);

        return new ConversationDetailResource($conversation);
    }

    /**
     * POST /api/v1/conversations/{conversation}/messages
     * Send a user message to a conversation.
     */
    public function sendMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        $message = $this->conversationService->addUserMessage(
            conversation: $conversation,
            user: $request->user(),
            content: $request->validated('content'),
        );

        return (new MessageResource($message))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/v1/conversations/{conversation}/stream
     * Send a user message and stream the AI response as SSE.
     *
     * Returns a text/event-stream response with AI SDK stream events:
     * stream_start, text_start, text_delta, text_end, tool_call,
     * tool_result, stream_end, followed by [DONE].
     */
    public function stream(SendMessageRequest $request, Conversation $conversation): StreamableAgentResponse
    {
        $this->authorize('stream', $conversation);

        return $this->conversationService->streamResponse(
            conversation: $conversation,
            user: $request->user(),
            content: $request->validated('content'),
        );
    }

    /**
     * POST /api/v1/conversations/{conversation}/projects
     * Add a project to an existing conversation (D28).
     * Frontend must show D128 visibility warning before calling.
     */
    public function addProject(AddProjectToConversationRequest $request, Conversation $conversation): ConversationResource
    {
        $this->authorize('addProject', $conversation);

        $this->conversationService->addProject(
            conversation: $conversation,
            user: $request->user(),
            projectId: $request->validated('project_id'),
        );

        return new ConversationResource($conversation);
    }

    /**
     * PATCH /api/v1/conversations/{conversation}/archive
     * Toggle archive state on a conversation.
     */
    public function archive(Conversation $conversation): ConversationResource
    {
        $this->authorize('archive', $conversation);

        $this->conversationService->toggleArchive($conversation);

        return new ConversationResource($conversation);
    }
}
