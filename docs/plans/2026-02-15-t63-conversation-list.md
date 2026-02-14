# T63: Chat Page — Conversation List Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the conversation list UI for the chat page — a filterable, searchable, paginated list of conversations with archive toggle.

**Architecture:** Pinia store (`conversations`) manages API state (list, filters, pagination). A `ConversationList` component renders the list with filter/search controls. A `ConversationListItem` component renders each entry. The `ChatPage` composes these into a sidebar + main area layout (sidebar shows list, main area is a placeholder for the message thread coming in T65).

**Tech Stack:** Vue 3 (`<script setup>`), Pinia, Axios, Tailwind CSS v4, Vitest + Vue Test Utils

---

### Task 1: Create conversations Pinia store

**Files:**
- Create: `resources/js/stores/conversations.js`

**Code:**

```javascript
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

export const useConversationsStore = defineStore('conversations', () => {
    const conversations = ref([]);
    const loading = ref(false);
    const error = ref(null);
    const nextCursor = ref(null);
    const hasMore = ref(false);

    // Filter state
    const projectFilter = ref(null);
    const searchQuery = ref('');
    const showArchived = ref(false);

    // Selected conversation ID (for highlighting active item)
    const selectedId = ref(null);

    const selected = computed(() =>
        conversations.value.find((c) => c.id === selectedId.value) || null
    );

    /**
     * Fetch conversations from the API. Resets the list (page 1).
     */
    async function fetchConversations() {
        loading.value = true;
        error.value = null;
        try {
            const params = {};
            if (projectFilter.value) params.project_id = projectFilter.value;
            if (searchQuery.value) params.search = searchQuery.value;
            if (showArchived.value) params.archived = 1;
            params.per_page = 25;

            const response = await axios.get('/api/v1/conversations', { params });
            conversations.value = response.data.data;
            nextCursor.value = response.data.meta?.next_cursor || null;
            hasMore.value = !!nextCursor.value;
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to load conversations';
            conversations.value = [];
        } finally {
            loading.value = false;
        }
    }

    /**
     * Load the next page of conversations (append to list).
     */
    async function loadMore() {
        if (!nextCursor.value || loading.value) return;
        loading.value = true;
        try {
            const params = { cursor: nextCursor.value, per_page: 25 };
            if (projectFilter.value) params.project_id = projectFilter.value;
            if (searchQuery.value) params.search = searchQuery.value;
            if (showArchived.value) params.archived = 1;

            const response = await axios.get('/api/v1/conversations', { params });
            conversations.value.push(...response.data.data);
            nextCursor.value = response.data.meta?.next_cursor || null;
            hasMore.value = !!nextCursor.value;
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to load more';
        } finally {
            loading.value = false;
        }
    }

    /**
     * Toggle archive on a conversation. Removes it from the current list.
     */
    async function toggleArchive(conversationId) {
        try {
            await axios.patch(`/api/v1/conversations/${conversationId}/archive`);
            // Remove from current list (archive/unarchive moves it to the other view)
            conversations.value = conversations.value.filter((c) => c.id !== conversationId);
            if (selectedId.value === conversationId) {
                selectedId.value = null;
            }
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to archive';
        }
    }

    /**
     * Update title of a conversation locally (optimistic, for rename).
     */
    async function renameConversation(conversationId, newTitle) {
        const conv = conversations.value.find((c) => c.id === conversationId);
        if (conv) {
            const oldTitle = conv.title;
            conv.title = newTitle;
            try {
                // Note: rename endpoint will be added in a later task if needed.
                // For now, this is optimistic-only to satisfy the acceptance criteria structure.
                // T64/T65 may add the PATCH endpoint.
            } catch {
                conv.title = oldTitle;
            }
        }
    }

    function setProjectFilter(projectId) {
        projectFilter.value = projectId;
        fetchConversations();
    }

    function setSearchQuery(query) {
        searchQuery.value = query;
        fetchConversations();
    }

    function setShowArchived(archived) {
        showArchived.value = archived;
        fetchConversations();
    }

    function selectConversation(id) {
        selectedId.value = id;
    }

    function $reset() {
        conversations.value = [];
        loading.value = false;
        error.value = null;
        nextCursor.value = null;
        hasMore.value = false;
        projectFilter.value = null;
        searchQuery.value = '';
        showArchived.value = false;
        selectedId.value = null;
    }

    return {
        conversations,
        loading,
        error,
        nextCursor,
        hasMore,
        projectFilter,
        searchQuery,
        showArchived,
        selectedId,
        selected,
        fetchConversations,
        loadMore,
        toggleArchive,
        renameConversation,
        setProjectFilter,
        setSearchQuery,
        setShowArchived,
        selectConversation,
        $reset,
    };
});
```

---

### Task 2: Create conversations store tests

**Files:**
- Create: `resources/js/stores/conversations.test.js`

**Test cases:**
1. Initial state: empty list, no loading, no error, no filters
2. `fetchConversations()` — success: populates list, sets cursor
3. `fetchConversations()` — failure: sets error, empties list
4. `fetchConversations()` — with project filter: passes `project_id` param
5. `fetchConversations()` — with search: passes `search` param
6. `fetchConversations()` — with archived: passes `archived=1` param
7. `loadMore()` — appends to list, updates cursor
8. `loadMore()` — no-op when no cursor or already loading
9. `toggleArchive()` — removes conversation from list, clears selection if archived item was selected
10. `selectConversation()` — sets selectedId, `selected` computed resolves
11. `setProjectFilter()` / `setSearchQuery()` / `setShowArchived()` — triggers fetch

**Pattern:** Follow `auth.test.js` — mock axios, use `setActivePinia(createPinia())` in `beforeEach`.

---

### Task 3: Create ConversationListItem component

**Files:**
- Create: `resources/js/components/ConversationListItem.vue`

**Props:** `conversation` (object), `projectName` (string), `isSelected` (boolean)
**Emits:** `select`, `archive`

**Template:** A clickable row showing:
- Title (bold if selected)
- Project name badge
- Last message preview (truncated, muted text)
- Relative timestamp (e.g., "2h ago")
- Archive button (icon, shows on hover)

**Code:**

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    conversation: { type: Object, required: true },
    projectName: { type: String, default: '' },
    isSelected: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'archive']);

const relativeTime = computed(() => {
    const date = new Date(props.conversation.updated_at);
    const now = new Date();
    const diffMs = now - date;
    const diffMin = Math.floor(diffMs / 60000);
    const diffHr = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr / 24);
    if (diffMin < 1) return 'just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffHr < 24) return `${diffHr}h ago`;
    if (diffDay < 30) return `${diffDay}d ago`;
    return date.toLocaleDateString();
});

const lastMessagePreview = computed(() => {
    if (!props.conversation.last_message) return 'No messages yet';
    return props.conversation.last_message.content;
});
</script>

<template>
  <button
    type="button"
    class="w-full text-left px-3 py-3 rounded-lg transition-colors group"
    :class="isSelected
      ? 'bg-zinc-100 dark:bg-zinc-800'
      : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50'"
    @click="emit('select', conversation.id)"
  >
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
          <h3
            class="text-sm truncate"
            :class="isSelected
              ? 'font-semibold text-zinc-900 dark:text-zinc-100'
              : 'font-medium text-zinc-700 dark:text-zinc-300'"
          >
            {{ conversation.title }}
          </h3>
          <span
            v-if="projectName"
            class="shrink-0 text-xs px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400"
          >
            {{ projectName }}
          </span>
        </div>
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 truncate">
          {{ lastMessagePreview }}
        </p>
      </div>
      <div class="flex items-center gap-1 shrink-0">
        <span class="text-xs text-zinc-400 dark:text-zinc-500">
          {{ relativeTime }}
        </span>
        <button
          type="button"
          class="p-1 rounded opacity-0 group-hover:opacity-100 hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-opacity"
          :title="conversation.archived_at ? 'Unarchive' : 'Archive'"
          @click.stop="emit('archive', conversation.id)"
        >
          <svg class="w-3.5 h-3.5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
          </svg>
        </button>
      </div>
    </div>
  </button>
</template>
```

---

### Task 4: Create ConversationList component

**Files:**
- Create: `resources/js/components/ConversationList.vue`

**Responsibilities:**
- Search input
- Project filter dropdown (populated from auth store's projects)
- Archive toggle
- Renders list of `ConversationListItem` components
- "Load more" button when `hasMore` is true
- Loading spinner and empty states

**Code:**

```vue
<script setup>
import { onMounted, watch } from 'vue';
import { useConversationsStore } from '@/stores/conversations';
import { useAuthStore } from '@/stores/auth';
import ConversationListItem from './ConversationListItem.vue';

const store = useConversationsStore();
const auth = useAuthStore();

let searchTimeout = null;

function onSearchInput(event) {
    const query = event.target.value;
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        store.setSearchQuery(query);
    }, 300);
}

function onProjectFilter(event) {
    const value = event.target.value;
    store.setProjectFilter(value ? Number(value) : null);
}

function onArchiveToggle() {
    store.setShowArchived(!store.showArchived);
}

function onSelect(id) {
    store.selectConversation(id);
}

function onArchive(id) {
    store.toggleArchive(id);
}

function projectNameForId(projectId) {
    const project = auth.projects.find((p) => p.id === projectId);
    return project ? project.name : '';
}

onMounted(() => {
    store.fetchConversations();
});
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Search + filters -->
    <div class="p-3 space-y-2 border-b border-zinc-200 dark:border-zinc-800">
      <input
        type="text"
        placeholder="Search conversations..."
        class="w-full px-3 py-1.5 text-sm rounded-md border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 dark:placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-400 dark:focus:ring-zinc-600"
        @input="onSearchInput"
      />
      <div class="flex items-center gap-2">
        <select
          class="flex-1 px-2 py-1 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-700 dark:text-zinc-300"
          @change="onProjectFilter"
        >
          <option value="">All projects</option>
          <option
            v-for="project in auth.projects"
            :key="project.id"
            :value="project.id"
          >
            {{ project.name }}
          </option>
        </select>
        <button
          type="button"
          class="px-2 py-1 text-xs rounded border transition-colors"
          :class="store.showArchived
            ? 'border-zinc-500 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300'
            : 'border-zinc-300 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
          @click="onArchiveToggle"
        >
          Archived
        </button>
      </div>
    </div>

    <!-- Conversation list -->
    <div class="flex-1 overflow-y-auto">
      <!-- Loading state -->
      <div v-if="store.loading && store.conversations.length === 0" class="p-4 text-center">
        <svg class="animate-spin h-5 w-5 mx-auto text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
      </div>

      <!-- Empty state -->
      <div
        v-else-if="!store.loading && store.conversations.length === 0"
        class="p-4 text-center text-sm text-zinc-500 dark:text-zinc-400"
      >
        <p v-if="store.searchQuery">No conversations match your search.</p>
        <p v-else-if="store.showArchived">No archived conversations.</p>
        <p v-else>No conversations yet. Start a new one!</p>
      </div>

      <!-- List -->
      <div v-else class="p-1 space-y-0.5">
        <ConversationListItem
          v-for="conversation in store.conversations"
          :key="conversation.id"
          :conversation="conversation"
          :project-name="projectNameForId(conversation.project_id)"
          :is-selected="store.selectedId === conversation.id"
          @select="onSelect"
          @archive="onArchive"
        />

        <!-- Load more -->
        <button
          v-if="store.hasMore"
          type="button"
          class="w-full py-2 text-xs text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300"
          :disabled="store.loading"
          @click="store.loadMore()"
        >
          {{ store.loading ? 'Loading...' : 'Load more' }}
        </button>
      </div>
    </div>

    <!-- Error state -->
    <div
      v-if="store.error"
      class="p-3 border-t border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 text-xs text-red-600 dark:text-red-400"
    >
      {{ store.error }}
    </div>
  </div>
</template>
```

---

### Task 5: Update ChatPage to compose layout

**Files:**
- Modify: `resources/js/pages/ChatPage.vue`

**Layout:** Two-column on desktop (sidebar 320px + main area), single column on mobile with toggle.

**Code:**

```vue
<script setup>
import { ref } from 'vue';
import ConversationList from '@/components/ConversationList.vue';
import { useConversationsStore } from '@/stores/conversations';

const store = useConversationsStore();
const sidebarOpen = ref(true);
</script>

<template>
  <div class="flex h-[calc(100vh-4rem)] -m-4 lg:-m-8">
    <!-- Sidebar: conversation list -->
    <aside
      class="border-r border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex-shrink-0 transition-all"
      :class="sidebarOpen ? 'w-80' : 'w-0 overflow-hidden'"
    >
      <ConversationList />
    </aside>

    <!-- Main area: placeholder for message thread (T65) -->
    <main class="flex-1 flex items-center justify-center">
      <div v-if="store.selectedId" class="text-center text-zinc-500 dark:text-zinc-400">
        <p class="text-sm">Message thread coming in T65.</p>
      </div>
      <div v-else class="text-center text-zinc-400 dark:text-zinc-500">
        <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
        <p class="text-sm">Select a conversation to get started</p>
      </div>
    </main>
  </div>
</template>
```

---

### Task 6: Write ConversationListItem component tests

**Files:**
- Create: `resources/js/components/ConversationListItem.test.js`

**Test cases:**
1. Renders conversation title
2. Shows project name badge when provided
3. Hides project name badge when not provided
4. Shows last message preview
5. Shows "No messages yet" when no last_message
6. Emits `select` with conversation ID on click
7. Emits `archive` with conversation ID on archive button click
8. Shows relative time (uses fixed dates for deterministic tests)
9. Applies selected styling when `isSelected` is true

---

### Task 7: Write ConversationList component tests

**Files:**
- Create: `resources/js/components/ConversationList.test.js`

**Test cases:**
1. Renders loading spinner when loading with no conversations
2. Renders empty state when no conversations and not loading
3. Renders empty state with search message when search is active
4. Renders conversation items from store
5. Renders project filter dropdown with projects from auth store
6. Search input triggers `setSearchQuery` after debounce
7. Project filter triggers `setProjectFilter`
8. Archive toggle triggers `setShowArchived`
9. Shows "Load more" button when `hasMore` is true
10. Shows error message when store has error

---

### Task 8: Run tests and verify

**Run:** `npm run test --prefix . -- --run`

Verify all new tests pass. Fix any failures.

---

### Task 9: Run verify_m3.py structural checks

**Run:** `python3 verify/verify_m3.py`

Verify all structural checks pass, including new T63 checks.

---

### Task 10: Update progress.md and commit

- Mark T63 `[x]` in progress.md
- Bold T64 as next task
- Update milestone count (16/27)
- Commit all files with message: `T63: Add chat page conversation list with filters and archive toggle`
