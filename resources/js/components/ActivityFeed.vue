<script setup>
import ActivityFeedItem from '@/components/ActivityFeedItem.vue';
import { useDashboardStore } from '@/stores/dashboard';

const dashboard = useDashboardStore();

const tabs = [
    { label: 'All', value: null },
    { label: 'Reviews', value: 'code_review' },
    { label: 'Feature Dev', value: 'feature_dev' },
    { label: 'UI Adjustments', value: 'ui_adjustment' },
    { label: 'PRDs', value: 'prd_creation' },
];

function selectTab(value) {
    dashboard.fetchActivity(value);
}
</script>

<template>
    <div data-testid="activity-feed">
        <!-- Filter tabs -->
        <div class="flex items-center gap-2 mb-4">
            <button
                v-for="tab in tabs"
                :key="tab.label"
                :data-testid="`filter-tab-${tab.value ?? 'all'}`"
                class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                :class="dashboard.activeFilter === tab.value
                    ? 'border-zinc-500 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300'
                    : 'border-zinc-300 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                @click="selectTab(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- Loading indicator -->
        <div
            v-if="dashboard.isLoading && dashboard.activityFeed.length === 0"
            data-testid="loading-indicator"
            class="flex items-center justify-center py-12"
        >
            <svg class="animate-spin h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
        </div>

        <!-- Empty state -->
        <div
            v-else-if="!dashboard.isLoading && dashboard.activityFeed.length === 0"
            data-testid="empty-state"
            class="flex items-center justify-center py-12"
        >
            <div class="text-center text-zinc-400 dark:text-zinc-500">
                <svg class="w-10 h-10 mx-auto mb-2 opacity-50" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm">
                    No activity yet.
                </p>
            </div>
        </div>

        <!-- Feed list -->
        <div v-else class="space-y-1">
            <ActivityFeedItem
                v-for="item in dashboard.activityFeed"
                :key="item.task_id"
                :item="item"
            />

            <!-- Load more button -->
            <div v-if="dashboard.hasMore" class="pt-4 text-center">
                <button
                    data-testid="load-more-btn"
                    class="px-4 py-2 text-sm font-medium rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="dashboard.isLoading"
                    @click="dashboard.loadMore()"
                >
                    <span v-if="dashboard.isLoading" class="inline-flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        Loading...
                    </span>
                    <span v-else>Load more</span>
                </button>
            </div>
        </div>
    </div>
</template>
