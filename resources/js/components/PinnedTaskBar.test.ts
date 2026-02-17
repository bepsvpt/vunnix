import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PinnedTaskBar from './PinnedTaskBar.vue';

// Freeze time for elapsed timer tests
const NOW = new Date('2026-02-15T12:05:00Z');

describe('pinnedTaskBar', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(NOW);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function mountBar(tasks: Array<Record<string, unknown>> = []) {
        return mount(PinnedTaskBar, {
            props: { tasks },
        });
    }

    it('renders nothing when no tasks', () => {
        const wrapper = mountBar([]);
        expect(wrapper.find('[data-testid="pinned-task-bar"]').exists()).toBe(false);
    });

    it('renders a bar for each active task', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Add Stripe', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
            { task_id: 2, status: 'queued', type: 'code_review', title: 'Review PR', pipeline_id: null, pipeline_status: null, started_at: null, project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.findAll('[data-testid="pinned-task-item"]')).toHaveLength(2);
    });

    it('displays task title', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Add Stripe', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.text()).toContain('Add Stripe');
    });

    it('displays elapsed time for running tasks', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Add Stripe', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        // 5 minutes elapsed (12:00:00 → 12:05:00)
        expect(wrapper.find('[data-testid="elapsed-time"]').text()).toBe('5m 0s');
    });

    it('updates elapsed time every second', async () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Add Stripe', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.find('[data-testid="elapsed-time"]').text()).toBe('5m 0s');

        vi.advanceTimersByTime(1000);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="elapsed-time"]').text()).toBe('5m 1s');
    });

    it('shows pipeline link when pipeline_id is available', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 456, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        const link = wrapper.find('[data-testid="pipeline-link"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('View pipeline');
    });

    it('hides pipeline link when pipeline_id is null', () => {
        const tasks = [
            { task_id: 1, status: 'queued', type: 'feature_dev', title: 'Test', pipeline_id: null, pipeline_status: null, started_at: null, project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.find('[data-testid="pipeline-link"]').exists()).toBe(false);
    });

    it('shows runner load warning when pipeline_status is pending (D133)', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 456, pipeline_status: 'pending', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.text()).toContain('Waiting for available runner');
        expect(wrapper.text()).toContain('System busy, expect delays');
    });

    it('does NOT show runner load warning when pipeline is running normally', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 456, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.text()).not.toContain('Waiting for available runner');
    });

    it('shows action type badge with emoji', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 456, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.find('[data-testid="task-type-badge"]').exists()).toBe(true);
    });

    it('shows queued state text when task is queued', () => {
        const tasks = [
            { task_id: 1, status: 'queued', type: 'feature_dev', title: 'Test', pipeline_id: null, pipeline_status: null, started_at: null, project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.text()).toContain('Queued');
    });

    it('cleans up interval on unmount', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const clearSpy = vi.spyOn(globalThis, 'clearInterval');
        const wrapper = mountBar(tasks);
        wrapper.unmount();
        expect(clearSpy).toHaveBeenCalled();
    });
});
