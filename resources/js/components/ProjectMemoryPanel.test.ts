import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import ProjectMemoryPanel from './ProjectMemoryPanel.vue';

const fetchEntries = vi.fn();
const fetchStats = vi.fn();
const archiveEntry = vi.fn();

interface MemoryState {
    entries: Array<{
        id: number;
        type: 'review_pattern' | 'conversation_fact' | 'cross_mr_pattern';
        category: string | null;
        content: Record<string, unknown>;
        confidence: number;
        applied_count: number;
        source_task_id: number | null;
        created_at: string | null;
    }>;
    stats: {
        total_entries: number;
        by_type: Record<string, number>;
        by_category: Record<string, number>;
        average_confidence: number;
        last_created_at: string | null;
    } | null;
}

const state = {
    entries: ref<MemoryState['entries']>([]),
    stats: ref<MemoryState['stats']>(null),
    loading: ref(false),
    statsLoading: ref(false),
    error: ref<string | null>(null),
    nextCursor: ref<string | null>(null),
};

vi.mock('@/composables/useProjectMemory', () => ({
    useProjectMemory: () => ({
        ...state,
        fetchEntries,
        fetchStats,
        archiveEntry,
    }),
}));

describe('projectMemoryPanel', () => {
    beforeEach(() => {
        fetchEntries.mockResolvedValue(undefined);
        fetchStats.mockResolvedValue(undefined);
        archiveEntry.mockResolvedValue(true);

        state.entries.value = [];
        state.stats.value = {
            total_entries: 0,
            by_type: {},
            by_category: {},
            average_confidence: 0,
            last_created_at: null,
        };
        state.loading.value = false;
        state.error.value = null;
    });

    it('fetches entries and stats on mount', () => {
        mount(ProjectMemoryPanel, { props: { projectId: 1 } });

        expect(fetchEntries).toHaveBeenCalled();
        expect(fetchStats).toHaveBeenCalled();
    });

    it('shows loading state', () => {
        state.loading.value = true;
        const wrapper = mount(ProjectMemoryPanel, { props: { projectId: 1 } });
        expect(wrapper.find('[data-testid="memory-loading"]').exists()).toBe(true);
    });

    it('shows empty state when no entries exist', () => {
        const wrapper = mount(ProjectMemoryPanel, { props: { projectId: 1 } });
        expect(wrapper.find('[data-testid="memory-empty"]').exists()).toBe(true);
    });

    it('renders memory entries and allows archive action', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        state.entries.value = [
            {
                id: 10,
                type: 'review_pattern',
                category: 'false_positive',
                content: { pattern: 'Type-safety findings are frequently dismissed.' },
                confidence: 72,
                applied_count: 0,
                source_task_id: 8,
                created_at: '2026-02-18T10:00:00Z',
            },
        ];
        state.stats.value.total_entries = 1;

        const wrapper = mount(ProjectMemoryPanel, { props: { projectId: 1 } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="memory-list"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Type-safety findings are frequently dismissed');

        await wrapper.find('[data-testid="archive-memory-10"]').trigger('click');

        expect(archiveEntry).toHaveBeenCalledWith(10);
    });

    it('filters by type chip', async () => {
        const wrapper = mount(ProjectMemoryPanel, { props: { projectId: 1 } });
        fetchEntries.mockClear();

        await wrapper.find('[data-testid="chip-conversation_fact"]').trigger('click');

        expect(fetchEntries).toHaveBeenCalledWith({ type: 'conversation_fact' });
    });
});
