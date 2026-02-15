import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import AdminRoleList from './AdminRoleList.vue';
import { useAdminStore } from '@/stores/admin';

vi.mock('axios');

describe('AdminRoleList', () => {
    let pinia;
    let admin;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        // Mock API calls triggered by onMounted
        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    it('renders roles heading', () => {
        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Roles');
    });

    it('shows create role button', () => {
        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="create-role-btn"]').exists()).toBe(true);
    });

    it('shows empty state when no roles', () => {
        admin.roles = [];
        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('No roles defined');
    });

    it('shows loading state', () => {
        admin.rolesLoading = true;
        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Loading roles...');
    });

    it('renders role list from store', async () => {
        admin.roles = [
            { id: 1, project_id: 1, project_name: 'Alpha', name: 'developer', description: 'Dev role', is_default: false, permissions: ['chat.access'], user_count: 3 },
            { id: 2, project_id: 1, project_name: 'Alpha', name: 'reviewer', description: null, is_default: true, permissions: ['review.view', 'review.comment'], user_count: 1 },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('developer');
        expect(wrapper.text()).toContain('reviewer');
        expect(wrapper.text()).toContain('Alpha');
    });

    it('renders permission badges on role cards', async () => {
        admin.roles = [
            { id: 1, project_id: 1, project_name: 'Alpha', name: 'developer', description: null, is_default: false, permissions: ['chat.access', 'review.view'], user_count: 0 },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('chat.access');
        expect(wrapper.text()).toContain('review.view');
    });

    it('shows no permissions text for roles without permissions', async () => {
        admin.roles = [
            { id: 1, project_id: 1, project_name: 'Alpha', name: 'viewer', description: null, is_default: false, permissions: [], user_count: 0 },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('No permissions');
    });

    it('shows user count per role', async () => {
        admin.roles = [
            { id: 1, project_id: 1, project_name: 'Alpha', name: 'developer', description: null, is_default: false, permissions: [], user_count: 5 },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('5 user(s)');
    });

    it('shows default badge for default roles', async () => {
        admin.roles = [
            { id: 1, project_id: 1, project_name: 'Alpha', name: 'member', description: null, is_default: true, permissions: [], user_count: 0 },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Default');
    });

    it('shows create form when create button clicked', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="create-role-form"]').exists()).toBe(true);
    });

    it('shows edit form when edit button clicked', async () => {
        admin.roles = [
            { id: 1, project_id: 1, project_name: 'Alpha', name: 'developer', description: 'A role', is_default: false, permissions: ['chat.access'], user_count: 0 },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-1"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="edit-role-form-1"]').exists()).toBe(true);
    });

    it('shows edit and delete buttons for each role', async () => {
        admin.roles = [
            { id: 1, project_id: 1, project_name: 'Alpha', name: 'developer', description: null, is_default: false, permissions: [], user_count: 0 },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="edit-role-btn-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="delete-role-btn-1"]').exists()).toBe(true);
    });

    it('fetches roles and permissions on mount', () => {
        mount(AdminRoleList, { global: { plugins: [pinia] } });

        expect(admin.fetchRoles).toHaveBeenCalled();
        expect(admin.fetchPermissions).toHaveBeenCalled();
    });
});
