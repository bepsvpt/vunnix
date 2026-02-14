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
            const params = { per_page: 25 };
            if (projectFilter.value) params.project_id = projectFilter.value;
            if (searchQuery.value) params.search = searchQuery.value;
            if (showArchived.value) params.archived = 1;

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
            conversations.value = conversations.value.filter((c) => c.id !== conversationId);
            if (selectedId.value === conversationId) {
                selectedId.value = null;
            }
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to archive';
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
        setProjectFilter,
        setSearchQuery,
        setShowArchived,
        selectConversation,
        $reset,
    };
});
