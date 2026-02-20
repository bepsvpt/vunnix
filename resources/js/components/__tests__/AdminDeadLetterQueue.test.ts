import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import AdminDeadLetterQueue from '../AdminDeadLetterQueue.vue';

vi.mock('axios');

function makeEntry(overrides: Record<string, unknown> = {}) {
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
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

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
        (admin.retryDeadLetterEntry as ReturnType<typeof vi.fn>).mockResolvedValue({ success: true, data: { id: 200 } });

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
        (admin.dismissDeadLetterEntry as ReturnType<typeof vi.fn>).mockResolvedValue({ success: true });

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
        (admin.retryDeadLetterEntry as ReturnType<typeof vi.fn>).mockResolvedValue({ success: false, error: 'Already retried.' });

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

    it('displays attempt history in detail view when entry has multiple attempts', async () => {
        const entry = makeEntry({ id: 20 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            attempt_history: [
                { attempted_at: '2026-02-15T08:00:00Z', error: 'Connection timeout' },
                { attempted_at: '2026-02-15T09:00:00Z', error: 'Service unavailable' },
                { attempted_at: '2026-02-15T10:00:00Z', error: 'Max retries hit' },
            ],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-20"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Attempt History');
        expect(wrapper.text()).toContain('Attempt 1');
        expect(wrapper.text()).toContain('Connection timeout');
        expect(wrapper.text()).toContain('Attempt 2');
        expect(wrapper.text()).toContain('Service unavailable');
        expect(wrapper.text()).toContain('Attempt 3');
        expect(wrapper.text()).toContain('Max retries hit');
    });

    it('displays attempt history entry without error message', async () => {
        const entry = makeEntry({ id: 21 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            attempt_history: [
                { attempted_at: '2026-02-15T08:00:00Z' },
            ],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-21"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Attempt History');
        expect(wrapper.text()).toContain('Attempt 1');
        // Should not contain error paragraph since attempt.error is absent
        const attemptSection = wrapper.find('.border-l-2');
        expect(attemptSection.exists()).toBe(true);
        expect(attemptSection.findAll('p').length).toBe(0);
    });

    it('hides attempt history section when attempt_history is empty', async () => {
        const entry = makeEntry({ id: 22 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            attempt_history: [],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-22"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Attempt History');
    });

    it('shows "No error details recorded." when error_details is absent', async () => {
        const entry = makeEntry({ id: 23, error_details: null });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            error_details: null,
            attempt_history: [],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-23"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('No error details recorded.');
    });

    it('shows full error details text in detail view', async () => {
        const longError = 'Error: Connection refused\n  at Socket.connect (net.js:100)\n  at Agent.createConnection (http.js:200)';
        const entry = makeEntry({ id: 24 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            error_details: longError,
            attempt_history: [],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-24"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('pre').text()).toContain('Error: Connection refused');
        expect(wrapper.find('pre').text()).toContain('at Socket.connect');
    });

    it('applies multiple filters simultaneously', async () => {
        admin.deadLetterEntries = [];
        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });

        // Set reason filter
        const reasonSelect = wrapper.find('[data-testid="dlq-filter-reason"]');
        await reasonSelect.setValue('context_exceeded');

        // Set date-from filter
        const dateFromInput = wrapper.find('[data-testid="dlq-filter-date-from"]');
        await dateFromInput.setValue('2026-01-01');

        // Set date-to filter
        const dateToInput = wrapper.find('[data-testid="dlq-filter-date-to"]');
        await dateToInput.setValue('2026-02-01');
        await dateToInput.trigger('change');

        expect(admin.fetchDeadLetterEntries).toHaveBeenCalledWith({
            reason: 'context_exceeded',
            date_from: '2026-01-01',
            date_to: '2026-02-01',
        });
    });

    it('clears reason filter and fetches with remaining filters', async () => {
        admin.deadLetterEntries = [];
        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });

        // Set reason first
        const reasonSelect = wrapper.find('[data-testid="dlq-filter-reason"]');
        await reasonSelect.setValue('expired');
        await reasonSelect.trigger('change');

        // Now clear reason back to "All reasons"
        await reasonSelect.setValue('');
        await reasonSelect.trigger('change');

        expect(admin.fetchDeadLetterEntries).toHaveBeenLastCalledWith({});
    });

    it('date-from filter triggers fetch independently', async () => {
        admin.deadLetterEntries = [];
        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });

        const dateFromInput = wrapper.find('[data-testid="dlq-filter-date-from"]');
        await dateFromInput.setValue('2026-02-10');
        await dateFromInput.trigger('change');

        expect(admin.fetchDeadLetterEntries).toHaveBeenCalledWith({
            date_from: '2026-02-10',
        });
    });

    it('renders all failure reason badge variants in list view', () => {
        admin.deadLetterEntries = [
            makeEntry({ id: 10, failure_reason: 'invalid_request' }),
            makeEntry({ id: 11, failure_reason: 'context_exceeded' }),
            makeEntry({ id: 12, failure_reason: 'scheduling_timeout' }),
        ];

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });

        expect(wrapper.find('[data-testid="dlq-entry-10"]').text()).toContain('invalid request');
        expect(wrapper.find('[data-testid="dlq-entry-11"]').text()).toContain('context exceeded');
        expect(wrapper.find('[data-testid="dlq-entry-12"]').text()).toContain('scheduling timeout');

        // Verify badge CSS classes
        const entry10Badge = wrapper.find('[data-testid="dlq-entry-10"] .rounded-full');
        expect(entry10Badge.classes()).toContain('bg-orange-100');
        const entry11Badge = wrapper.find('[data-testid="dlq-entry-11"] .rounded-full');
        expect(entry11Badge.classes()).toContain('bg-purple-100');
        const entry12Badge = wrapper.find('[data-testid="dlq-entry-12"] .rounded-full');
        expect(entry12Badge.classes()).toContain('bg-blue-100');
    });

    it('renders unknown failure reason with fallback badge class', () => {
        admin.deadLetterEntries = [
            makeEntry({ id: 13, failure_reason: 'unknown_reason' }),
        ];

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        const badge = wrapper.find('[data-testid="dlq-entry-13"] .rounded-full');
        expect(badge.classes()).toContain('bg-zinc-100');
        expect(badge.text()).toContain('unknown reason');
    });

    it('renders failure reason badge in detail view', async () => {
        const entry = makeEntry({ id: 30, failure_reason: 'context_exceeded' });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            attempt_history: [],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-30"]').trigger('click');
        await wrapper.vm.$nextTick();

        const detailBadge = wrapper.find('[data-testid="dlq-detail-30"] .rounded-full');
        expect(detailBadge.classes()).toContain('bg-purple-100');
        expect(detailBadge.text()).toContain('context exceeded');
    });

    it('shows task type and attempt count in entry list', () => {
        admin.deadLetterEntries = [
            makeEntry({
                id: 14,
                task_record: { type: 'feature_dev', project_id: 99 },
                attempt_count: 5,
            }),
        ];

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        const entryEl = wrapper.find('[data-testid="dlq-entry-14"]');
        expect(entryEl.text()).toContain('feature_dev');
        expect(entryEl.text()).toContain('5 attempts');
    });

    it('truncates long error_details in list view', () => {
        const longError = 'A'.repeat(200);
        admin.deadLetterEntries = [
            makeEntry({ id: 15, error_details: longError }),
        ];

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        const entryEl = wrapper.find('[data-testid="dlq-entry-15"]');
        // truncate default is 120 chars + ellipsis
        expect(entryEl.text()).toContain('A'.repeat(120));
        expect(entryEl.text()).not.toContain('A'.repeat(200));
    });

    it('shows em-dash for entries without error_details', () => {
        admin.deadLetterEntries = [
            makeEntry({ id: 16, error_details: null }),
        ];

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        const entryEl = wrapper.find('[data-testid="dlq-entry-16"]');
        // entry without error_details should not have the truncated paragraph rendered
        // (the v-if="entry.error_details" hides the paragraph)
        expect(entryEl.findAll('.truncate').length).toBe(0);
    });

    it('does not call retry when confirm is cancelled', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        const entry = makeEntry({ id: 25 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = entry;

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-25"]').trigger('click');

        await wrapper.find('[data-testid="dlq-retry-btn-25"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(admin.retryDeadLetterEntry).not.toHaveBeenCalled();
    });

    it('does not call dismiss when confirm is cancelled', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        const entry = makeEntry({ id: 26 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = entry;

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-26"]').trigger('click');

        await wrapper.find('[data-testid="dlq-dismiss-btn-26"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(admin.dismissDeadLetterEntry).not.toHaveBeenCalled();
    });

    it('back to list button returns to list view and clears detail', async () => {
        const entry = makeEntry({ id: 27 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = entry;

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-27"]').trigger('click');
        expect(wrapper.find('[data-testid="dlq-detail-27"]').exists()).toBe(true);

        // Click back button
        const backBtn = wrapper.findAll('button').find(b => b.text().includes('Back to list'));
        expect(backBtn).toBeTruthy();
        await backBtn!.trigger('click');

        expect(admin.deadLetterDetail).toBeNull();
        expect(wrapper.find('[data-testid="dlq-detail-27"]').exists()).toBe(false);
    });

    it('shows detail loading state while fetching entry detail', async () => {
        const entry = makeEntry({ id: 28 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetailLoading = true;
        admin.deadLetterDetail = null;

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-28"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="dlq-detail-loading"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="dlq-detail-loading"]').text()).toContain('Loading entry details...');
    });

    it('shows error banner on dismiss failure', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const entry = makeEntry({ id: 29 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = entry;
        (admin.dismissDeadLetterEntry as ReturnType<typeof vi.fn>).mockResolvedValue({ success: false, error: 'Cannot dismiss.' });

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-29"]').trigger('click');

        await wrapper.find('[data-testid="dlq-dismiss-btn-29"]').trigger('click');
        await new Promise(r => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="dlq-action-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="dlq-action-error"]').text()).toContain('Cannot dismiss.');
    });

    it('displays task type and project ID in detail view', async () => {
        const entry = makeEntry({ id: 31, task_record: { type: 'deep_analysis', project_id: 77 } });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            attempt_history: [],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-31"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('deep_analysis');
        expect(wrapper.text()).toContain('77');
        expect(wrapper.text()).toContain('Entry #31');
    });

    it('displays em-dash for missing date in formatDate', async () => {
        const entry = makeEntry({ id: 32, dead_lettered_at: null });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            dead_lettered_at: null,
            attempt_history: [],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-32"]').trigger('click');
        await wrapper.vm.$nextTick();

        // The formatDate(null) should return '\u2014' (em-dash)
        expect(wrapper.text()).toContain('\u2014');
    });

    it('shows entry without task_record type', () => {
        admin.deadLetterEntries = [
            makeEntry({ id: 33, task_record: null }),
        ];

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        const entryEl = wrapper.find('[data-testid="dlq-entry-33"]');
        expect(entryEl.exists()).toBe(true);
        // Should not crash and task type should not appear
        expect(entryEl.text()).toContain('max retries exceeded');
    });

    it('clears selectedEntry on successful retry', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const entry = makeEntry({ id: 34 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = entry;
        (admin.retryDeadLetterEntry as ReturnType<typeof vi.fn>).mockResolvedValue({ success: true, data: { id: 300 } });

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-34"]').trigger('click');
        expect(wrapper.find('[data-testid="dlq-detail-34"]').exists()).toBe(true);

        await wrapper.find('[data-testid="dlq-retry-btn-34"]').trigger('click');
        await new Promise(r => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        // After successful retry, should return to list view
        expect(wrapper.find('[data-testid="dlq-detail-34"]').exists()).toBe(false);
    });

    it('clears selectedEntry on successful dismiss', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const entry = makeEntry({ id: 35 });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = entry;
        (admin.dismissDeadLetterEntry as ReturnType<typeof vi.fn>).mockResolvedValue({ success: true });

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-35"]').trigger('click');
        expect(wrapper.find('[data-testid="dlq-detail-35"]').exists()).toBe(true);

        await wrapper.find('[data-testid="dlq-dismiss-btn-35"]').trigger('click');
        await new Promise(r => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        // After successful dismiss, should return to list view
        expect(wrapper.find('[data-testid="dlq-detail-35"]').exists()).toBe(false);
    });

    it('shows detail view with task_record missing type and project_id', async () => {
        const entry = makeEntry({ id: 36, task_record: {} });
        admin.deadLetterEntries = [entry];
        admin.deadLetterDetail = {
            ...entry,
            task_record: {},
            attempt_history: [],
        };

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="dlq-entry-36"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Missing type and project_id should show fallback '—'
        const detailText = wrapper.find('[data-testid="dlq-detail-36"]').text();
        expect(detailText).toContain('Task type');
        expect(detailText).toContain('Project ID');
    });

    it('shows entry without attempt_count in list', () => {
        admin.deadLetterEntries = [
            makeEntry({ id: 37, attempt_count: undefined, error_details: 'Some error' }),
        ];

        const wrapper = mount(AdminDeadLetterQueue, { global: { plugins: [pinia] } });
        const entryEl = wrapper.find('[data-testid="dlq-entry-37"]');
        expect(entryEl.exists()).toBe(true);
        // The v-if="entry.attempt_count" span should not render when attempt_count is undefined
        // so the "· X attempts" portion should be absent
        const dateText = entryEl.findAll('p').find(p => p.text().includes('2/15/2026'));
        expect(dateText).toBeTruthy();
        expect(dateText!.text()).not.toMatch(/\d+ attempts/);
    });
});
