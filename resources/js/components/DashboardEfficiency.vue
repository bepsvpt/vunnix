<script setup>
import { computed, onMounted } from 'vue';
import { useDashboardStore } from '@/stores/dashboard';

const dashboard = useDashboardStore();

onMounted(() => {
    dashboard.fetchEfficiency();
});

const efficiency = computed(() => dashboard.efficiency);

const timeToFirstReviewDisplay = computed(() => {
    if (!efficiency.value || efficiency.value.time_to_first_review === null) return '—';
    return `${efficiency.value.time_to_first_review} min`;
});

const reviewTurnaroundDisplay = computed(() => {
    if (!efficiency.value || efficiency.value.review_turnaround === null) return '—';
    return `${efficiency.value.review_turnaround} min`;
});

const typeLabels = {
    code_review: 'Code Review',
    issue_discussion: 'Issue Discussion',
    feature_dev: 'Feature Dev',
    ui_adjustment: 'UI Adjustment',
    prd_creation: 'PRD Creation',
    security_audit: 'Security Audit',
    deep_analysis: 'Deep Analysis',
};

const completionRateEntries = computed(() => {
    if (!efficiency.value?.completion_rate_by_type) return [];
    return Object.entries(efficiency.value.completion_rate_by_type).map(([key, rate]) => ({
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
      <svg class="animate-spin h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
    </div>

    <!-- Efficiency cards -->
    <div v-else-if="efficiency" class="space-y-6">
      <!-- Top row: Time to First Review + Review Turnaround -->
      <div class="grid grid-cols-2 gap-4">
        <div
          data-testid="time-to-first-review-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Time to First Review</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="time-to-first-review-value">
            {{ timeToFirstReviewDisplay }}
          </p>
          <p v-if="efficiency.time_to_first_review === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
            No completed reviews yet
          </p>
          <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Avg wait before processing starts
          </p>
        </div>

        <div
          data-testid="review-turnaround-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Review Turnaround</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="review-turnaround-value">
            {{ reviewTurnaroundDisplay }}
          </p>
          <p v-if="efficiency.review_turnaround === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
            No completed reviews yet
          </p>
          <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Avg total time from creation to completion
          </p>
        </div>
      </div>

      <!-- Completion rate by type -->
      <div>
        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">Completion Rate by Type</h3>
        <div
          v-if="completionRateEntries.length > 0"
          class="grid grid-cols-3 gap-4"
          data-testid="completion-rate-by-type"
        >
          <div
            v-for="entry in completionRateEntries"
            :key="entry.key"
            :data-testid="`completion-rate-${entry.key}`"
            class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4 text-center"
          >
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">{{ entry.label }}</p>
            <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" :data-testid="`completion-rate-${entry.key}-value`">
              {{ entry.rate }}%
            </p>
          </div>
        </div>
        <div
          v-else
          data-testid="completion-rate-empty"
          class="text-center text-zinc-400 dark:text-zinc-500 py-4"
        >
          <p class="text-sm">No completed or failed tasks yet.</p>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div
      v-else
      data-testid="efficiency-empty"
      class="flex items-center justify-center py-12"
    >
      <div class="text-center text-zinc-400 dark:text-zinc-500">
        <p class="text-sm">No efficiency data available.</p>
      </div>
    </div>
  </div>
</template>
