<script setup>
import { computed, onMounted } from 'vue';
import { useDashboardStore } from '@/stores/dashboard';
import { useAdminStore } from '@/stores/admin';

const dashboard = useDashboardStore();
const admin = useAdminStore();

onMounted(() => {
    dashboard.fetchCost();
    dashboard.fetchCostAlerts();
});

const cost = computed(() => dashboard.cost);
const costAlerts = computed(() => dashboard.costAlerts);

const severityColors = {
    critical: 'bg-red-100 dark:bg-red-900/30 border-red-300 dark:border-red-700 text-red-800 dark:text-red-200',
    warning: 'bg-amber-100 dark:bg-amber-900/30 border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-200',
};

const ruleLabels = {
    monthly_anomaly: 'Monthly Anomaly',
    daily_spike: 'Daily Spike',
    single_task_outlier: 'Single Task Outlier',
    approaching_projection: 'Approaching Projection',
};

async function handleAcknowledge(alertId) {
    await admin.acknowledgeCostAlert(alertId);
    dashboard.fetchCostAlerts();
}

const typeLabels = {
    code_review: 'Code Review',
    issue_discussion: 'Issue Discussion',
    feature_dev: 'Feature Dev',
    ui_adjustment: 'UI Adjustment',
    prd_creation: 'PRD Creation',
    security_audit: 'Security Audit',
    deep_analysis: 'Deep Analysis',
};

const totalCostDisplay = computed(() => {
    if (!cost.value) return '—';
    return `$${cost.value.total_cost.toFixed(2)}`;
});

const totalTokensDisplay = computed(() => {
    if (!cost.value) return '—';
    return cost.value.total_tokens.toLocaleString();
});

const tokenUsageEntries = computed(() => {
    if (!cost.value?.token_usage_by_type) return [];
    return Object.entries(cost.value.token_usage_by_type).map(([key, tokens]) => ({
        key,
        label: typeLabels[key] || key,
        tokens,
    }));
});

const costPerTypeEntries = computed(() => {
    if (!cost.value?.cost_per_type) return [];
    return Object.entries(cost.value.cost_per_type).map(([key, data]) => ({
        key,
        label: typeLabels[key] || key,
        avgCost: data.avg_cost,
        totalCost: data.total_cost,
        taskCount: data.task_count,
    }));
});

const costPerProject = computed(() => {
    if (!cost.value?.cost_per_project) return [];
    return cost.value.cost_per_project;
});

const monthlyTrend = computed(() => {
    if (!cost.value?.monthly_trend) return [];
    return cost.value.monthly_trend;
});
</script>

<template>
  <div data-testid="dashboard-cost">
    <!-- Loading state -->
    <div
      v-if="dashboard.costLoading && !cost"
      data-testid="cost-loading"
      class="flex items-center justify-center py-12"
    >
      <svg class="animate-spin h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
    </div>

    <!-- Cost data -->
    <div v-else-if="cost" class="space-y-6">
      <!-- Active cost alerts (T94) -->
      <div v-if="costAlerts.length > 0" data-testid="cost-alerts">
        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">Active Alerts</h3>
        <div class="space-y-2">
          <div
            v-for="alert in costAlerts"
            :key="alert.id"
            :data-testid="`cost-alert-${alert.id}`"
            :class="['rounded-lg border p-3 flex items-start justify-between', severityColors[alert.severity] || severityColors.warning]"
          >
            <div>
              <span class="text-xs font-semibold uppercase">{{ ruleLabels[alert.rule] || alert.rule }}</span>
              <p class="text-sm mt-0.5">{{ alert.message }}</p>
              <p class="text-xs opacity-70 mt-1">{{ new Date(alert.created_at).toLocaleString() }}</p>
            </div>
            <button
              data-testid="acknowledge-btn"
              class="ml-3 flex-shrink-0 text-xs font-medium underline opacity-70 hover:opacity-100"
              @click="handleAcknowledge(alert.id)"
            >
              Dismiss
            </button>
          </div>
        </div>
      </div>

      <!-- Summary row: Total Cost + Total Tokens -->
      <div class="grid grid-cols-2 gap-4">
        <div
          data-testid="total-cost-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Total Cost</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="total-cost-value">
            {{ totalCostDisplay }}
          </p>
          <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            All completed tasks
          </p>
        </div>

        <div
          data-testid="total-tokens-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Total Tokens</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="total-tokens-value">
            {{ totalTokensDisplay }}
          </p>
          <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            All completed and failed tasks
          </p>
        </div>
      </div>

      <!-- Token usage by type -->
      <div>
        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">Token Usage by Type</h3>
        <div
          v-if="tokenUsageEntries.length > 0"
          class="grid grid-cols-3 gap-4"
          data-testid="token-usage-by-type"
        >
          <div
            v-for="entry in tokenUsageEntries"
            :key="entry.key"
            :data-testid="`token-usage-${entry.key}`"
            class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4 text-center"
          >
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">{{ entry.label }}</p>
            <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" :data-testid="`token-usage-${entry.key}-value`">
              {{ entry.tokens.toLocaleString() }}
            </p>
          </div>
        </div>
        <div
          v-else
          data-testid="token-usage-empty"
          class="text-center text-zinc-400 dark:text-zinc-500 py-4"
        >
          <p class="text-sm">No token usage data yet.</p>
        </div>
      </div>

      <!-- Cost per type -->
      <div>
        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">Cost per Task Type</h3>
        <div
          v-if="costPerTypeEntries.length > 0"
          data-testid="cost-per-type"
        >
          <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
              <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                  <th class="px-4 py-2 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Type</th>
                  <th class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Avg Cost</th>
                  <th class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Total Cost</th>
                  <th class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Tasks</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700 bg-white dark:bg-zinc-900">
                <tr
                  v-for="entry in costPerTypeEntries"
                  :key="entry.key"
                  :data-testid="`cost-type-${entry.key}`"
                >
                  <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">{{ entry.label }}</td>
                  <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">${{ entry.avgCost.toFixed(4) }}</td>
                  <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">${{ entry.totalCost.toFixed(2) }}</td>
                  <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">{{ entry.taskCount }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div
          v-else
          data-testid="cost-per-type-empty"
          class="text-center text-zinc-400 dark:text-zinc-500 py-4"
        >
          <p class="text-sm">No cost data by type yet.</p>
        </div>
      </div>

      <!-- Cost per project -->
      <div>
        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">Cost per Project</h3>
        <div
          v-if="costPerProject.length > 0"
          data-testid="cost-per-project"
        >
          <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
              <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                  <th class="px-4 py-2 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Project</th>
                  <th class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Total Cost</th>
                  <th class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Tasks</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700 bg-white dark:bg-zinc-900">
                <tr
                  v-for="project in costPerProject"
                  :key="project.project_id"
                  :data-testid="`cost-project-${project.project_id}`"
                >
                  <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">{{ project.project_name }}</td>
                  <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">${{ project.total_cost.toFixed(2) }}</td>
                  <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">{{ project.task_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div
          v-else
          data-testid="cost-per-project-empty"
          class="text-center text-zinc-400 dark:text-zinc-500 py-4"
        >
          <p class="text-sm">No cost data by project yet.</p>
        </div>
      </div>

      <!-- Monthly trend -->
      <div>
        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">Monthly Trend</h3>
        <div
          v-if="monthlyTrend.length > 0"
          data-testid="monthly-trend"
        >
          <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
              <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                  <th class="px-4 py-2 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Month</th>
                  <th class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Cost</th>
                  <th class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Tokens</th>
                  <th class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Tasks</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700 bg-white dark:bg-zinc-900">
                <tr
                  v-for="month in monthlyTrend"
                  :key="month.month"
                  :data-testid="`trend-${month.month}`"
                >
                  <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">{{ month.month }}</td>
                  <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">${{ month.total_cost.toFixed(2) }}</td>
                  <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">{{ month.total_tokens.toLocaleString() }}</td>
                  <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">{{ month.task_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div
          v-else
          data-testid="monthly-trend-empty"
          class="text-center text-zinc-400 dark:text-zinc-500 py-4"
        >
          <p class="text-sm">No monthly trend data yet.</p>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div
      v-else
      data-testid="cost-empty"
      class="flex items-center justify-center py-12"
    >
      <div class="text-center text-zinc-400 dark:text-zinc-500">
        <p class="text-sm">No cost data available.</p>
      </div>
    </div>
  </div>
</template>
