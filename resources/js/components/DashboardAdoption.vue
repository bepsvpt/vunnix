<script setup>
import { computed, onMounted } from 'vue';
import { useDashboardStore } from '@/stores/dashboard';

const dashboard = useDashboardStore();

onMounted(() => {
    dashboard.fetchAdoption();
});

const adoption = computed(() => dashboard.adoption);

const typeLabels = {
    code_review: 'Code Review',
    issue_discussion: 'Issue Discussion',
    feature_dev: 'Feature Dev',
    ui_adjustment: 'UI Adjustment',
    prd_creation: 'PRD Creation',
    security_audit: 'Security Audit',
    deep_analysis: 'Deep Analysis',
};

const mrPercentDisplay = computed(() => {
    if (!adoption.value || adoption.value.ai_reviewed_mr_percent === null)
        return '—';
    return `${adoption.value.ai_reviewed_mr_percent}%`;
});

const mrCountDisplay = computed(() => {
    if (!adoption.value)
        return '';
    return `${adoption.value.reviewed_mr_count} of ${adoption.value.total_mr_count} MRs`;
});

const activeUsersDisplay = computed(() => {
    if (!adoption.value)
        return '—';
    return adoption.value.chat_active_users.toLocaleString();
});

const tasksByTypeMonths = computed(() => {
    if (!adoption.value?.tasks_by_type_over_time)
        return [];
    return Object.entries(adoption.value.tasks_by_type_over_time).map(([month, types]) => ({
        month,
        types,
    }));
});

const allTypeKeys = computed(() => {
    if (!adoption.value?.tasks_by_type_over_time)
        return [];
    const keys = new Set();
    Object.values(adoption.value.tasks_by_type_over_time).forEach((types) => {
        Object.keys(types).forEach(k => keys.add(k));
    });
    return [...keys];
});

const aiMentions = computed(() => {
    if (!adoption.value?.ai_mentions_per_week)
        return [];
    return adoption.value.ai_mentions_per_week;
});
</script>

<template>
    <div data-testid="dashboard-adoption">
        <!-- Loading state -->
        <div
            v-if="dashboard.adoptionLoading && !adoption"
            data-testid="adoption-loading"
            class="flex items-center justify-center py-12"
        >
            <svg class="animate-spin h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
        </div>

        <!-- Adoption data -->
        <div v-else-if="adoption" class="space-y-6">
            <!-- Summary row: AI-Reviewed MR % + Chat Active Users -->
            <div class="grid grid-cols-2 gap-4">
                <div
                    data-testid="ai-reviewed-mr-card"
                    class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        AI-Reviewed MR %
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="ai-reviewed-mr-value">
                        {{ mrPercentDisplay }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" data-testid="ai-reviewed-mr-count">
                        <template v-if="adoption.ai_reviewed_mr_percent !== null">
                            {{ mrCountDisplay }}
                        </template>
                        <template v-else>
                            No merge requests yet
                        </template>
                    </p>
                </div>

                <div
                    data-testid="chat-active-users-card"
                    class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Chat Active Users
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="chat-active-users-value">
                        {{ activeUsersDisplay }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Distinct users with conversations
                    </p>
                </div>
            </div>

            <!-- Tasks by type over time -->
            <div>
                <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                    Tasks by Type Over Time
                </h3>
                <div
                    v-if="tasksByTypeMonths.length > 0"
                    data-testid="tasks-by-type-over-time"
                >
                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">
                                        Month
                                    </th>
                                    <th
                                        v-for="typeKey in allTypeKeys"
                                        :key="typeKey"
                                        class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase"
                                    >
                                        {{ typeLabels[typeKey] || typeKey }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700 bg-white dark:bg-zinc-900">
                                <tr
                                    v-for="row in tasksByTypeMonths"
                                    :key="row.month"
                                    :data-testid="`tasks-month-${row.month}`"
                                >
                                    <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                        {{ row.month }}
                                    </td>
                                    <td
                                        v-for="typeKey in allTypeKeys"
                                        :key="typeKey"
                                        class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{ row.types[typeKey] || 0 }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div
                    v-else
                    data-testid="tasks-by-type-empty"
                    class="text-center text-zinc-400 dark:text-zinc-500 py-4"
                >
                    <p class="text-sm">
                        No task data over time yet.
                    </p>
                </div>
            </div>

            <!-- @ai mentions per week -->
            <div>
                <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                    @ai Mentions per Week
                </h3>
                <div
                    v-if="aiMentions.length > 0"
                    data-testid="ai-mentions-per-week"
                >
                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">
                                        Week
                                    </th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">
                                        Mentions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700 bg-white dark:bg-zinc-900">
                                <tr
                                    v-for="entry in aiMentions"
                                    :key="entry.week"
                                    :data-testid="`mentions-${entry.week}`"
                                >
                                    <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                        {{ entry.week }}
                                    </td>
                                    <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">
                                        {{ entry.count }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div
                    v-else
                    data-testid="ai-mentions-empty"
                    class="text-center text-zinc-400 dark:text-zinc-500 py-4"
                >
                    <p class="text-sm">
                        No @ai mention data yet.
                    </p>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div
            v-else
            data-testid="adoption-empty"
            class="flex items-center justify-center py-12"
        >
            <div class="text-center text-zinc-400 dark:text-zinc-500">
                <p class="text-sm">
                    No adoption data available.
                </p>
            </div>
        </div>
    </div>
</template>
