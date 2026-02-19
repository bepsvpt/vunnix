<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import BaseBadge from '@/components/ui/BaseBadge.vue';
import BaseButton from '@/components/ui/BaseButton.vue';
import BaseCard from '@/components/ui/BaseCard.vue';
import BaseEmptyState from '@/components/ui/BaseEmptyState.vue';
import BaseFilterChips from '@/components/ui/BaseFilterChips.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import { useProjectMemory } from '@/composables/useProjectMemory';

interface Props {
    projectId: number;
}

const props = defineProps<Props>();
const memory = useProjectMemory(props.projectId);
const selectedType = ref<'review_pattern' | 'conversation_fact' | 'cross_mr_pattern' | null>(null);

const typeChips = [
    { label: 'All', value: null },
    { label: 'Review', value: 'review_pattern' },
    { label: 'Conversation', value: 'conversation_fact' },
    { label: 'Cross-MR', value: 'cross_mr_pattern' },
];

const sortedEntries = computed(() => [...memory.entries.value]
    .sort((a, b) => b.confidence - a.confidence));

const hasEntries = computed(() => sortedEntries.value.length > 0);
const loading = computed(() => memory.loading.value);
const stats = computed(() => memory.stats.value);

function onTypeChange(value: string | null) {
    selectedType.value = value as typeof selectedType.value;
}

function entryBody(entry: { content: Record<string, unknown> }): string {
    const pattern = entry.content.pattern;
    if (typeof pattern === 'string' && pattern !== '')
        return pattern;

    const fact = entry.content.fact;
    if (typeof fact === 'string' && fact !== '')
        return fact;

    return JSON.stringify(entry.content);
}

function memoryTypeLabel(type: string): string {
    if (type === 'review_pattern')
        return 'Review';
    if (type === 'conversation_fact')
        return 'Conversation';
    if (type === 'cross_mr_pattern')
        return 'Cross-MR';
    return type;
}

async function archive(id: number) {
    if (!window.confirm('Archive this memory entry?'))
        return;

    await memory.archiveEntry(id);
    await memory.fetchStats();
}

onMounted(async () => {
    await Promise.all([
        memory.fetchEntries(),
        memory.fetchStats(),
    ]);
});

watch(selectedType, async (type) => {
    await memory.fetchEntries({ type });
});
</script>

<template>
    <div class="space-y-4" data-testid="project-memory-panel">
        <div class="grid grid-cols-3 gap-3">
            <BaseCard class="text-center">
                <p class="text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                    Total Entries
                </p>
                <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="memory-total-entries">
                    {{ stats?.total_entries ?? 0 }}
                </p>
            </BaseCard>
            <BaseCard class="text-center">
                <p class="text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                    Avg Confidence
                </p>
                <p class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100" data-testid="memory-average-confidence">
                    {{ Math.round(stats?.average_confidence ?? 0) }}%
                </p>
            </BaseCard>
            <BaseCard class="text-center">
                <p class="text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                    Last Extraction
                </p>
                <p class="mt-1 text-sm font-medium text-zinc-700 dark:text-zinc-200" data-testid="memory-last-extraction">
                    {{ stats?.last_created_at ? new Date(stats.last_created_at).toLocaleString() : '—' }}
                </p>
            </BaseCard>
        </div>

        <BaseCard>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                    Project Memory
                </h2>
                <BaseFilterChips
                    :chips="typeChips"
                    :model-value="selectedType"
                    @update:model-value="onTypeChange"
                />
            </div>
        </BaseCard>

        <div
            v-if="loading"
            class="flex justify-center py-10"
            data-testid="memory-loading"
        >
            <BaseSpinner size="md" />
        </div>

        <BaseEmptyState v-else-if="!hasEntries" data-testid="memory-empty">
            <template #title>
                No memory entries yet
            </template>
            <template #description>
                Learned project patterns will appear here after reviews and conversations.
            </template>
        </BaseEmptyState>

        <div v-else class="space-y-3" data-testid="memory-list">
            <BaseCard
                v-for="entry in sortedEntries"
                :key="entry.id"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-2 min-w-0">
                        <div class="flex items-center gap-2">
                            <BaseBadge variant="info">
                                {{ memoryTypeLabel(entry.type) }}
                            </BaseBadge>
                            <BaseBadge variant="neutral">
                                {{ entry.confidence }}%
                            </BaseBadge>
                        </div>
                        <p class="text-sm text-zinc-700 dark:text-zinc-300 break-words" :data-testid="`memory-entry-body-${entry.id}`">
                            {{ entryBody(entry) }}
                        </p>
                    </div>
                    <BaseButton
                        variant="danger"
                        size="sm"
                        :data-testid="`archive-memory-${entry.id}`"
                        @click="archive(entry.id)"
                    >
                        Archive
                    </BaseButton>
                </div>
            </BaseCard>
        </div>
    </div>
</template>
