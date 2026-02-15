# T64: Chat Page — New Conversation Flow

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement the new conversation creation flow with project selection, and the "add project mid-conversation" feature with cross-project visibility warning (D128).

**Architecture:** The current schema has a single `project_id` on `agent_conversations`. To support multi-project conversations (D28), we add a `conversation_projects` pivot table. The existing `project_id` becomes the "primary project" (set at creation, scopes initial AI context). Additional projects are added via `POST /conversations/{id}/projects` with the D128 visibility warning enforced on the frontend. The `accessibleBy` scope is updated to check both primary and pivot projects.

**Tech Stack:** Laravel 11 (migration, model, controller, policy, service, request), Vue 3 (Composition API + `<script setup>`), Pinia, Vitest + Vue Test Utils

---

### Task 1: Add `conversation_projects` pivot migration

**Files:**
- Create: `database/migrations/2024_01_01_000021_create_conversation_projects_table.php`

**Step 1: Write the migration**

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
        Schema::create('conversation_projects', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id');
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'project_id']);

            // Only add FK if agent_conversations exists and we're on PostgreSQL
            // (SQLite test env may not have the SDK table)
            if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('agent_conversations')) {
                $table->foreign('conversation_id')
                    ->references('id')
                    ->on('agent_conversations')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_projects');
    }
};
```

**Step 2: Run migration**

Run: `php artisan migrate`

**Step 3: Commit**

```bash
git add database/migrations/2024_01_01_000021_create_conversation_projects_table.php
git commit --no-gpg-sign -m "T64.1: Add conversation_projects pivot table migration"
```

---

### Task 2: Update Conversation model with `projects()` relationship

**Files:**
- Modify: `app/Models/Conversation.php`

**Step 1: Add `projects()` belongsToMany and `allProjectIds()` helper**

Add to Conversation model:

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

public function projects(): BelongsToMany
{
    return $this->belongsToMany(Project::class, 'conversation_projects');
}

/**
 * Get all project IDs associated with this conversation
 * (primary project_id + any additional pivot projects).
 */
public function allProjectIds(): array
{
    $ids = $this->projects()->pluck('projects.id')->toArray();
    if ($this->project_id && !in_array($this->project_id, $ids)) {
        $ids[] = $this->project_id;
    }
    return $ids;
}
```

**Step 2: Update `scopeAccessibleBy` to check both primary and pivot**

Replace:
```php
public function scopeAccessibleBy(Builder $query, User $user): Builder
{
    $projectIds = $user->projects()->pluck('projects.id');
    return $query->whereIn('project_id', $projectIds);
}
```

With:
```php
public function scopeAccessibleBy(Builder $query, User $user): Builder
{
    $projectIds = $user->projects()->pluck('projects.id');
    return $query->where(function (Builder $q) use ($projectIds) {
        $q->whereIn('project_id', $projectIds)
          ->orWhereHas('projects', function (Builder $sub) use ($projectIds) {
              $sub->whereIn('projects.id', $projectIds);
          });
    });
}
```

**Step 3: Run existing tests**

Run: `php artisan test --filter=ConversationModel`
Expected: PASS

**Step 4: Commit**

```bash
git add app/Models/Conversation.php
git commit --no-gpg-sign -m "T64.2: Add projects relationship and multi-project accessibleBy scope"
```

---

### Task 3: Update ConversationPolicy for multi-project access

**Files:**
- Modify: `app/Policies/ConversationPolicy.php`

**Step 1: Update `view()` to check both primary and pivot projects**

Replace:
```php
public function view(User $user, Conversation $conversation): bool
{
    return $user->projects()->where('projects.id', $conversation->project_id)->exists();
}
```

With:
```php
public function view(User $user, Conversation $conversation): bool
{
    $userProjectIds = $user->projects()->pluck('projects.id')->toArray();

    // Check primary project
    if (in_array($conversation->project_id, $userProjectIds)) {
        return true;
    }

    // Check additional projects via pivot
    return $conversation->projects()
        ->whereIn('projects.id', $userProjectIds)
        ->exists();
}
```

**Step 2: Add `addProject` policy method**

```php
/**
 * Can the user add a project to this conversation?
 * Must be able to view the conversation AND have access to the new project.
 */
public function addProject(User $user, Conversation $conversation): bool
{
    return $this->view($user, $conversation);
}
```

**Step 3: Run existing tests**

Run: `php artisan test --filter=ConversationApi`
Expected: PASS

**Step 4: Commit**

```bash
git add app/Policies/ConversationPolicy.php
git commit --no-gpg-sign -m "T64.3: Update ConversationPolicy for multi-project and addProject"
```

---

### Task 4: Add "add project to conversation" backend API

**Files:**
- Create: `app/Http/Requests/AddProjectToConversationRequest.php`
- Modify: `app/Services/ConversationService.php`
- Modify: `app/Http/Controllers/Api/ConversationController.php`
- Modify: `routes/api.php`

**Step 1: Create AddProjectToConversationRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddProjectToConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by controller policy
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ];
    }
}
```

**Step 2: Add `addProject()` to ConversationService**

```php
/**
 * Add a project to an existing conversation (cross-project support D28).
 * Validates user has access to the project being added.
 */
public function addProject(Conversation $conversation, User $user, int $projectId): Conversation
{
    $project = \App\Models\Project::findOrFail($projectId);

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
```

**Step 3: Add `addProject()` to ConversationController**

```php
use App\Http\Requests\AddProjectToConversationRequest;

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
```

**Step 4: Add route**

In `routes/api.php`, inside the auth middleware group:

```php
Route::post('/conversations/{conversation}/projects', [ConversationController::class, 'addProject']);
```

**Step 5: Run tests**

Run: `php artisan test`
Expected: PASS

**Step 6: Commit**

```bash
git add app/Http/Requests/AddProjectToConversationRequest.php app/Services/ConversationService.php app/Http/Controllers/Api/ConversationController.php routes/api.php
git commit --no-gpg-sign -m "T64.4: Add POST /conversations/{id}/projects endpoint for cross-project"
```

---

### Task 5: Update ConversationResource to include additional projects

**Files:**
- Modify: `app/Http/Resources/ConversationResource.php`
- Modify: `app/Http/Resources/ConversationDetailResource.php`

**Step 1: Add `projects` to ConversationResource**

Add to the `toArray` return:
```php
'projects' => $this->whenLoaded('projects', function () {
    return $this->projects->map(fn ($p) => [
        'id' => $p->id,
        'name' => $p->name,
    ]);
}),
```

**Step 2: Add `projects` to ConversationDetailResource**

Add to the `toArray` return:
```php
'projects' => $this->whenLoaded('projects', function () {
    return $this->projects->map(fn ($p) => [
        'id' => $p->id,
        'name' => $p->name,
    ]);
}),
```

**Step 3: Eager-load projects in ConversationService::listForUser**

Update the query in `listForUser`:
```php
$query = Conversation::accessibleBy($user)
    ->with(['latestMessage', 'projects']);
```

**Step 4: Eager-load projects in ConversationService::loadWithMessages**

Update:
```php
return $conversation->load(['messages' => function ($query) {
    $query->orderBy('created_at', 'asc');
}, 'projects']);
```

**Step 5: Run tests**

Run: `php artisan test`
Expected: PASS

**Step 6: Commit**

```bash
git add app/Http/Resources/ConversationResource.php app/Http/Resources/ConversationDetailResource.php app/Services/ConversationService.php
git commit --no-gpg-sign -m "T64.5: Include additional projects in conversation API responses"
```

---

### Task 6: Create `NewConversationDialog` Vue component

**Files:**
- Create: `resources/js/components/NewConversationDialog.vue`

**Step 1: Write the component**

The dialog shows:
- A select dropdown of user's accessible projects (from auth store)
- Only projects where user has `chat.access` permission
- Create button → POST to API → select new conversation → navigate

```vue
<script setup>
import { ref, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';

const emit = defineEmits(['create', 'close']);
const auth = useAuthStore();

const selectedProjectId = ref(null);
const creating = ref(false);
const error = ref(null);

const chatProjects = computed(() =>
    auth.projects.filter((p) => p.permissions.includes('chat.access'))
);

function onSubmit() {
    if (!selectedProjectId.value || creating.value) return;
    creating.value = true;
    error.value = null;
    emit('create', selectedProjectId.value);
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
      <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4">New Conversation</h2>

      <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
        Select a project
      </label>
      <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-2">
        This scopes the AI's initial context to the selected project's repository.
      </p>
      <select
        v-model="selectedProjectId"
        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-400 dark:focus:ring-zinc-600"
      >
        <option :value="null" disabled>Choose a project...</option>
        <option
          v-for="project in chatProjects"
          :key="project.id"
          :value="project.id"
        >
          {{ project.name }}
        </option>
      </select>

      <div v-if="chatProjects.length === 0" class="mt-2 text-xs text-amber-600 dark:text-amber-400">
        You don't have chat access to any projects.
      </div>

      <div v-if="error" class="mt-2 text-xs text-red-600 dark:text-red-400">
        {{ error }}
      </div>

      <div class="flex justify-end gap-2 mt-6">
        <button
          type="button"
          class="px-4 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
          @click="emit('close')"
        >
          Cancel
        </button>
        <button
          type="button"
          class="px-4 py-2 text-sm rounded-lg bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:bg-zinc-800 dark:hover:bg-zinc-200 transition-colors disabled:opacity-50"
          :disabled="!selectedProjectId || creating"
          @click="onSubmit"
        >
          {{ creating ? 'Creating...' : 'Start Conversation' }}
        </button>
      </div>
    </div>
  </div>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/components/NewConversationDialog.vue
git commit --no-gpg-sign -m "T64.6: Create NewConversationDialog component"
```

---

### Task 7: Create `CrossProjectWarningDialog` Vue component

**Files:**
- Create: `resources/js/components/CrossProjectWarningDialog.vue`

**Step 1: Write the component**

Implements D128: confirmation dialog when adding a project to an existing conversation.

```vue
<script setup>
const props = defineProps({
    existingProjectName: { type: String, required: true },
    newProjectName: { type: String, required: true },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('cancel')">
    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
      <div class="flex items-center gap-3 mb-4">
        <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Cross-Project Visibility Warning</h2>
      </div>

      <p class="text-sm text-zinc-700 dark:text-zinc-300 mb-4">
        Adding <strong>{{ newProjectName }}</strong> will make this conversation &mdash; including AI-browsed content from <strong>{{ existingProjectName }}</strong> &mdash; visible to all members of <strong>{{ newProjectName }}</strong>.
      </p>

      <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-6">
        This cannot be undone. Members of the added project will be able to see the full conversation history.
      </p>

      <div class="flex justify-end gap-2">
        <button
          type="button"
          class="px-4 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
          @click="emit('cancel')"
        >
          Cancel
        </button>
        <button
          type="button"
          class="px-4 py-2 text-sm rounded-lg bg-amber-600 text-white hover:bg-amber-700 transition-colors"
          @click="emit('confirm')"
        >
          Continue
        </button>
      </div>
    </div>
  </div>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/components/CrossProjectWarningDialog.vue
git commit --no-gpg-sign -m "T64.7: Create CrossProjectWarningDialog component (D128)"
```

---

### Task 8: Add `createConversation` and `addProject` to conversations store

**Files:**
- Modify: `resources/js/stores/conversations.js`

**Step 1: Add `createConversation` action**

```javascript
async function createConversation(projectId) {
    error.value = null;
    try {
        const response = await axios.post('/api/v1/conversations', {
            project_id: projectId,
        });
        const newConversation = response.data.data;
        conversations.value.unshift(newConversation);
        selectedId.value = newConversation.id;
        return newConversation;
    } catch (err) {
        error.value = err.response?.data?.message || 'Failed to create conversation';
        throw err;
    }
}
```

**Step 2: Add `addProjectToConversation` action**

```javascript
async function addProjectToConversation(conversationId, projectId) {
    error.value = null;
    try {
        const response = await axios.post(
            `/api/v1/conversations/${conversationId}/projects`,
            { project_id: projectId }
        );
        // Update conversation in list with new data
        const idx = conversations.value.findIndex((c) => c.id === conversationId);
        if (idx !== -1) {
            conversations.value[idx] = { ...conversations.value[idx], ...response.data.data };
        }
        return response.data.data;
    } catch (err) {
        error.value = err.response?.data?.message || 'Failed to add project';
        throw err;
    }
}
```

**Step 3: Export both new functions in the return**

Add `createConversation` and `addProjectToConversation` to the return object.

**Step 4: Reset: update `$reset` if needed** (no new state to reset, actions are stateless)

**Step 5: Run existing store tests**

Run: `npx vitest run resources/js/stores/conversations.test.js`
Expected: PASS

**Step 6: Commit**

```bash
git add resources/js/stores/conversations.js
git commit --no-gpg-sign -m "T64.8: Add createConversation and addProjectToConversation store actions"
```

---

### Task 9: Integrate NewConversationDialog into ConversationList

**Files:**
- Modify: `resources/js/components/ConversationList.vue`

**Step 1: Add "New conversation" button and dialog integration**

Import NewConversationDialog, add `showNewDialog` ref, add "New conversation" button above the search bar, and wire up the create flow.

The button goes at the top of the sidebar. On create:
1. Call `store.createConversation(projectId)`
2. Close dialog
3. New conversation appears at top of list and is auto-selected

See component code for exact integration.

**Step 2: Commit**

```bash
git add resources/js/components/ConversationList.vue
git commit --no-gpg-sign -m "T64.9: Integrate NewConversationDialog into ConversationList"
```

---

### Task 10: Write Vitest tests for NewConversationDialog

**Files:**
- Create: `resources/js/components/NewConversationDialog.test.js`

**Tests:**
1. Renders project dropdown with chat-accessible projects only
2. Filters out projects without `chat.access` permission
3. Shows warning when user has no chat-accessible projects
4. Create button is disabled when no project selected
5. Emits `create` with selected project ID on submit
6. Emits `close` when Cancel clicked
7. Emits `close` when backdrop clicked

**Step 1: Write tests**

```javascript
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import NewConversationDialog from './NewConversationDialog.vue';
import { useAuthStore } from '@/stores/auth';

let pinia;

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
});

function mountDialog() {
    return mount(NewConversationDialog, {
        global: { plugins: [pinia] },
    });
}

describe('NewConversationDialog', () => {
    it('renders project dropdown with chat-accessible projects', () => {
        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            projects: [
                { id: 1, name: 'Frontend', permissions: ['chat.access'] },
                { id: 2, name: 'Backend', permissions: ['chat.access'] },
            ],
        });

        const wrapper = mountDialog();
        const options = wrapper.find('select').findAll('option');
        // placeholder + 2 projects
        expect(options.length).toBe(3);
        expect(options[1].text()).toBe('Frontend');
        expect(options[2].text()).toBe('Backend');
    });

    it('filters out projects without chat.access permission', () => {
        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            projects: [
                { id: 1, name: 'Has Access', permissions: ['chat.access'] },
                { id: 2, name: 'No Access', permissions: ['dashboard.view'] },
            ],
        });

        const wrapper = mountDialog();
        const options = wrapper.find('select').findAll('option');
        expect(options.length).toBe(2); // placeholder + 1 accessible
        expect(options[1].text()).toBe('Has Access');
    });

    it('shows warning when user has no chat-accessible projects', () => {
        const auth = useAuthStore();
        auth.setUser({ id: 1, projects: [] });

        const wrapper = mountDialog();
        expect(wrapper.text()).toContain("You don't have chat access to any projects");
    });

    it('disables Create button when no project selected', () => {
        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            projects: [{ id: 1, name: 'P1', permissions: ['chat.access'] }],
        });

        const wrapper = mountDialog();
        const btn = wrapper.findAll('button').find((b) => b.text() === 'Start Conversation');
        expect(btn.attributes('disabled')).toBeDefined();
    });

    it('emits create with selected project ID on submit', async () => {
        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            projects: [{ id: 5, name: 'My App', permissions: ['chat.access'] }],
        });

        const wrapper = mountDialog();
        await wrapper.find('select').setValue(5);
        await wrapper.findAll('button').find((b) => b.text() === 'Start Conversation').trigger('click');

        expect(wrapper.emitted('create')).toHaveLength(1);
        expect(wrapper.emitted('create')[0]).toEqual([5]);
    });

    it('emits close when Cancel clicked', async () => {
        const auth = useAuthStore();
        auth.setUser({ id: 1, projects: [] });

        const wrapper = mountDialog();
        await wrapper.findAll('button').find((b) => b.text() === 'Cancel').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
```

**Step 2: Run tests**

Run: `npx vitest run resources/js/components/NewConversationDialog.test.js`
Expected: PASS

**Step 3: Commit**

```bash
git add resources/js/components/NewConversationDialog.test.js
git commit --no-gpg-sign -m "T64.10: Add NewConversationDialog Vitest tests"
```

---

### Task 11: Write Vitest tests for CrossProjectWarningDialog

**Files:**
- Create: `resources/js/components/CrossProjectWarningDialog.test.js`

**Tests:**
1. Renders both project names in warning text
2. Emits `confirm` when Continue clicked
3. Emits `cancel` when Cancel clicked

**Step 1: Write tests**

```javascript
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CrossProjectWarningDialog from './CrossProjectWarningDialog.vue';

function mountDialog(props = {}) {
    return mount(CrossProjectWarningDialog, {
        props: {
            existingProjectName: 'Frontend App',
            newProjectName: 'Backend API',
            ...props,
        },
    });
}

describe('CrossProjectWarningDialog', () => {
    it('displays both project names in warning message', () => {
        const wrapper = mountDialog();
        expect(wrapper.text()).toContain('Backend API');
        expect(wrapper.text()).toContain('Frontend App');
        expect(wrapper.text()).toContain('visible to all members');
    });

    it('emits confirm when Continue clicked', async () => {
        const wrapper = mountDialog();
        await wrapper.findAll('button').find((b) => b.text() === 'Continue').trigger('click');
        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('emits cancel when Cancel clicked', async () => {
        const wrapper = mountDialog();
        await wrapper.findAll('button').find((b) => b.text() === 'Cancel').trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});
```

**Step 2: Run tests**

Run: `npx vitest run resources/js/components/CrossProjectWarningDialog.test.js`
Expected: PASS

**Step 3: Commit**

```bash
git add resources/js/components/CrossProjectWarningDialog.test.js
git commit --no-gpg-sign -m "T64.11: Add CrossProjectWarningDialog Vitest tests"
```

---

### Task 12: Write Vitest tests for store createConversation and addProjectToConversation

**Files:**
- Modify: `resources/js/stores/conversations.test.js`

**Tests to add:**
1. `createConversation` — calls POST, prepends to list, sets selectedId
2. `createConversation` — sets error on failure
3. `addProjectToConversation` — calls POST, updates conversation in list
4. `addProjectToConversation` — sets error on failure

**Step 1: Add test block**

Append to the existing `describe('useConversationsStore')` block.

**Step 2: Run tests**

Run: `npx vitest run resources/js/stores/conversations.test.js`
Expected: PASS

**Step 3: Commit**

```bash
git add resources/js/stores/conversations.test.js
git commit --no-gpg-sign -m "T64.12: Add createConversation and addProjectToConversation store tests"
```

---

### Task 13: Add T64 checks to verify_m3.py

**Files:**
- Modify: `verify/verify_m3.py`

**Step 1: Add structural checks**

- `NewConversationDialog.vue` exists
- `CrossProjectWarningDialog.vue` exists
- `NewConversationDialog.test.js` exists
- `CrossProjectWarningDialog.test.js` exists
- `conversation_projects` pivot migration exists
- `addProject` method exists in `ConversationService.php`
- `addProject` route registered in `routes/api.php`
- `createConversation` action exists in `conversations.js` store
- `addProjectToConversation` action exists in `conversations.js` store

**Step 2: Run verification**

Run: `python3 verify/verify_m3.py`
Expected: T64 checks PASS

**Step 3: Commit**

```bash
git add verify/verify_m3.py
git commit --no-gpg-sign -m "T64.13: Add T64 verification checks to verify_m3.py"
```

---

### Task 14: Run full verification and update progress

**Step 1: Run Laravel tests**

Run: `php artisan test`
Expected: PASS

**Step 2: Run Vitest**

Run: `npx vitest run`
Expected: PASS

**Step 3: Run M3 verification**

Run: `python3 verify/verify_m3.py`
Expected: T64 PASS

**Step 4: Update progress.md**

- Check `[x]` for T64
- Bold the next task T65
- Update task count (17/27 for M3, 64/116 total)

**Step 5: Clear handoff.md**

**Step 6: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "T64: Add new conversation flow with project selection and cross-project warning"
```
