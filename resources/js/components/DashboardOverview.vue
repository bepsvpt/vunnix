<script setup lang="ts">
import { computed } from 'vue';
import BaseCard from '@/components/ui/BaseCard.vue';
import BaseEmptyState from '@/components/ui/BaseEmptyState.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import { useDashboardStore } from '@/stores/dashboard';

const dashboard = useDashboardStore();

const typeCards = [
    { key: 'code_review', label: 'Code Reviews', icon: '\uD83D\uDD0D' },
    { key: 'feature_dev', label: 'Feature Dev', icon: '\u2699\uFE0F' },
    { key: 'ui_adjustment', label: 'UI Adjustments', icon: '\uD83C\uDFA8' },
    { key: 'prd_creation', label: 'PRDs', icon: '\uD83D\uDCCB' },
];

const overview = computed(() => dashboard.overview);

const successRateDisplay = computed(() => {
    if (!overview.value || overview.value.success_rate === null)
        return '—';
    return `${overview.value.success_rate}%`;
});

const recentActivityDisplay = computed(() => {
    if (!overview.value?.recent_activity)
        return 'No activity';
    const date = new Date(overview.value.recent_activity as string);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);
    const diffHr = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr / 24);
    if (diffMin < 1)
        return 'Just now';
    if (diffMin < 60)
        return `${diffMin}m ago`;
    if (diffHr < 24)
        return `${diffHr}h ago`;
    if (diffDay < 30)
        return `${diffDay}d ago`;
    return date.toLocaleDateString();
});
</script>

<template>
    <div data-testid="dashboard-overview">
        <!-- Loading state -->
        <div
            v-if="dashboard.overviewLoading && !overview"
            data-testid="overview-loading"
            class="flex items-center justify-center py-12"
        >
            <BaseSpinner size="md" />
        </div>

        <!-- Error / null state — retry -->
        <BaseEmptyState
            v-else-if="!overview"
            data-testid="overview-empty"
        >
            <template #icon>
                <svg class="w-10 h-10 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
            </template>
            <template #title>
                Unable to load overview
            </template>
            <template #description>
                Something went wrong fetching dashboard data.
            </template>
            <template #action>
                <button
                    data-testid="retry-btn"
                    class="px-4 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:bg-zinc-800 dark:hover:bg-zinc-200 transition-colors"
                    @click="dashboard.fetchOverview()"
                >
                    Retry
                </button>
            </template>
        </BaseEmptyState>

        <!-- Onboarding state — all zeros -->
        <BaseEmptyState
            v-else-if="dashboard.isAllZeros"
            data-testid="overview-onboarding"
        >
            <template #icon>
                <svg class="w-10 h-10 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                </svg>
            </template>
            <template #title>
                Welcome to Vunnix
            </template>
            <template #description>
                Enable a project and start a conversation to see your dashboard come alive.
            </template>
            <template #action>
                <div class="flex items-center gap-3">
                    <RouterLink
                        to="/admin"
                        data-testid="onboarding-admin-link"
                        class="px-4 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:bg-zinc-800 dark:hover:bg-zinc-200 transition-colors"
                    >
                        Enable a project
                    </RouterLink>
                    <RouterLink
                        to="/chat"
                        data-testid="onboarding-chat-link"
                        class="px-4 py-2 text-sm font-medium rounded-[var(--radius-button)] border border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
                    >
                        Start a conversation
                    </RouterLink>
                </div>
            </template>
        </BaseEmptyState>

        <!-- Normal data state -->
        <div v-else class="space-y-6">
            <!-- Top row: Active tasks + Success rate + Recent activity -->
            <div class="grid grid-cols-3 gap-4">
                <BaseCard
                    padded
                    data-testid="active-tasks-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Active Tasks
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="active-tasks-count">
                        {{ overview.active_tasks }}
                    </p>
                    <p v-if="overview.active_tasks > 0" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        ⏳ In progress
                    </p>
                </BaseCard>

                <BaseCard
                    padded
                    data-testid="success-rate-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Success Rate
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="success-rate-value">
                        {{ successRateDisplay }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ overview.total_completed }} completed · {{ overview.total_failed }} failed
                    </p>
                </BaseCard>

                <BaseCard
                    padded
                    data-testid="recent-activity-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Recent Activity
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="recent-activity-value">
                        {{ recentActivityDisplay }}
                    </p>
                </BaseCard>
            </div>

            <!-- Bottom row: Tasks by type -->
            <div>
                <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                    Tasks by Type
                </h3>
                <div class="grid grid-cols-4 gap-4">
                    <BaseCard
                        v-for="card in typeCards"
                        :key="card.key"
                        padded
                        :data-testid="`type-card-${card.key}`"
                        class="text-center"
                    >
                        <span class="text-2xl">{{ card.icon }}</span>
                        <p class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" :data-testid="`type-count-${card.key}`">
                            {{ overview.tasks_by_type[card.key] ?? 0 }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ card.label }}
                        </p>
                    </BaseCard>
                </div>
            </div>
        </div>
    </div>
</template>
