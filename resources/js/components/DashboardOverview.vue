<script setup>
import { computed } from 'vue';
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
    const date = new Date(overview.value.recent_activity);
    const now = new Date();
    const diffMs = now - date;
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
            <svg class="animate-spin h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
        </div>

        <!-- Summary cards -->
        <div v-else-if="overview" class="space-y-6">
            <!-- Top row: Active tasks + Success rate + Recent activity -->
            <div class="grid grid-cols-3 gap-4">
                <div
                    data-testid="active-tasks-card"
                    class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
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
                </div>

                <div
                    data-testid="success-rate-card"
                    class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
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
                </div>

                <div
                    data-testid="recent-activity-card"
                    class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Recent Activity
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="recent-activity-value">
                        {{ recentActivityDisplay }}
                    </p>
                </div>
            </div>

            <!-- Bottom row: Tasks by type -->
            <div>
                <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                    Tasks by Type
                </h3>
                <div class="grid grid-cols-4 gap-4">
                    <div
                        v-for="card in typeCards"
                        :key="card.key"
                        :data-testid="`type-card-${card.key}`"
                        class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4 text-center"
                    >
                        <span class="text-2xl">{{ card.icon }}</span>
                        <p class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" :data-testid="`type-count-${card.key}`">
                            {{ overview.tasks_by_type[card.key] ?? 0 }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ card.label }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state (no data loaded) -->
        <div
            v-else
            data-testid="overview-empty"
            class="flex items-center justify-center py-12"
        >
            <div class="text-center text-zinc-400 dark:text-zinc-500">
                <p class="text-sm">
                    No overview data available.
                </p>
            </div>
        </div>
    </div>
</template>
