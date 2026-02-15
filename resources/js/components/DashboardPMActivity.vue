<script setup>
import { computed, onMounted } from 'vue';
import { useDashboardStore } from '@/stores/dashboard';

const dashboard = useDashboardStore();

onMounted(() => {
    dashboard.fetchPMActivity();
});

const pmActivity = computed(() => dashboard.pmActivity);

const avgTurnsDisplay = computed(() => {
    if (!pmActivity.value || pmActivity.value.avg_turns_per_prd === null) return '—';
    return `${pmActivity.value.avg_turns_per_prd}`;
});
</script>

<template>
  <div data-testid="dashboard-pm-activity">
    <!-- Loading state -->
    <div
      v-if="dashboard.pmActivityLoading && !pmActivity"
      data-testid="pm-activity-loading"
      class="flex items-center justify-center py-12"
    >
      <svg class="animate-spin h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
    </div>

    <!-- PM Activity cards -->
    <div v-else-if="pmActivity" class="space-y-6">
      <!-- Top row: PRDs Created + Conversations Held -->
      <div class="grid grid-cols-2 gap-4">
        <div
          data-testid="prds-created-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">PRDs Created</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="prds-created-value">
            {{ pmActivity.prds_created }}
          </p>
          <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Issues created from PRD conversations
          </p>
        </div>

        <div
          data-testid="conversations-held-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Conversations Held</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="conversations-held-value">
            {{ pmActivity.conversations_held }}
          </p>
          <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Total chat conversations
          </p>
        </div>
      </div>

      <!-- Bottom row: Issues from Chat + Avg Turns per PRD -->
      <div class="grid grid-cols-2 gap-4">
        <div
          data-testid="issues-from-chat-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Issues from Chat</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="issues-from-chat-value">
            {{ pmActivity.issues_from_chat }}
          </p>
          <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Issues created via conversation dispatch
          </p>
        </div>

        <div
          data-testid="avg-turns-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Avg Turns / PRD</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="avg-turns-value">
            {{ avgTurnsDisplay }}
          </p>
          <p v-if="pmActivity.avg_turns_per_prd === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
            No PRDs yet
          </p>
          <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Conversation messages before PRD creation
          </p>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div
      v-else
      data-testid="pm-activity-empty"
      class="flex items-center justify-center py-12"
    >
      <div class="text-center text-zinc-400 dark:text-zinc-500">
        <p class="text-sm">No PM activity data available.</p>
      </div>
    </div>
  </div>
</template>
