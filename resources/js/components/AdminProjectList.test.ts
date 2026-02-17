import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import AdminProjectList from './AdminProjectList.vue';

vi.mock('axios');

describe('adminProjectList \u2014 Configure button (T91)', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

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
        expect(wrapper.emitted('configure')![0][0]).toEqual({ id: 1, name: 'Project A' });
    });
});
