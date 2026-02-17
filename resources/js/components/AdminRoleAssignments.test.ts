import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import AdminRoleAssignments from './AdminRoleAssignments.vue';

vi.mock('axios');

describe('adminRoleAssignments', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        // Mock API calls triggered by onMounted
        vi.spyOn(admin, 'fetchAssignments').mockResolvedValue();
        vi.spyOn(admin, 'fetchUsers').mockResolvedValue();
        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    it('renders assignments heading', () => {
        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Role Assignments');
    });

    it('shows assign role button', () => {
        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="assign-role-btn"]').exists()).toBe(true);
    });

    it('shows empty state when no assignments', () => {
        admin.roleAssignments = [];
        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('No role assignments found');
    });

    it('renders assignment list from store', async () => {
        admin.roleAssignments = [
            { user_id: 1, user_name: 'Alice', user_email: 'alice@test.com', username: 'alice', role_id: 1, role_name: 'developer', project_id: 1, project_name: 'Alpha', assigned_by: 2, assigned_at: '2026-01-01T00:00:00Z' },
            { user_id: 2, user_name: 'Bob', user_email: 'bob@test.com', username: 'bob', role_id: 2, role_name: 'reviewer', project_id: 1, project_name: 'Alpha', assigned_by: 2, assigned_at: '2026-01-01T00:00:00Z' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Alice');
        expect(wrapper.text()).toContain('@alice');
        expect(wrapper.text()).toContain('developer');
        expect(wrapper.text()).toContain('Bob');
        expect(wrapper.text()).toContain('reviewer');
    });

    it('shows project filter dropdown', () => {
        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="filter-project"]').exists()).toBe(true);
    });

    it('shows revoke button for each assignment', async () => {
        admin.roleAssignments = [
            { user_id: 1, user_name: 'Alice', user_email: 'alice@test.com', username: 'alice', role_id: 1, role_name: 'developer', project_id: 1, project_name: 'Alpha', assigned_by: 2, assigned_at: '2026-01-01T00:00:00Z' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="revoke-btn-0"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="revoke-btn-0"]').text()).toContain('Revoke');
    });

    it('shows assign form when assign button clicked', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [{ id: 1, project_id: 1, name: 'developer' }];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="assign-role-form"]').exists()).toBe(true);
    });

    it('fetches assignments and users on mount', () => {
        mount(AdminRoleAssignments, { global: { plugins: [pinia] } });

        expect(admin.fetchAssignments).toHaveBeenCalled();
        expect(admin.fetchUsers).toHaveBeenCalled();
    });

    it('renders project name and role name for each assignment', async () => {
        admin.roleAssignments = [
            { user_id: 1, user_name: 'Alice', user_email: 'alice@test.com', username: 'alice', role_id: 1, role_name: 'admin', project_id: 1, project_name: 'MyProject', assigned_by: 2, assigned_at: '2026-01-01T00:00:00Z' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('admin');
        expect(wrapper.text()).toContain('MyProject');
    });
});
