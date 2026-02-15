import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import AdminProjectList from './AdminProjectList.vue';
import { useAdminStore } from '@/stores/admin';

vi.mock('axios');

describe('AdminProjectList — Configure button (T91)', () => {
    let pinia;
    let admin;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
    });

    it('shows Configure button for enabled projects', () => {
        admin.projects = [
            { id: 1, name: 'Project A', slug: 'project-a', gitlab_project_id: 42, enabled: true, webhook_configured: true, recent_task_count: 5, active_conversation_count: 2 },
        ];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="configure-btn-1"]').exists()).toBe(true);
    });

    it('does not show Configure button for disabled projects', () => {
        admin.projects = [
            { id: 1, name: 'Project A', slug: 'project-a', gitlab_project_id: 42, enabled: false, webhook_configured: false, recent_task_count: 0, active_conversation_count: 0 },
        ];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="configure-btn-1"]').exists()).toBe(false);
    });

    it('emits configure event with project data when Configure is clicked', async () => {
        admin.projects = [
            { id: 1, name: 'Project A', slug: 'project-a', gitlab_project_id: 42, enabled: true, webhook_configured: true, recent_task_count: 5, active_conversation_count: 2 },
        ];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="configure-btn-1"]').trigger('click');

        expect(wrapper.emitted('configure')).toBeTruthy();
        expect(wrapper.emitted('configure')[0][0]).toEqual({ id: 1, name: 'Project A' });
    });
});
