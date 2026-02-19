<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useConversationsStore } from '@/features/chat';
import { useAuthStore } from '@/stores/auth';
import ConversationListItem from './ConversationListItem.vue';
import NewConversationDialog from './NewConversationDialog.vue';
import BaseButton from './ui/BaseButton.vue';
import BaseSpinner from './ui/BaseSpinner.vue';

const store = useConversationsStore();
const auth = useAuthStore();
const router = useRouter();

const showNewDialog = ref(false);

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

function onSearchInput(event: Event) {
    const target = event.target as HTMLInputElement;
    const query = target.value;
    if (searchTimeout)
        clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        store.setSearchQuery(query);
    }, 300);
}

function onProjectFilter(event: Event) {
    const target = event.target as HTMLSelectElement;
    const value = target.value;
    store.setProjectFilter(value ? Number(value) : null);
}

function onArchiveToggle() {
    store.setShowArchived(!store.showArchived);
}

function onSelect(id: string) {
    router.push({ name: 'chat-conversation', params: { id } });
}

async function onArchive(id: string) {
    const wasSelected = store.selectedId === id;
    await store.toggleArchive(id);
    if (wasSelected) {
        router.push({ name: 'chat' });
    }
}

function projectNameForId(projectId: number): string {
    const project = auth.projects.find(p => p.id === projectId);
    return project ? project.name : '';
}

async function onCreateConversation(projectId: number) {
    try {
        const conversation = await store.createConversation(projectId);
        showNewDialog.value = false;
        router.push({ name: 'chat-conversation', params: { id: conversation.id } });
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
            <BaseButton
                variant="primary"
                size="md"
                class="w-full"
                @click="showNewDialog = true"
            >
                + New Conversation
            </BaseButton>
        </div>

        <!-- Search + filters -->
        <div class="p-3 space-y-2 border-b border-zinc-200 dark:border-zinc-800">
            <input
                type="text"
                placeholder="Search conversations..."
                class="w-full px-3 py-1.5 text-sm rounded-[var(--radius-input)] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 dark:placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                @input="onSearchInput"
            >
            <div class="flex items-center gap-2">
                <select
                    class="flex-1 px-2 py-1 text-xs rounded-[var(--radius-input)] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-700 dark:text-zinc-300"
                    @change="onProjectFilter"
                >
                    <option value="">
                        All projects
                    </option>
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
                    class="px-2 py-1 text-xs rounded-[var(--radius-button)] border transition-colors"
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
                <BaseSpinner size="md" />
            </div>

            <!-- Empty state -->
            <div
                v-else-if="!store.loading && store.conversations.length === 0"
                class="p-4 text-center text-sm text-zinc-500 dark:text-zinc-400"
            >
                <p v-if="store.searchQuery">
                    No conversations match your search.
                </p>
                <p v-else-if="store.showArchived">
                    No archived conversations.
                </p>
                <p v-else>
                    No conversations yet. Start a new one!
                </p>
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
