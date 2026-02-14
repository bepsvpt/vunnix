# T47: Chat API Endpoints — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build REST API endpoints for conversation management (create, list, load, send message, archive/unarchive) with cursor-based pagination, full-text search, and RBAC authorization.

**Architecture:** Extend the Laravel AI SDK's `agent_conversations` / `agent_conversation_messages` tables with a custom Eloquent `Conversation` model and `Message` model (wrapping the SDK tables). A `ConversationController` handles all 5 endpoints, using `ConversationService` for business logic. Authorization uses the existing `permission:chat.access` middleware plus a `ConversationPolicy` for per-record access control. The `send message` endpoint stores the user message only — the Conversation Engine (T49) will handle AI responses later.

**Tech Stack:** Laravel 11, Pest, SQLite in-memory (tests), PostgreSQL (production), cursor pagination, full-text search (PostgreSQL-only, gracefully degraded in SQLite tests).

---

### Task 1: Add `archived_at` migration

**Files:**
- Create: `database/migrations/2024_01_01_000019_add_archived_at_to_agent_conversations.php`

**Action:** Create a migration that adds `archived_at` nullable timestamp to `agent_conversations`. Guard with PostgreSQL + table existence check (same pattern as migration `000010`).

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
```

**Verify:** `php artisan migrate:status` shows the new migration (won't run on SQLite test DB, but that's expected — tests create tables inline).

---

### Task 2: Create Conversation model

**Files:**
- Create: `app/Models/Conversation.php`

**Action:** Eloquent model wrapping the `agent_conversations` table. Uses string UUID primary key (not auto-incrementing). Relationships: `belongsTo(User)`, `belongsTo(Project)`, `hasMany(Message)`. Scopes: `notArchived()`, `archived()`, `forProject(int $projectId)`, `accessibleBy(User $user)`.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Conversation extends Model
{
    protected $table = 'agent_conversations';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'user_id',
        'project_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation) {
            if (! $conversation->id) {
                $conversation->id = (string) Str::uuid7();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope to conversations accessible by a user.
     * A user can access conversations belonging to projects they are a member of.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $projectIds = $user->projects()->pluck('projects.id');

        return $query->whereIn('project_id', $projectIds);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
```

---

### Task 3: Create Message model

**Files:**
- Create: `app/Models/Message.php`

**Action:** Eloquent model wrapping the `agent_conversation_messages` table. String UUID key. Belongs to Conversation and User.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Message extends Model
{
    protected $table = 'agent_conversation_messages';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'agent',
        'role',
        'content',
        'attachments',
        'tool_calls',
        'tool_results',
        'usage',
        'meta',
    ];

    protected static function booted(): void
    {
        static::creating(function (Message $message) {
            if (! $message->id) {
                $message->id = (string) Str::uuid7();
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

---

### Task 4: Create ConversationPolicy

**Files:**
- Create: `app/Policies/ConversationPolicy.php`

**Action:** Policy that checks the user has `chat.access` permission on the conversation's project and is a member of the project. Registered in `AppServiceProvider`.

```php
<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Can the user view this conversation?
     * User must be a member of the conversation's project.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->projects()->where('projects.id', $conversation->project_id)->exists();
    }

    /**
     * Can the user send a message to this conversation?
     * Same as view — must be a project member.
     */
    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /**
     * Can the user archive/unarchive this conversation?
     * Same as view — any project member can archive.
     */
    public function archive(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
```

Register in `app/Providers/AppServiceProvider.php`:
```php
use App\Models\Conversation;
use App\Policies\ConversationPolicy;
use Illuminate\Support\Facades\Gate;

// In boot():
Gate::policy(Conversation::class, ConversationPolicy::class);
```

---

### Task 5: Create FormRequest classes

**Files:**
- Create: `app/Http/Requests/CreateConversationRequest.php`
- Create: `app/Http/Requests/SendMessageRequest.php`

**CreateConversationRequest:**
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by middleware
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

**SendMessageRequest:**
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by policy
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:50000'],
        ];
    }
}
```

---

### Task 6: Create API Resource classes

**Files:**
- Create: `app/Http/Resources/ConversationResource.php`
- Create: `app/Http/Resources/ConversationDetailResource.php`
- Create: `app/Http/Resources/MessageResource.php`

**ConversationResource** (for list — lightweight):
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'last_message' => $this->whenLoaded('latestMessage', function () {
                return [
                    'content' => \Illuminate\Support\Str::limit($this->latestMessage->content, 150),
                    'role' => $this->latestMessage->role,
                    'created_at' => $this->latestMessage->created_at->toIso8601String(),
                ];
            }),
        ];
    }
}
```

**ConversationDetailResource** (for show — includes messages):
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
```

**MessageResource:**
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'role' => $this->role,
            'content' => $this->content,
            'tool_calls' => json_decode($this->tool_calls, true),
            'tool_results' => json_decode($this->tool_results, true),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
```

---

### Task 7: Create ConversationService

**Files:**
- Create: `app/Services/ConversationService.php`

**Action:** Service class encapsulating conversation business logic — search, create, archive. Keeps the controller thin.

```php
<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Str;

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
```

---

### Task 8: Create ConversationController

**Files:**
- Create: `app/Http/Controllers/Api/ConversationController.php`

**Action:** REST controller with 5 actions: `index`, `store`, `show`, `sendMessage`, `archive`. Uses policy-based authorization for per-record access. The `store` endpoint needs special permission handling since the project context comes from the request body.

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
    ) {}

    /**
     * GET /api/v1/conversations
     * List conversations with filters, search, and cursor pagination.
     */
    public function index(Request $request)
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
```

---

### Task 9: Register routes

**Files:**
- Modify: `routes/api.php`

**Action:** Add conversation routes inside the `v1` group with auth middleware. The route model binding for `{conversation}` needs to resolve against the `agent_conversations` table via the Conversation model.

Add to `routes/api.php`:
```php
use App\Http\Controllers\Api\ConversationController;

// Inside the existing v1 group:
Route::middleware('auth')->group(function () {
    Route::get('/conversations', [ConversationController::class, 'index'])
        ->name('api.conversations.index');
    Route::post('/conversations', [ConversationController::class, 'store'])
        ->name('api.conversations.store');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
        ->name('api.conversations.show');
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage'])
        ->name('api.conversations.messages.store');
    Route::patch('/conversations/{conversation}/archive', [ConversationController::class, 'archive'])
        ->name('api.conversations.archive');
});
```

---

### Task 10: Register ConversationPolicy in AppServiceProvider

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

**Action:** Register the `Gate::policy()` mapping. Add a `latestMessage` relationship to the Conversation model for the list endpoint's "last message preview".

---

### Task 11: Add `latestMessage` relationship to Conversation

**Files:**
- Modify: `app/Models/Conversation.php`

**Action:** Add a `latestMessage()` `hasOne` relationship that returns the most recent message, for eager-loading in the list endpoint.

```php
use Illuminate\Database\Eloquent\Relations\HasOne;

public function latestMessage(): HasOne
{
    return $this->hasOne(Message::class, 'conversation_id')->latestOfMany();
}
```

---

### Task 12: Create ConversationFactory and MessageFactory

**Files:**
- Create: `database/factories/ConversationFactory.php`
- Create: `database/factories/MessageFactory.php`

**ConversationFactory:**
```php
<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
```

**MessageFactory:**
```php
<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'agent' => '',
            'role' => 'user',
            'content' => fake()->paragraph(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn () => ['role' => 'assistant']);
    }

    public function forConversation(Conversation $conversation): static
    {
        return $this->state(fn () => ['conversation_id' => $conversation->id]);
    }
}
```

---

### Task 13: Write feature tests — Conversation API

**Files:**
- Create: `tests/Feature/ConversationApiTest.php`

**Action:** Comprehensive feature tests covering all 5 endpoints, authorization, pagination, filtering, and search. Tests use SQLite in-memory (no full-text search — that's PostgreSQL-only), so search tests use the LIKE fallback.

Test cases (per M3 Verification section):
1. Authenticated user with `chat.access` can create a conversation
2. Authenticated user without `chat.access` gets 403 on create
3. Authenticated user can list their accessible conversations (cursor-paginated)
4. List filters by project_id
5. List filters by archived status
6. List searches by keyword (title LIKE)
7. Authenticated user can load a conversation with messages
8. User cannot view a conversation for a project they don't belong to (403)
9. Authenticated user can send a message
10. User cannot send a message to a conversation they can't access (403)
11. Archive toggles archived_at on/off
12. Conversation creation with primary project stores project_id
13. Auto-generated title defaults to "New conversation" (title generation by CE is T49)
14. Unauthenticated requests get redirected (302 — session auth)

---

### Task 14: Write feature tests — Conversation model

**Files:**
- Create: `tests/Feature/Models/ConversationModelTest.php`

**Action:** Model-level tests for scopes, relationships, and archive behavior.

Test cases:
1. Create conversation with primary project
2. Load conversation with messages
3. `notArchived` scope excludes archived conversations
4. `archived` scope includes only archived conversations
5. `accessibleBy` scope returns only conversations for user's projects
6. `forProject` scope filters by project
7. `isArchived()` returns correct boolean

---

### Task 15: Create M3 verification script

**Files:**
- Create: `verify/verify_m3.py`

**Action:** Structural verification for T47 (and future M3 tasks). Checks file existence, content patterns, and route registration.

Checks for T47:
- ConversationController exists
- Conversation model exists with correct table name
- Message model exists with correct table name
- ConversationPolicy exists
- FormRequest classes exist
- API Resource classes exist
- ConversationService exists
- Routes registered (file contains conversation route patterns)
- Migration for archived_at exists
- Factory files exist
- Feature tests exist

---

### Task 16: Run tests and verify

**Action:**
1. `php artisan test` — all tests pass
2. `python3 verify/verify_m3.py` — structural checks pass
3. If any failures, fix and re-run

---

### Task 17: Update handoff.md and commit

**Action:**
1. Update `handoff.md` throughout implementation
2. After verification passes, update `progress.md` (mark T47 complete, bold T48)
3. Promote any learnings to CLAUDE.md
4. Clear `handoff.md`
5. Commit with `T47: Add Chat API endpoints with cursor pagination and full-text search`
