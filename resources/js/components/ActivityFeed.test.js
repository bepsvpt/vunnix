import { mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDashboardStore } from '@/stores/dashboard';
import ActivityFeed from './ActivityFeed.vue';

vi.mock('axios');

let pinia;

function mountFeed() {
    return mount(ActivityFeed, {
        global: {
            plugins: [pinia],
        },
    });
}

describe('activityFeed', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        vi.clearAllMocks();
        // Prevent unhandled rejections from axios calls in the store
        axios.get.mockResolvedValue({
            data: { data: [], meta: { next_cursor: null, per_page: 25 } },
        });
    });

    // -- Container --

    it('renders the activity feed container', () => {
        const wrapper = mountFeed();
        expect(wrapper.find('[data-testid="activity-feed"]').exists()).toBe(true);
    });

    // -- Filter tabs --

    it('renders all five filter tabs', () => {
        const wrapper = mountFeed();
        expect(wrapper.find('[data-testid="filter-tab-all"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-tab-code_review"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-tab-feature_dev"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-tab-ui_adjustment"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-tab-prd_creation"]').exists()).toBe(true);
    });

    it('highlights the active filter tab', () => {
        const store = useDashboardStore();
        store.activeFilter = 'code_review';
        const wrapper = mountFeed();
        const tab = wrapper.find('[data-testid="filter-tab-code_review"]');
        expect(tab.classes().join(' ')).toContain('bg-zinc-100');
    });

    it('clicking a filter tab calls fetchActivity with correct type', async () => {
        const store = useDashboardStore();
        store.fetchActivity = vi.fn();
        const wrapper = mountFeed();
        await wrapper.find('[data-testid="filter-tab-code_review"]').trigger('click');
        expect(store.fetchActivity).toHaveBeenCalledWith('code_review');
    });

    it('clicking All tab calls fetchActivity with null', async () => {
        const store = useDashboardStore();
        store.fetchActivity = vi.fn();
        const wrapper = mountFeed();
        await wrapper.find('[data-testid="filter-tab-all"]').trigger('click');
        expect(store.fetchActivity).toHaveBeenCalledWith(null);
    });

    // -- Feed items --

    it('renders activity items from store', () => {
        const store = useDashboardStore();
        store.activityFeed = [
            { task_id: 1, type: 'code_review', status: 'completed', project_id: 10, project_name: 'proj', summary: 'Review', created_at: new Date().toISOString() },
            { task_id: 2, type: 'feature_dev', status: 'running', project_id: 10, project_name: 'proj', summary: 'Build', created_at: new Date().toISOString() },
        ];
        const wrapper = mountFeed();
        const items = wrapper.findAll('[data-testid="activity-item"]');
        expect(items).toHaveLength(2);
    });

    // -- Load more --

    it('shows Load more button when hasMore is true', () => {
        const store = useDashboardStore();
        store.activityFeed = [
            { task_id: 1, type: 'code_review', status: 'completed', project_id: 10, project_name: 'proj', summary: 'A', created_at: new Date().toISOString() },
        ];
        store.nextCursor = 'abc';
        const wrapper = mountFeed();
        expect(wrapper.find('[data-testid="load-more-btn"]').exists()).toBe(true);
    });

    it('hides Load more button when hasMore is false', () => {
        const store = useDashboardStore();
        store.activityFeed = [
            { task_id: 1, type: 'code_review', status: 'completed', project_id: 10, project_name: 'proj', summary: 'A', created_at: new Date().toISOString() },
        ];
        store.nextCursor = null;
        const wrapper = mountFeed();
        expect(wrapper.find('[data-testid="load-more-btn"]').exists()).toBe(false);
    });

    it('clicking Load more calls store.loadMore', async () => {
        const store = useDashboardStore();
        store.activityFeed = [
            { task_id: 1, type: 'code_review', status: 'completed', project_id: 10, project_name: 'proj', summary: 'A', created_at: new Date().toISOString() },
        ];
        store.nextCursor = 'abc';
        store.loadMore = vi.fn();
        const wrapper = mountFeed();
        await wrapper.find('[data-testid="load-more-btn"]').trigger('click');
        expect(store.loadMore).toHaveBeenCalled();
    });

    // -- Empty state --

    it('shows empty state when no items and not loading', () => {
        const store = useDashboardStore();
        store.activityFeed = [];
        store.isLoading = false;
        const wrapper = mountFeed();
        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No activity yet');
    });

    it('hides empty state when items exist', () => {
        const store = useDashboardStore();
        store.activityFeed = [
            { task_id: 1, type: 'code_review', status: 'completed', project_id: 10, project_name: 'proj', summary: 'A', created_at: new Date().toISOString() },
        ];
        const wrapper = mountFeed();
        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(false);
    });

    // -- Loading state --

    it('shows loading indicator when isLoading and feed is empty', () => {
        const store = useDashboardStore();
        store.activityFeed = [];
        store.isLoading = true;
        const wrapper = mountFeed();
        expect(wrapper.find('[data-testid="loading-indicator"]').exists()).toBe(true);
    });

    it('hides loading indicator when not loading', () => {
        const store = useDashboardStore();
        store.activityFeed = [];
        store.isLoading = false;
        const wrapper = mountFeed();
        expect(wrapper.find('[data-testid="loading-indicator"]').exists()).toBe(false);
    });
});
