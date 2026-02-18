<script setup lang="ts">
import { computed, ref } from 'vue';

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

function severityClass(severity: string): string {
    if (severity === 'critical')
        return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300';
    if (severity === 'major')
        return 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300';
    return 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300';
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
            <span
                data-testid="result-type-badge"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                :class="isSuccess
                    ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300'
                    : 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'"
            >
                <span>{{ typeDisplay.emoji }}</span>
                <span>{{ isSuccess ? `${typeDisplay.label} completed` : `${typeDisplay.label} failed` }}</span>
            </span>
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
            <p
                v-if="result.result_summary"
                data-testid="result-summary"
                class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed"
            >
                {{ result.result_summary }}
            </p>

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
                            <span
                                class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide"
                                :class="severityClass(finding.severity)"
                            >{{ finding.severity }}</span>
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ finding.title }}</span>
                        </div>
                        <p class="text-zinc-500 dark:text-zinc-400 leading-relaxed pl-0.5">
                            {{ finding.description }}
                        </p>
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
