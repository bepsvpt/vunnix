<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useDashboardStore } from '@/features/dashboard';
import BaseCard from './ui/BaseCard.vue';
import BaseEmptyState from './ui/BaseEmptyState.vue';
import BaseSpinner from './ui/BaseSpinner.vue';

const dashboard = useDashboardStore();

onMounted(() => {
    dashboard.fetchEfficiency();
});

const efficiency = computed(() => dashboard.efficiency);

const timeToFirstReviewDisplay = computed(() => {
    if (!efficiency.value || efficiency.value.time_to_first_review === null)
        return '—';
    return `${efficiency.value.time_to_first_review} min`;
});

const reviewTurnaroundDisplay = computed(() => {
    if (!efficiency.value || efficiency.value.review_turnaround === null)
        return '—';
    return `${efficiency.value.review_turnaround} min`;
});

const typeLabels: Record<string, string> = {
    code_review: 'Code Review',
    issue_discussion: 'Issue Discussion',
    feature_dev: 'Feature Dev',
    ui_adjustment: 'UI Adjustment',
    prd_creation: 'PRD Creation',
    security_audit: 'Security Audit',
    deep_analysis: 'Deep Analysis',
};

const completionRateEntries = computed(() => {
    if (!efficiency.value?.completion_rate_by_type)
        return [];
    return Object.entries(efficiency.value.completion_rate_by_type as Record<string, number>).map(([key, rate]) => ({
        key,
        label: typeLabels[key] || key,
        rate,
    }));
});
</script>

<template>
    <div data-testid="dashboard-efficiency">
        <!-- Loading state -->
        <div
            v-if="dashboard.efficiencyLoading && !efficiency"
            data-testid="efficiency-loading"
            class="flex items-center justify-center py-12"
        >
            <BaseSpinner size="md" />
        </div>

        <!-- Efficiency cards -->
        <div v-else-if="efficiency" class="space-y-6">
            <!-- Top row: Time to First Review + Review Turnaround -->
            <div class="grid grid-cols-2 gap-4">
                <BaseCard
                    data-testid="time-to-first-review-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Time to First Review
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="time-to-first-review-value">
                        {{ timeToFirstReviewDisplay }}
                    </p>
                    <p v-if="efficiency.time_to_first_review === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                        No completed reviews yet
                    </p>
                    <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Avg wait before processing starts
                    </p>
                </BaseCard>

                <BaseCard
                    data-testid="review-turnaround-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Review Turnaround
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="review-turnaround-value">
                        {{ reviewTurnaroundDisplay }}
                    </p>
                    <p v-if="efficiency.review_turnaround === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                        No completed reviews yet
                    </p>
                    <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Avg total time from creation to completion
                    </p>
                </BaseCard>
            </div>

            <!-- Completion rate by type -->
            <div>
                <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                    Completion Rate by Type
                </h3>
                <div
                    v-if="completionRateEntries.length > 0"
                    class="grid grid-cols-3 gap-4"
                    data-testid="completion-rate-by-type"
                >
                    <BaseCard
                        v-for="entry in completionRateEntries"
                        :key="entry.key"
                        :data-testid="`completion-rate-${entry.key}`"
                        class="text-center"
                    >
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                            {{ entry.label }}
                        </p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" :data-testid="`completion-rate-${entry.key}-value`">
                            {{ entry.rate }}%
                        </p>
                    </BaseCard>
                </div>
                <BaseEmptyState v-else data-testid="completion-rate-empty">
                    <template #description>
                        No completed or failed tasks yet.
                    </template>
                </BaseEmptyState>
            </div>
        </div>

        <!-- Empty state -->
        <BaseEmptyState v-else data-testid="efficiency-empty">
            <template #description>
                No efficiency data available.
            </template>
        </BaseEmptyState>
    </div>
</template>
