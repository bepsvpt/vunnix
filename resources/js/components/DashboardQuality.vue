<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { useAdminStore } from '@/stores/admin';
import { useDashboardStore } from '@/stores/dashboard';
import BaseCard from './ui/BaseCard.vue';
import BaseEmptyState from './ui/BaseEmptyState.vue';
import BaseSpinner from './ui/BaseSpinner.vue';

const dashboard = useDashboardStore();
const admin = useAdminStore();

onMounted(() => {
    dashboard.fetchQuality();
    dashboard.fetchOverrelianceAlerts();
    dashboard.fetchPromptVersions();
});

// Re-fetch quality when prompt version filter changes
watch(() => dashboard.promptVersionFilter, () => {
    dashboard.fetchQuality();
});

const quality = computed(() => dashboard.quality);
const overrelianceAlerts = computed(() => dashboard.overrelianceAlerts);

const overrelianceRuleLabels: Record<string, string> = {
    high_acceptance_rate: 'High Acceptance Rate',
    critical_acceptance_rate: 'Critical Finding Acceptance',
    bulk_resolution: 'Bulk Resolution Pattern',
    zero_reactions: 'Zero Negative Reactions',
};

const overrelianceSeverityColors: Record<string, string> = {
    warning: 'bg-amber-100 dark:bg-amber-900/30 border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-200',
    info: 'bg-blue-100 dark:bg-blue-900/30 border-blue-300 dark:border-blue-700 text-blue-800 dark:text-blue-200',
};

async function handleOverrelianceAcknowledge(alertId: number) {
    await admin.acknowledgeOverrelianceAlert(alertId);
    dashboard.fetchOverrelianceAlerts();
}

const acceptanceRateDisplay = computed(() => {
    if (!quality.value || quality.value.acceptance_rate === null)
        return '—';
    return `${quality.value.acceptance_rate}%`;
});

const avgFindingsDisplay = computed(() => {
    if (!quality.value || quality.value.avg_findings_per_review === null)
        return '—';
    return `${quality.value.avg_findings_per_review}`;
});

const severityTotal = computed(() => {
    if (!quality.value?.severity_distribution)
        return 0;
    const d = quality.value.severity_distribution as { critical: number; major: number; minor: number };
    return d.critical + d.major + d.minor;
});

function severityPercent(count: number) {
    if (severityTotal.value === 0)
        return 0;
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
            <BaseSpinner size="md" />
        </div>

        <!-- Quality cards -->
        <div v-else-if="quality" class="space-y-6">
            <!-- Prompt version filter (T102) -->
            <div v-if="dashboard.promptVersions.length > 0" class="flex items-center gap-3">
                <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                    Prompt Version
                </label>
                <select
                    data-testid="prompt-version-filter"
                    class="text-sm rounded-[var(--radius-card)] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 px-3 py-1.5 focus:ring-2 focus:ring-zinc-400 focus:border-zinc-400"
                    :value="dashboard.promptVersionFilter"
                    @change="dashboard.promptVersionFilter = $event.target.value || null"
                >
                    <option value="">
                        All Versions
                    </option>
                    <option
                        v-for="pv in dashboard.promptVersions"
                        :key="pv.skill"
                        :value="pv.skill"
                    >
                        {{ pv.skill }}
                    </option>
                </select>
            </div>

            <!-- Active over-reliance alerts (T95) -->
            <div v-if="overrelianceAlerts.length > 0" data-testid="overreliance-alerts">
                <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                    Over-Reliance Alerts
                </h3>
                <div class="space-y-2">
                    <div
                        v-for="alert in overrelianceAlerts"
                        :key="alert.id"
                        :data-testid="`overreliance-alert-${alert.id}`"
                        class="rounded-[var(--radius-card)] border p-3 flex items-start justify-between" :class="[overrelianceSeverityColors[alert.severity] || overrelianceSeverityColors.warning]"
                    >
                        <div>
                            <span class="text-xs font-semibold uppercase">{{ overrelianceRuleLabels[alert.rule] || alert.rule }}</span>
                            <p class="text-sm mt-0.5">
                                {{ alert.message }}
                            </p>
                            <p class="text-xs opacity-70 mt-1">
                                {{ new Date(alert.created_at).toLocaleString() }}
                            </p>
                        </div>
                        <button
                            data-testid="overreliance-acknowledge-btn"
                            class="ml-3 flex-shrink-0 text-xs font-medium underline opacity-70 hover:opacity-100"
                            @click="handleOverrelianceAcknowledge(alert.id)"
                        >
                            Dismiss
                        </button>
                    </div>
                </div>
            </div>

            <!-- Top row: Acceptance rate + Avg findings per review + Total reviews -->
            <div class="grid grid-cols-3 gap-4">
                <BaseCard
                    data-testid="acceptance-rate-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Acceptance Rate
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="acceptance-rate-value">
                        {{ acceptanceRateDisplay }}
                    </p>
                    <p v-if="quality.acceptance_rate === null" class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                        Not yet tracked
                    </p>
                </BaseCard>

                <BaseCard
                    data-testid="avg-findings-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Avg Findings / Review
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="avg-findings-value">
                        {{ avgFindingsDisplay }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ quality.total_findings }} total findings
                    </p>
                </BaseCard>

                <BaseCard
                    data-testid="total-reviews-card"
                >
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                        Total Reviews
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="total-reviews-value">
                        {{ quality.total_reviews }}
                    </p>
                </BaseCard>
            </div>

            <!-- Severity distribution -->
            <div>
                <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                    Severity Distribution
                </h3>
                <div class="grid grid-cols-3 gap-4" data-testid="severity-distribution">
                    <BaseCard
                        data-testid="severity-critical"
                        class="text-center"
                    >
                        <p class="text-xs font-medium text-red-600 dark:text-red-400 uppercase tracking-wide">
                            Critical
                        </p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="severity-critical-count">
                            {{ quality.severity_distribution.critical }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" data-testid="severity-critical-pct">
                            {{ severityPercent(quality.severity_distribution.critical) }}%
                        </p>
                    </BaseCard>

                    <BaseCard
                        data-testid="severity-major"
                        class="text-center"
                    >
                        <p class="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wide">
                            Major
                        </p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="severity-major-count">
                            {{ quality.severity_distribution.major }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" data-testid="severity-major-pct">
                            {{ severityPercent(quality.severity_distribution.major) }}%
                        </p>
                    </BaseCard>

                    <BaseCard
                        data-testid="severity-minor"
                        class="text-center"
                    >
                        <p class="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wide">
                            Minor
                        </p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="severity-minor-count">
                            {{ quality.severity_distribution.minor }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" data-testid="severity-minor-pct">
                            {{ severityPercent(quality.severity_distribution.minor) }}%
                        </p>
                    </BaseCard>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <BaseEmptyState v-else data-testid="quality-empty">
            <template #description>
                No quality data available.
            </template>
        </BaseEmptyState>
    </div>
</template>
