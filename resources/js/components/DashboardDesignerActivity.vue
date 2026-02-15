<script setup>
import { computed, onMounted } from 'vue';
import { useDashboardStore } from '@/stores/dashboard';

const dashboard = useDashboardStore();

onMounted(() => {
    dashboard.fetchDesignerActivity();
});

const designerActivity = computed(() => dashboard.designerActivity);

const avgIterationsDisplay = computed(() => {
    if (!designerActivity.value || designerActivity.value.avg_iterations === null) return '—';
    return `${designerActivity.value.avg_iterations}`;
});

const successRateDisplay = computed(() => {
    if (!designerActivity.value || designerActivity.value.first_attempt_success_rate === null) return '—';
    return `${designerActivity.value.first_attempt_success_rate}%`;
});
</script>

<template>
  <div data-testid="dashboard-designer-activity">
    <!-- Loading state -->
    <div
      v-if="dashboard.designerActivityLoading && !designerActivity"
      data-testid="designer-activity-loading"
      class="flex items-center justify-center py-12"
    >
      <svg class="animate-spin h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
    </div>

    <!-- Designer Activity cards -->
    <div v-else-if="designerActivity" class="space-y-6">
      <!-- Top row: UI Adjustments Dispatched + Avg Iterations -->
      <div class="grid grid-cols-2 gap-4">
        <div
          data-testid="ui-adjustments-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">UI Adjustments</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="ui-adjustments-value">
            {{ designerActivity.ui_adjustments_dispatched }}
          </p>
          <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Completed UI adjustment tasks
          </p>
        </div>

        <div
          data-testid="avg-iterations-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Avg Iterations</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="avg-iterations-value">
            {{ avgIterationsDisplay }}
          </p>
          <p v-if="designerActivity.avg_iterations === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
            No adjustments yet
          </p>
          <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Attempts per UI adjustment
          </p>
        </div>
      </div>

      <!-- Bottom row: MRs from Chat + First-Attempt Success Rate -->
      <div class="grid grid-cols-2 gap-4">
        <div
          data-testid="mrs-from-chat-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">MRs from Chat</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="mrs-from-chat-value">
            {{ designerActivity.mrs_created_from_chat }}
          </p>
          <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Merge requests created via conversation
          </p>
        </div>

        <div
          data-testid="success-rate-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">First-Attempt Success</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="success-rate-value">
            {{ successRateDisplay }}
          </p>
          <p v-if="designerActivity.first_attempt_success_rate === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
            No adjustments yet
          </p>
          <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Completed on first attempt
          </p>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div
      v-else
      data-testid="designer-activity-empty"
      class="flex items-center justify-center py-12"
    >
      <div class="text-center text-zinc-400 dark:text-zinc-500">
        <p class="text-sm">No designer activity data available.</p>
      </div>
    </div>
  </div>
</template>
