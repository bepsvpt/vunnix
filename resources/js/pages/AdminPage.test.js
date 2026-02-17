import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import { useAuthStore } from '@/stores/auth';
import AdminPage from './AdminPage.vue';

vi.mock('axios');

describe('adminPage', () => {
    let pinia;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);

        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            name: 'Admin',
            projects: [
                { id: 1, name: 'Test Project', permissions: ['admin.global_config'] },
            ],
        });
    });

    it('renders admin page heading', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Admin');
    });

    it('shows Projects tab', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="admin-tab-projects"]').exists()).toBe(true);
    });

    it('fetches projects on mount', () => {
        const admin = useAdminStore();
        const fetchSpy = vi.spyOn(admin, 'fetchProjects').mockResolvedValue();

        mount(AdminPage, { global: { plugins: [pinia] } });

        expect(fetchSpy).toHaveBeenCalled();
    });

    it('displays project list', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 1, name: 'Alpha', slug: 'alpha', enabled: true, webhook_configured: true, recent_task_count: 5, active_conversation_count: 2 },
            { id: 2, name: 'Beta', slug: 'beta', enabled: false, webhook_configured: false, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Alpha');
        expect(wrapper.text()).toContain('Beta');
    });

    it('shows enabled badge for enabled projects', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 1, name: 'Alpha', slug: 'alpha', enabled: true, webhook_configured: true, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="project-status-1"]').text()).toContain('Enabled');
    });

    it('shows disabled badge for disabled projects', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 1, name: 'Alpha', slug: 'alpha', enabled: false, webhook_configured: false, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="project-status-1"]').text()).toContain('Disabled');
    });

    it('shows Roles tab', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="admin-tab-roles"]').exists()).toBe(true);
    });

    it('shows Assignments tab', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="admin-tab-assignments"]').exists()).toBe(true);
    });

    it('switches to Roles tab content on click', async () => {
        const admin = useAdminStore();
        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="admin-tab-roles"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Roles');
        expect(wrapper.find('[data-testid="create-role-btn"]').exists()).toBe(true);
    });

    it('switches to Assignments tab content on click', async () => {
        const admin = useAdminStore();
        vi.spyOn(admin, 'fetchAssignments').mockResolvedValue();
        vi.spyOn(admin, 'fetchUsers').mockResolvedValue();
        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="admin-tab-assignments"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Role Assignments');
        expect(wrapper.find('[data-testid="assign-role-btn"]').exists()).toBe(true);
    });

    it('shows Settings tab', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="admin-tab-settings"]').exists()).toBe(true);
    });

    it('switches to Settings tab content on click', async () => {
        const admin = useAdminStore();
        vi.spyOn(admin, 'fetchSettings').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="admin-tab-settings"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Global Settings');
    });
});
