<script setup>
import { ref, onMounted } from 'vue';
import { useConversationsStore } from '@/stores/conversations';
import { useAuthStore } from '@/stores/auth';
import ConversationListItem from './ConversationListItem.vue';
import NewConversationDialog from './NewConversationDialog.vue';

const store = useConversationsStore();
const auth = useAuthStore();

const showNewDialog = ref(false);

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

async function onCreateConversation(projectId) {
    try {
        await store.createConversation(projectId);
        showNewDialog.value = false;
    } catch {
        // Error is set in the store; dialog stays open so user can retry
    }
}

onMounted(() => {
    store.fetchConversations();
});
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- New conversation button -->
    <div class="p-3 border-b border-zinc-200 dark:border-zinc-800">
      <button
        type="button"
        class="w-full px-3 py-2 text-sm font-medium rounded-lg bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:bg-zinc-800 dark:hover:bg-zinc-200 transition-colors"
        @click="showNewDialog = true"
      >
        + New Conversation
      </button>
    </div>

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

    <!-- New conversation dialog -->
    <NewConversationDialog
      v-if="showNewDialog"
      @create="onCreateConversation"
      @close="showNewDialog = false"
    />
  </div>
</template>
