<script setup lang="ts">
import ActivityFeedItem from '@/components/ActivityFeedItem.vue';
import BaseEmptyState from '@/components/ui/BaseEmptyState.vue';
import BaseFilterChips from '@/components/ui/BaseFilterChips.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import { useDashboardStore } from '@/stores/dashboard';

const dashboard = useDashboardStore();

const chips: Array<{ label: string; value: string | null }> = [
    { label: 'All', value: null },
    { label: 'Reviews', value: 'code_review' },
    { label: 'Feature Dev', value: 'feature_dev' },
    { label: 'UI Adjustments', value: 'ui_adjustment' },
    { label: 'PRDs', value: 'prd_creation' },
];

function onFilterChange(value: string | null) {
    dashboard.fetchActivity(value);
}
</script>

<template>
    <div data-testid="activity-feed">
        <!-- Filter chips -->
        <div class="mb-4">
            <BaseFilterChips
                :chips="chips"
                :model-value="dashboard.activeFilter"
                @update:model-value="onFilterChange"
            />
        </div>

        <!-- Loading indicator -->
        <div
            v-if="dashboard.isLoading && dashboard.activityFeed.length === 0"
            data-testid="loading-indicator"
            class="flex items-center justify-center py-12"
        >
            <BaseSpinner size="md" />
        </div>

        <!-- Empty state -->
        <BaseEmptyState
            v-else-if="!dashboard.isLoading && dashboard.activityFeed.length === 0"
            data-testid="empty-state"
        >
            <template #icon>
                <svg class="w-10 h-10 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </template>
            <template #title>
                No activity yet
            </template>
            <template #description>
                Activity from your projects will appear here as tasks are processed.
            </template>
        </BaseEmptyState>

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
                    class="px-4 py-2 text-sm font-medium rounded-[var(--radius-button)] border border-zinc-300 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="dashboard.isLoading"
                    @click="dashboard.loadMore()"
                >
                    <span v-if="dashboard.isLoading" class="inline-flex items-center gap-2">
                        <BaseSpinner size="sm" />
                        Loading...
                    </span>
                    <span v-else>Load more</span>
                </button>
            </div>
        </div>
    </div>
</template>
