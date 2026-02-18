<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useAdminStore } from '@/stores/admin';
import BaseButton from './ui/BaseButton.vue';
import BaseCard from './ui/BaseCard.vue';
import BaseEmptyState from './ui/BaseEmptyState.vue';

interface DeadLetterEntry {
    id: number;
    failure_reason: string;
    dead_lettered_at: string;
    attempt_count?: number;
    error_details?: string;
    task_record?: Record<string, unknown>;
    [key: string]: unknown;
}

const admin = useAdminStore();
const actionInProgress = ref<number | null>(null);
const actionError = ref<string | null>(null);
const selectedEntry = ref<DeadLetterEntry | null>(null);

// Filters
const filterReason = ref('');
const filterDateFrom = ref('');
const filterDateTo = ref('');

const reasonOptions = [
    { value: '', label: 'All reasons' },
    { value: 'max_retries_exceeded', label: 'Max retries exceeded' },
    { value: 'expired', label: 'Expired' },
    { value: 'invalid_request', label: 'Invalid request' },
    { value: 'context_exceeded', label: 'Context exceeded' },
    { value: 'scheduling_timeout', label: 'Scheduling timeout' },
];

const reasonBadgeClass: Record<string, string> = {
    max_retries_exceeded: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    expired: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
    invalid_request: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
    context_exceeded: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    scheduling_timeout: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
};

function buildFilters(): Record<string, string> {
    const filters: Record<string, string> = {};
    if (filterReason.value)
        filters.reason = filterReason.value;
    if (filterDateFrom.value)
        filters.date_from = filterDateFrom.value;
    if (filterDateTo.value)
        filters.date_to = filterDateTo.value;
    return filters;
}

function applyFilters() {
    admin.fetchDeadLetterEntries(buildFilters());
}

async function selectEntry(entry: DeadLetterEntry) {
    selectedEntry.value = entry;
    await admin.fetchDeadLetterDetail(entry.id);
}

function backToList() {
    selectedEntry.value = null;
    admin.deadLetterDetail = null;
}

async function handleRetry(entryId: number) {
    if (!confirm('Retry this failed task? A new task will be created and queued.'))
        return;

    actionInProgress.value = entryId;
    actionError.value = null;
    const result = await admin.retryDeadLetterEntry(entryId);
    if (!result.success) {
        actionError.value = result.error ?? null;
    } else {
        selectedEntry.value = null;
    }
    actionInProgress.value = null;
}

async function handleDismiss(entryId: number) {
    if (!confirm('Dismiss this entry? It will be marked as acknowledged and hidden from the active list.'))
        return;

    actionInProgress.value = entryId;
    actionError.value = null;
    const result = await admin.dismissDeadLetterEntry(entryId);
    if (!result.success) {
        actionError.value = result.error ?? null;
    } else {
        selectedEntry.value = null;
    }
    actionInProgress.value = null;
}

function formatDate(dateStr: string | null | undefined): string {
    if (!dateStr)
        return '\u2014';
    return new Date(dateStr).toLocaleString();
}

function formatReason(reason: string | null | undefined): string {
    return (reason || '').replace(/_/g, ' ');
}

function truncate(str: string | null | undefined, len = 120): string {
    if (!str)
        return '\u2014';
    return str.length > len ? `${str.slice(0, len)}\u2026` : str;
}

onMounted(() => {
    admin.fetchDeadLetterEntries();
});
</script>

<template>
    <div>
        <!-- Action error banner -->
        <div
            v-if="actionError"
            class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400"
            data-testid="dlq-action-error"
        >
            {{ actionError }}
        </div>

        <!-- Detail view -->
        <div v-if="selectedEntry" :data-testid="`dlq-detail-${selectedEntry.id}`">
            <button
                class="mb-4 text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200"
                @click="backToList"
            >
                &larr; Back to list
            </button>

            <div v-if="admin.deadLetterDetailLoading" class="py-8 text-center text-zinc-500" data-testid="dlq-detail-loading">
                Loading entry details...
            </div>

            <div v-else-if="admin.deadLetterDetail" class="space-y-4">
                <BaseCard>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-medium">
                            Entry #{{ admin.deadLetterDetail.id }}
                        </h3>
                        <span
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                            :class="reasonBadgeClass[admin.deadLetterDetail.failure_reason] || 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
                        >
                            {{ formatReason(admin.deadLetterDetail.failure_reason) }}
                        </span>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <dt class="text-zinc-500 dark:text-zinc-400">
                            Dead lettered at
                        </dt>
                        <dd>{{ formatDate(admin.deadLetterDetail.dead_lettered_at) }}</dd>
                        <dt class="text-zinc-500 dark:text-zinc-400">
                            Task type
                        </dt>
                        <dd>{{ admin.deadLetterDetail.task_record?.type || '—' }}</dd>
                        <dt class="text-zinc-500 dark:text-zinc-400">
                            Project ID
                        </dt>
                        <dd>{{ admin.deadLetterDetail.task_record?.project_id || '—' }}</dd>
                        <dt class="text-zinc-500 dark:text-zinc-400">
                            Attempts
                        </dt>
                        <dd>{{ admin.deadLetterDetail.attempt_count ?? '—' }}</dd>
                    </dl>
                </BaseCard>

                <!-- Error details -->
                <BaseCard>
                    <h4 class="text-sm font-medium mb-2">
                        Error Details
                    </h4>
                    <pre class="text-xs text-zinc-600 dark:text-zinc-400 whitespace-pre-wrap break-words bg-zinc-50 dark:bg-zinc-800/50 p-3 rounded">{{ admin.deadLetterDetail.error_details || 'No error details recorded.' }}</pre>
                </BaseCard>

                <!-- Attempt history -->
                <BaseCard v-if="admin.deadLetterDetail.attempt_history?.length">
                    <h4 class="text-sm font-medium mb-2">
                        Attempt History
                    </h4>
                    <div class="space-y-2">
                        <div
                            v-for="(attempt, idx) in admin.deadLetterDetail.attempt_history"
                            :key="idx"
                            class="text-xs border-l-2 border-zinc-300 dark:border-zinc-600 pl-3 py-1"
                        >
                            <span class="text-zinc-500 dark:text-zinc-400">Attempt {{ idx + 1 }}</span>
                            <span class="mx-1">·</span>
                            <span>{{ formatDate(attempt.attempted_at) }}</span>
                            <p v-if="attempt.error" class="mt-0.5 text-zinc-600 dark:text-zinc-400">
                                {{ attempt.error }}
                            </p>
                        </div>
                    </div>
                </BaseCard>

                <!-- Action buttons -->
                <div class="flex gap-3">
                    <BaseButton
                        variant="primary"
                        :disabled="actionInProgress === admin.deadLetterDetail.id"
                        :data-testid="`dlq-retry-btn-${admin.deadLetterDetail.id}`"
                        @click="handleRetry(admin.deadLetterDetail.id)"
                    >
                        {{ actionInProgress === admin.deadLetterDetail.id ? 'Retrying...' : 'Retry' }}
                    </BaseButton>
                    <BaseButton
                        variant="secondary"
                        :disabled="actionInProgress === admin.deadLetterDetail.id"
                        :data-testid="`dlq-dismiss-btn-${admin.deadLetterDetail.id}`"
                        @click="handleDismiss(admin.deadLetterDetail.id)"
                    >
                        {{ actionInProgress === admin.deadLetterDetail.id ? 'Dismissing...' : 'Dismiss' }}
                    </BaseButton>
                </div>
            </div>
        </div>

        <!-- List view -->
        <div v-else>
            <!-- Filter bar -->
            <div class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-zinc-500 dark:text-zinc-400 mb-1">Reason</label>
                    <select
                        v-model="filterReason"
                        data-testid="dlq-filter-reason"
                        class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm"
                        @change="applyFilters"
                    >
                        <option v-for="opt in reasonOptions" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-zinc-500 dark:text-zinc-400 mb-1">From</label>
                    <input
                        v-model="filterDateFrom"
                        type="date"
                        data-testid="dlq-filter-date-from"
                        class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm"
                        @change="applyFilters"
                    >
                </div>
                <div>
                    <label class="block text-xs text-zinc-500 dark:text-zinc-400 mb-1">To</label>
                    <input
                        v-model="filterDateTo"
                        type="date"
                        data-testid="dlq-filter-date-to"
                        class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm"
                        @change="applyFilters"
                    >
                </div>
            </div>

            <!-- Loading state -->
            <div v-if="admin.deadLetterLoading" class="py-8 text-center text-zinc-500" data-testid="dlq-loading">
                Loading dead letter queue...
            </div>

            <!-- Empty state -->
            <BaseEmptyState v-else-if="admin.deadLetterEntries.length === 0" data-testid="dlq-empty">
                <template #title>
                    No failed tasks in the dead letter queue
                </template>
            </BaseEmptyState>

            <!-- Entry list -->
            <div v-else class="space-y-3">
                <div
                    v-for="entry in admin.deadLetterEntries"
                    :key="entry.id"
                    class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                    :data-testid="`dlq-entry-${entry.id}`"
                    @click="selectEntry(entry)"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="reasonBadgeClass[entry.failure_reason] || 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
                                >
                                    {{ formatReason(entry.failure_reason) }}
                                </span>
                                <span v-if="entry.task_record?.type" class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ entry.task_record.type }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ formatDate(entry.dead_lettered_at) }}
                                <span v-if="entry.attempt_count"> &middot; {{ entry.attempt_count }} attempts</span>
                            </p>
                            <p v-if="entry.error_details" class="mt-1 text-xs text-zinc-600 dark:text-zinc-400 truncate">
                                {{ truncate(entry.error_details) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
