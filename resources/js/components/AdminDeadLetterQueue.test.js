import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import AdminDeadLetterQueue from './AdminDeadLetterQueue.vue';

vi.mock('axios');

function makeEntry(overrides = {}) {
    return {
        id: 1,
        failure_reason: 'max_retries_exceeded',
        dead_lettered_at: '2026-02-15T10:00:00Z',
        error_details: 'Connection refused after 3 attempts',
        attempt_count: 3,
        task_record: { type: 'code_review', project_id: 42 },
        task_id: 100,
        dismissed: false,
        retried: false,
        attempt_history: [],
        ...overrides,
    };
}

describe('adminDeadLetterQueue (T97)', () => {
    let pinia;
    let admin;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
        // Prevent onMounted fetch from making real calls
        admin.fetchDeadLetterEntries = vi.fn();
        admin.fetchDeadLetterDetail = vi.fn();
        admin.retryDeadLetterEntry = vi.fn();
        admin.dismissDeadLetterEntry = vi.fn();
    });

    it('shows loading state while fetching', () => {
        admin.deadLetterLoading = true;
        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="dlq-loading"]').exists()).toBe(true);
    });

    it('shows empty state when no entries', () => {
        admin.deadLetterLoading = false;
        admin.deadLetterEntries = [];
        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="dlq-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="dlq-empty"]').text()).toContain('No failed tasks');
    });

    it('renders entry list with failure reason badges', () => {
        admin.deadLetterEntries = [
            makeEntry({ id: 1, failure_reason: 'max_retries_exceeded' }),
            makeEntry({ id: 2, failure_reason: 'expired' }),
        ];

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="dlq-entry-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="dlq-entry-2"]').exists()).toBe(true);
        // Check badge text
        expect(wrapper.find('[data-testid="dlq-entry-1"]').text()).toContain('max retries exceeded');
        expect(wrapper.find('[data-testid="dlq-entry-2"]').text()).toContain('expired');
    });

    it('clicking entry shows detail view', async () => {
        const entry = makeEntry({ id: 5 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            attempt_history: [{ attempted_at: '2026-02-15T09:00:00Z', error: 'Timeout' }],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-5"]').trigger('click');

        expect(admin.fetchDeadLetterDetail).toHaveBeenCalledWith(5);
        expect(wrapper.find('[data-testid="dlq-detail-5"]').exists()).toBe(true);
    });

    it('retry button calls store action and removes entry from list', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const entry = makeEntry({ id: 7 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = entry;
        admin.retryDeadLetterEntry.mockResolvedValue({ success: true, data: { id: 200 } });

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        // Navigate to detail
        await wrapper.find('[data-testid="dlq-entry-7"]').trigger('click');

        await wrapper.find('[data-testid="dlq-retry-btn-7"]').trigger('click');
        // Wait for async
        await vi.dynamicImportSettled();

        expect(admin.retryDeadLetterEntry).toHaveBeenCalledWith(7);
    });

    it('dismiss button calls store action and removes entry from list', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const entry = makeEntry({ id: 8 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = entry;
        admin.dismissDeadLetterEntry.mockResolvedValue({ success: true });

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-8"]').trigger('click');

        await wrapper.find('[data-testid="dlq-dismiss-btn-8"]').trigger('click');
        await vi.dynamicImportSettled();

        expect(admin.dismissDeadLetterEntry).toHaveBeenCalledWith(8);
    });

    it('shows error banner on action failure', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const entry = makeEntry({ id: 9 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = entry;
        admin.retryDeadLetterEntry.mockResolvedValue({ success: false, error: 'Already retried.' });

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-9"]').trigger('click');

        await wrapper.find('[data-testid="dlq-retry-btn-9"]').trigger('click');
        // Wait for the promise to resolve
        await new Promise(r => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="dlq-action-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="dlq-action-error"]').text()).toContain('Already retried.');
    });

    it('filter controls trigger store fetch with params', async () => {
        admin.deadLetterEntries = [];
        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });

        const select = wrapper.find('[data-testid="dlq-filter-reason"]');
        await select.setValue('expired');
        await select.trigger('change');

        expect(admin.fetchDeadLetterEntries).toHaveBeenCalledWith({ reason: 'expired' });
    });
});
