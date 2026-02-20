import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import MemoryStatsWidget from '../MemoryStatsWidget.vue';

const fetchStats = vi.fn();

const state = {
    entries: ref<Array<Record<string, unknown>>>([]),
    stats: ref<{
        total_entries: number;
        by_type: Record<string, number>;
        by_category: Record<string, number>;
        average_confidence: number;
        last_created_at: string | null;
    } | null>(null),
    loading: ref(false),
    statsLoading: ref(false),
    error: ref<string | null>(null),
    nextCursor: ref<string | null>(null),
};

vi.mock('@/composables/useProjectMemory', () => ({
    useProjectMemory: () => ({
        ...state,
        fetchEntries: vi.fn(),
        fetchStats,
        archiveEntry: vi.fn(),
    }),
}));

describe('memoryStatsWidget', () => {
    beforeEach(() => {
        fetchStats.mockResolvedValue(undefined);
        state.stats.value = null;
        state.statsLoading.value = false;
    });

    it('fetches stats on mount', () => {
        mount(MemoryStatsWidget, { props: { projectId: 1 } });
        expect(fetchStats).toHaveBeenCalled();
    });

    it('shows empty state when there are no stats', () => {
        const wrapper = mount(MemoryStatsWidget, { props: { projectId: 1 } });
        expect(wrapper.find('[data-testid="memory-stats-empty"]').exists()).toBe(true);
    });

    it('renders stats data', async () => {
        state.stats.value = {
            total_entries: 6,
            by_type: {
                review_pattern: 3,
                conversation_fact: 2,
                cross_mr_pattern: 1,
            },
            by_category: {},
            average_confidence: 74,
            last_created_at: '2026-02-19T01:00:00Z',
        };

        const wrapper = mount(MemoryStatsWidget, { props: { projectId: 1 } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('6 patterns');
        expect(wrapper.text()).toContain('Review: 3');
        expect(wrapper.find('[data-testid="memory-confidence-bar"]').attributes('style')).toContain('74%');
    });
});
