<script setup lang="ts">
import axios from 'axios';
import { computed, ref } from 'vue';
import MarkdownContent from './MarkdownContent.vue';
import BaseBadge from './ui/BaseBadge.vue';

interface KeyFinding {
    title: string;
    description: string;
    severity: string;
}

interface TaskResult {
    task_id: number;
    status: string;
    type: string;
    title: string;
    mr_iid: number | null;
    issue_iid: number | null;
    branch: string | null;
    target_branch: string;
    files_changed: string[] | null;
    result_summary: string | null;
    error_reason: string | null;
    screenshot: string | null;
    key_findings: KeyFinding[] | null;
    project_id: number;
    gitlab_url: string;
}

interface Props {
    result: TaskResult;
}

const props = defineProps<Props>();

interface TypeDisplayInfo {
    label: string;
    emoji: string;
}

const TYPE_DISPLAY: Record<string, TypeDisplayInfo> = {
    code_review: { label: 'Code Review', emoji: '🔍' },
    feature_dev: { label: 'Feature Dev', emoji: '🚀' },
    ui_adjustment: { label: 'UI Adjustment', emoji: '🎨' },
    prd_creation: { label: 'Issue Created', emoji: '📋' },
    deep_analysis: { label: 'Deep Analysis', emoji: '🔬' },
    security_audit: { label: 'Security Audit', emoji: '🔒' },
    issue_discussion: { label: 'Issue Discussion', emoji: '💬' },
};

const typeDisplay = computed(() =>
    TYPE_DISPLAY[props.result.type] || { label: props.result.type, emoji: '⚙️' },
);

const isSuccess = computed(() => props.result.status === 'completed');
const isFailed = computed(() => props.result.status === 'failed');

const artifactLabel = computed(() => {
    if (props.result.mr_iid)
        return `MR !${props.result.mr_iid}`;
    if (props.result.issue_iid)
        return `Issue #${props.result.issue_iid}`;
    return null;
});

const artifactUrl = computed(() => {
    const base = props.result.gitlab_url || '';
    if (props.result.mr_iid)
        return `${base}/-/merge_requests/${props.result.mr_iid}`;
    if (props.result.issue_iid)
        return `${base}/-/issues/${props.result.issue_iid}`;
    return null;
});

const filesCount = computed(() => props.result.files_changed?.length ?? 0);

const hasBranch = computed(() =>
    props.result.branch && ['feature_dev', 'ui_adjustment'].includes(props.result.type),
);

const hasScreenshot = computed(() =>
    props.result.type === 'ui_adjustment' && props.result.screenshot,
);

const hasFindings = computed(() =>
    props.result.key_findings !== null && props.result.key_findings.length > 0,
);

const findingsExpanded = ref(false);

// Full analysis lazy-fetch — deep_analysis reports can be 10–50KB+, so they
// are excluded from the broadcast payload. We fetch on demand from the API.
const fullAnalysis = ref<string | null>(null);
const analysisLoading = ref(false);
const analysisExpanded = ref(false);

const hasAnalysisExpand = computed(() =>
    props.result.type === 'deep_analysis' && props.result.result_summary !== null,
);

async function toggleFullAnalysis(): Promise<void> {
    if (analysisExpanded.value) {
        analysisExpanded.value = false;
        return;
    }
    // Already fetched — just expand.
    if (fullAnalysis.value !== null) {
        analysisExpanded.value = true;
        return;
    }
    analysisLoading.value = true;
    try {
        const response = await axios.get(`/api/v1/tasks/${props.result.task_id}/view`);
        fullAnalysis.value = (response.data?.data?.result?.analysis as string) ?? null;
        if (fullAnalysis.value !== null)
            analysisExpanded.value = true;
    } catch {
        // silent — button stays collapsed; user can retry
    } finally {
        analysisLoading.value = false;
    }
}

function severityVariant(severity: string): 'danger' | 'warning' | 'info' {
    if (severity === 'critical')
        return 'danger';
    if (severity === 'major')
        return 'warning';
    return 'info';
}
</script>

<template>
    <div
        data-testid="result-card"
        class="w-full max-w-lg rounded-xl border overflow-hidden"
        :class="isSuccess
            ? 'border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/30'
            : 'border-red-200 dark:border-red-800 bg-red-50/50 dark:bg-red-950/30'"
    >
        <!-- Header -->
        <div
            class="px-4 py-3 border-b flex items-center gap-2"
            :class="isSuccess
                ? 'border-emerald-100 dark:border-emerald-900'
                : 'border-red-100 dark:border-red-900'"
        >
            <span class="text-lg">{{ isSuccess ? '✅' : '❌' }}</span>
            <BaseBadge
                data-testid="result-type-badge"
                :variant="isSuccess ? 'success' : 'danger'"
            >
                <span>{{ typeDisplay.emoji }}</span>
                <span>{{ isSuccess ? `${typeDisplay.label} completed` : `${typeDisplay.label} failed` }}</span>
            </BaseBadge>
        </div>

        <!-- Body -->
        <div class="px-4 py-3 space-y-2">
            <!-- Title + artifact link -->
            <div class="flex items-start justify-between gap-2">
                <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                    {{ result.title }}
                </h4>
            </div>

            <!-- Artifact link (MR or Issue) -->
            <a
                v-if="artifactUrl"
                data-testid="artifact-link"
                :href="artifactUrl"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline"
            >
                {{ artifactLabel }}
                <span class="text-[10px]">↗</span>
            </a>

            <!-- Branch info -->
            <div v-if="hasBranch" class="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                <span class="font-medium text-zinc-700 dark:text-zinc-300">Branch:</span>
                <code class="px-1 py-0.5 rounded bg-white/60 dark:bg-zinc-800 font-mono text-[11px]">{{ result.branch }}</code>
                <span>→</span>
                <code class="px-1 py-0.5 rounded bg-white/60 dark:bg-zinc-800 font-mono text-[11px]">{{ result.target_branch || 'main' }}</code>
            </div>

            <!-- Files changed -->
            <div
                v-if="filesCount > 0"
                class="text-xs text-zinc-500 dark:text-zinc-400"
            >
                Files changed: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ filesCount }}</span>
            </div>

            <!-- Summary -->
            <div
                v-if="result.result_summary"
                data-testid="result-summary"
                class="text-xs text-zinc-600 dark:text-zinc-400 result-card-markdown"
            >
                <MarkdownContent :content="result.result_summary" />
            </div>

            <!-- Full analysis expand (deep_analysis only) -->
            <div v-if="hasAnalysisExpand" class="space-y-1.5">
                <button
                    type="button"
                    class="flex items-center gap-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors disabled:opacity-50"
                    :disabled="analysisLoading"
                    @click="toggleFullAnalysis"
                >
                    <span v-if="analysisLoading" class="animate-pulse">Loading…</span>
                    <template v-else>
                        <span>{{ analysisExpanded ? '▾' : '▸' }}</span>
                        <span>{{ analysisExpanded ? 'Hide full analysis' : 'View full analysis' }}</span>
                    </template>
                </button>
                <div
                    v-if="analysisExpanded && fullAnalysis"
                    class="text-xs text-zinc-600 dark:text-zinc-400 border-t border-zinc-200 dark:border-zinc-700 pt-2 result-card-markdown"
                >
                    <MarkdownContent :content="fullAnalysis" />
                </div>
            </div>

            <!-- Key findings (deep_analysis / security_audit) -->
            <div v-if="hasFindings" class="space-y-1.5">
                <button
                    type="button"
                    class="flex items-center gap-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors"
                    @click="findingsExpanded = !findingsExpanded"
                >
                    <span>{{ findingsExpanded ? '▾' : '▸' }}</span>
                    <span>{{ result.key_findings!.length }} finding{{ result.key_findings!.length !== 1 ? 's' : '' }}</span>
                </button>
                <ul v-if="findingsExpanded" class="space-y-2">
                    <li
                        v-for="(finding, i) in result.key_findings"
                        :key="i"
                        class="text-xs space-y-0.5"
                    >
                        <div class="flex items-center gap-1.5">
                            <BaseBadge
                                :variant="severityVariant(finding.severity)"
                                class="uppercase tracking-wide !text-[10px] !px-1.5"
                            >
                                {{ finding.severity }}
                            </BaseBadge>
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ finding.title }}</span>
                        </div>
                        <div class="text-zinc-500 dark:text-zinc-400 pl-0.5 result-card-markdown">
                            <MarkdownContent :content="finding.description" />
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Error reason (failed tasks) -->
            <p
                v-if="isFailed && result.error_reason"
                data-testid="error-reason"
                class="text-xs text-red-600 dark:text-red-400 leading-relaxed"
            >
                {{ result.error_reason }}
            </p>

            <!-- Screenshot (UI adjustment) -->
            <div
                v-if="hasScreenshot"
                data-testid="result-screenshot"
                class="mt-2 rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700"
            >
                <img
                    :src="`data:image/png;base64,${result.screenshot}`"
                    alt="UI adjustment screenshot"
                    class="w-full h-auto"
                >
            </div>
        </div>

        <!-- Footer: View in GitLab -->
        <div
            v-if="artifactUrl"
            class="px-4 py-2.5 border-t text-right"
            :class="isSuccess
                ? 'border-emerald-100 dark:border-emerald-900'
                : 'border-red-100 dark:border-red-900'"
        >
            <a
                :href="artifactUrl"
                target="_blank"
                rel="noopener"
                class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline"
            >
                View in GitLab ↗
            </a>
        </div>
    </div>
</template>
