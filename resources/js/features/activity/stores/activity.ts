import type { Activity } from '@/types';
import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

export const useActivityStore = defineStore('activity', () => {
    const activityFeed = ref<Activity[]>([]);
    const isLoading = ref(false);
    const nextCursor = ref<string | null>(null);
    const activeFilter = ref<string | null>(null);
    const hasMore = computed(() => nextCursor.value !== null);

    async function fetchActivity(filter: string | null = null) {
        isLoading.value = true;
        activeFilter.value = filter;
        nextCursor.value = null;

        try {
            const params: Record<string, unknown> = { per_page: 25 };
            if (filter)
                params.type = filter;

            const response = await axios.get('/api/v1/activity', { params });
            activityFeed.value = response.data.data;
            nextCursor.value = response.data.meta?.next_cursor ?? null;
        } finally {
            isLoading.value = false;
        }
    }

    async function loadMore() {
        if (!nextCursor.value || isLoading.value)
            return;

        isLoading.value = true;
        try {
            const params: Record<string, unknown> = { per_page: 25, cursor: nextCursor.value };
            if (activeFilter.value)
                params.type = activeFilter.value;

            const response = await axios.get('/api/v1/activity', { params });
            activityFeed.value.push(...response.data.data);
            nextCursor.value = response.data.meta?.next_cursor ?? null;
        } finally {
            isLoading.value = false;
        }
    }

    return {
        activityFeed,
        activeFilter,
        isLoading,
        nextCursor,
        hasMore,
        fetchActivity,
        loadMore,
    };
});
