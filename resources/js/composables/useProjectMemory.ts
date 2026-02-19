import type { infer as ZodInfer } from 'zod';
import axios from 'axios';
import { ref } from 'vue';
import { MemoryEntrySchema, MemoryStatsSchema, PaginatedResponseSchema } from '@/types';

type MemoryEntry = ZodInfer<typeof MemoryEntrySchema>;
type MemoryStats = ZodInfer<typeof MemoryStatsSchema>;

interface MemoryFilters {
    type?: 'review_pattern' | 'conversation_fact' | 'cross_mr_pattern' | null;
    category?: string | null;
    cursor?: string | null;
}

export function useProjectMemory(projectId: number) {
    const entries = ref<MemoryEntry[]>([]);
    const stats = ref<MemoryStats | null>(null);
    const loading = ref(false);
    const statsLoading = ref(false);
    const error = ref<string | null>(null);
    const nextCursor = ref<string | null>(null);

    const listSchema = PaginatedResponseSchema(MemoryEntrySchema);

    async function fetchEntries(filters: MemoryFilters = {}) {
        loading.value = true;
        error.value = null;
        try {
            const response = await axios.get(`/api/v1/projects/${projectId}/memory`, {
                params: {
                    type: filters.type ?? undefined,
                    category: filters.category ?? undefined,
                    cursor: filters.cursor ?? undefined,
                },
            });

            const parsed = listSchema.parse(response.data);
            entries.value = parsed.data;
            nextCursor.value = parsed.meta.next_cursor;
        } catch {
            error.value = 'Failed to load project memory entries.';
            entries.value = [];
            nextCursor.value = null;
        } finally {
            loading.value = false;
        }
    }

    async function fetchStats() {
        statsLoading.value = true;
        error.value = null;
        try {
            const response = await axios.get(`/api/v1/projects/${projectId}/memory/stats`);
            stats.value = MemoryStatsSchema.parse(response.data.data);
        } catch {
            error.value = 'Failed to load project memory stats.';
            stats.value = null;
        } finally {
            statsLoading.value = false;
        }
    }

    async function archiveEntry(entryId: number): Promise<boolean> {
        try {
            await axios.delete(`/api/v1/projects/${projectId}/memory/${entryId}`);
            entries.value = entries.value.filter(entry => entry.id !== entryId);
            return true;
        } catch {
            error.value = 'Failed to archive memory entry.';
            return false;
        }
    }

    return {
        entries,
        stats,
        loading,
        statsLoading,
        error,
        nextCursor,
        fetchEntries,
        fetchStats,
        archiveEntry,
    };
}
