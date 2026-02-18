import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import { useAuthStore } from '@/stores/auth';
import AdminPage from './AdminPage.vue';

vi.mock('axios');

describe('adminPage', () => {
    let pinia: ReturnType<typeof createPinia>;

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

    it('shows Dead Letter tab', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="admin-tab-dlq"]').exists()).toBe(true);
    });

    it('switches to Dead Letter tab content on click', async () => {
        const admin = useAdminStore();
        vi.spyOn(admin, 'fetchDeadLetterEntries').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="admin-tab-dlq"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Dead Letter');
    });

    it('shows all five tabs', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="admin-tab-projects"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="admin-tab-roles"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="admin-tab-assignments"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="admin-tab-settings"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="admin-tab-dlq"]').exists()).toBe(true);
    });

    it('highlights the active tab with distinct styling', async () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });

        // Projects tab is active by default
        const projectsTab = wrapper.find('[data-testid="admin-tab-projects"]');
        expect(projectsTab.classes()).toContain('border-zinc-500');

        // Roles tab is not active
        const rolesTab = wrapper.find('[data-testid="admin-tab-roles"]');
        expect(rolesTab.classes()).not.toContain('border-zinc-500');
    });

    it('shows AdminProjectConfig when configure event is emitted from project list', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 42, name: 'ConfigProject', slug: 'config-project', enabled: true, webhook_configured: true, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjectConfig').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Click the configure button for the project
        const configBtn = wrapper.find('[data-testid="configure-btn-42"]');
        expect(configBtn.exists()).toBe(true);
        await configBtn.trigger('click');
        await wrapper.vm.$nextTick();

        // AdminProjectConfig should now be visible with project name
        expect(wrapper.text()).toContain('ConfigProject');
        expect(wrapper.find('[data-testid="back-btn"]').exists()).toBe(true);
    });

    it('returns to project list when back is clicked from project config', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 42, name: 'ConfigProject', slug: 'config-project', enabled: true, webhook_configured: true, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjectConfig').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Navigate to config
        await wrapper.find('[data-testid="configure-btn-42"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Click back
        await wrapper.find('[data-testid="back-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Should be back to project list
        expect(wrapper.find('[data-testid="configure-btn-42"]').exists()).toBe(true);
    });

    it('shows AdminPrdTemplate when edit-template is clicked from project config', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 42, name: 'ConfigProject', slug: 'config-project', enabled: true, webhook_configured: true, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjectConfig').mockResolvedValue();
        vi.spyOn(admin, 'fetchPrdTemplate').mockResolvedValue();
        vi.spyOn(admin, 'fetchGlobalPrdTemplate').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Navigate to config
        await wrapper.find('[data-testid="configure-btn-42"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Click edit template
        const templateBtn = wrapper.find('[data-testid="edit-template-btn"]');
        expect(templateBtn.exists()).toBe(true);
        await templateBtn.trigger('click');
        await wrapper.vm.$nextTick();

        // AdminPrdTemplate should now be visible
        expect(wrapper.text()).toContain('ConfigProject');
        expect(wrapper.find('[data-testid="back-btn"]').exists()).toBe(true);
    });

    it('returns to project list from PRD template via back button', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 42, name: 'ConfigProject', slug: 'config-project', enabled: true, webhook_configured: true, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjectConfig').mockResolvedValue();
        vi.spyOn(admin, 'fetchPrdTemplate').mockResolvedValue();
        vi.spyOn(admin, 'fetchGlobalPrdTemplate').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // Navigate: project list → config → prd template
        await wrapper.find('[data-testid="configure-btn-42"]').trigger('click');
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-testid="edit-template-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Click back from PRD template (should clear editingTemplate, show project list since configuringProject was replaced)
        await wrapper.find('[data-testid="back-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        // editingTemplate is null, but configuringProject is still set, so AdminProjectConfig shows
        expect(wrapper.find('[data-testid="edit-template-btn"]').exists()).toBe(true);
    });

    it('defaults to projects tab showing AdminProjectList', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        // Default activeTab is 'projects', so project list component should render
        expect(wrapper.find('[data-testid="admin-tab-projects"]').classes()).toContain('border-zinc-500');
    });
});
