<script setup>
import { computed, onMounted } from 'vue';
import { useDashboardStore } from '@/stores/dashboard';

const dashboard = useDashboardStore();

onMounted(() => {
    dashboard.fetchQuality();
});

const quality = computed(() => dashboard.quality);

const acceptanceRateDisplay = computed(() => {
    if (!quality.value || quality.value.acceptance_rate === null) return '—';
    return `${quality.value.acceptance_rate}%`;
});

const avgFindingsDisplay = computed(() => {
    if (!quality.value || quality.value.avg_findings_per_review === null) return '—';
    return `${quality.value.avg_findings_per_review}`;
});

const severityTotal = computed(() => {
    if (!quality.value?.severity_distribution) return 0;
    const d = quality.value.severity_distribution;
    return d.critical + d.major + d.minor;
});

function severityPercent(count) {
    if (severityTotal.value === 0) return 0;
    return Math.round((count / severityTotal.value) * 100);
}
</script>

<template>
  <div data-testid="dashboard-quality">
    <!-- Loading state -->
    <div
      v-if="dashboard.qualityLoading && !quality"
      data-testid="quality-loading"
      class="flex items-center justify-center py-12"
    >
      <svg class="animate-spin h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
    </div>

    <!-- Quality cards -->
    <div v-else-if="quality" class="space-y-6">
      <!-- Top row: Acceptance rate + Avg findings per review + Total reviews -->
      <div class="grid grid-cols-3 gap-4">
        <div
          data-testid="acceptance-rate-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Acceptance Rate</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="acceptance-rate-value">
            {{ acceptanceRateDisplay }}
          </p>
          <p v-if="quality.acceptance_rate === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
            Not yet tracked
          </p>
        </div>

        <div
          data-testid="avg-findings-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Avg Findings / Review</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="avg-findings-value">
            {{ avgFindingsDisplay }}
          </p>
          <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            {{ quality.total_findings }} total findings
          </p>
        </div>

        <div
          data-testid="total-reviews-card"
          class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
        >
          <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Total Reviews</p>
          <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="total-reviews-value">
            {{ quality.total_reviews }}
          </p>
        </div>
      </div>

      <!-- Severity distribution -->
      <div>
        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">Severity Distribution</h3>
        <div class="grid grid-cols-3 gap-4" data-testid="severity-distribution">
          <div
            data-testid="severity-critical"
            class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4 text-center"
          >
            <p class="text-xs font-medium text-red-600 dark:text-red-400 uppercase tracking-wide">Critical</p>
            <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="severity-critical-count">
              {{ quality.severity_distribution.critical }}
            </p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" data-testid="severity-critical-pct">
              {{ severityPercent(quality.severity_distribution.critical) }}%
            </p>
          </div>

          <div
            data-testid="severity-major"
            class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4 text-center"
          >
            <p class="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wide">Major</p>
            <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="severity-major-count">
              {{ quality.severity_distribution.major }}
            </p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" data-testid="severity-major-pct">
              {{ severityPercent(quality.severity_distribution.major) }}%
            </p>
          </div>

          <div
            data-testid="severity-minor"
            class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4 text-center"
          >
            <p class="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wide">Minor</p>
            <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="severity-minor-count">
              {{ quality.severity_distribution.minor }}
            </p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" data-testid="severity-minor-pct">
              {{ severityPercent(quality.severity_distribution.minor) }}%
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div
      v-else
      data-testid="quality-empty"
      class="flex items-center justify-center py-12"
    >
      <div class="text-center text-zinc-400 dark:text-zinc-500">
        <p class="text-sm">No quality data available.</p>
      </div>
    </div>
  </div>
</template>
