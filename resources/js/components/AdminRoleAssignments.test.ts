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

    it('fetches roles when roles array is empty on mount', () => {
        admin.roles = [];
        mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        expect(admin.fetchRoles).toHaveBeenCalled();
    });

    it('fetches projects when projects array is empty on mount', () => {
        admin.projects = [];
        mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        expect(admin.fetchProjects).toHaveBeenCalled();
    });

    it('does not fetch roles when roles already populated', () => {
        admin.roles = [{ id: 1, project_id: 1, name: 'developer', description: '', permissions: [], user_count: 0 }];
        mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        expect(admin.fetchRoles).not.toHaveBeenCalled();
    });

    it('does not fetch projects when projects already populated', () => {
        admin.projects = [{ id: 1, name: 'Alpha', gitlab_id: 100, enabled: true, created_at: '', updated_at: '' }];
        mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        expect(admin.fetchProjects).not.toHaveBeenCalled();
    });

    it('startAssign populates form with first project, user, and matching role', async () => {
        admin.projects = [
            { id: 10, name: 'ProjectA' },
            { id: 20, name: 'ProjectB' },
        ];
        admin.users = [
            { id: 100, name: 'Alice', username: 'alice' },
            { id: 200, name: 'Bob', username: 'bob' },
        ];
        admin.roles = [
            { id: 1, project_id: 10, name: 'developer' },
            { id: 2, project_id: 20, name: 'reviewer' },
            { id: 3, project_id: 10, name: 'admin' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Form should be visible
        expect(wrapper.find('[data-testid="assign-role-form"]').exists()).toBe(true);

        // Project select should have the first project selected
        const projectSelect = wrapper.find('[data-testid="assign-project"]');
        expect((projectSelect.element as HTMLSelectElement).value).toBe('10');

        // User select should have first user selected
        const userSelect = wrapper.find('[data-testid="assign-user"]');
        expect((userSelect.element as HTMLSelectElement).value).toBe('100');

        // Role select should have the first role matching project 10
        const roleSelect = wrapper.find('[data-testid="assign-role"]');
        expect((roleSelect.element as HTMLSelectElement).value).toBe('1');
    });

    it('startAssign clears previous action error', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [{ id: 1, project_id: 1, name: 'dev' }];

        // First, create an error by submitting a failed assignment
        vi.spyOn(admin, 'assignRole').mockResolvedValue({ success: false, error: 'Some error' });

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="assign-submit"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="assignment-action-error"]').exists()).toBe(true);

        // Close the form, click assign again — error should be cleared
        // Cancel the form first
        const cancelBtn = wrapper.findAll('button').find(b => b.text() === 'Cancel');
        await cancelBtn!.trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="assignment-action-error"]').exists()).toBe(false);
    });

    it('startAssign handles empty projects/users gracefully', async () => {
        admin.projects = [];
        admin.users = [];
        admin.roles = [];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="assign-role-form"]').exists()).toBe(true);
        // Should show "no roles" message
        expect(wrapper.text()).toContain('No roles defined for this project');
    });

    it('onProjectChange filters roles dropdown to selected project', async () => {
        admin.projects = [
            { id: 10, name: 'ProjectA' },
            { id: 20, name: 'ProjectB' },
        ];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [
            { id: 1, project_id: 10, name: 'dev' },
            { id: 2, project_id: 20, name: 'reviewer' },
            { id: 3, project_id: 20, name: 'tester' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Initially project 10 is selected, so only role id=1 should appear
        let roleOptions = wrapper.find('[data-testid="assign-role"]').findAll('option');
        expect(roleOptions).toHaveLength(1);
        expect(roleOptions[0].text()).toBe('dev');

        // Change project to 20
        const projectSelect = wrapper.find('[data-testid="assign-project"]');
        await projectSelect.setValue(20);
        await projectSelect.trigger('change');
        await wrapper.vm.$nextTick();

        // Now roles for project 20 should appear
        roleOptions = wrapper.find('[data-testid="assign-role"]').findAll('option');
        expect(roleOptions).toHaveLength(2);
        expect(roleOptions[0].text()).toBe('reviewer');
        expect(roleOptions[1].text()).toBe('tester');

        // Role should be auto-set to first role of new project
        expect((wrapper.find('[data-testid="assign-role"]').element as HTMLSelectElement).value).toBe('2');
    });

    it('onProjectChange shows no roles message when project has no roles', async () => {
        admin.projects = [
            { id: 10, name: 'ProjectA' },
            { id: 20, name: 'ProjectB' },
        ];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [
            { id: 1, project_id: 10, name: 'dev' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Change to project 20 which has no roles
        const projectSelect = wrapper.find('[data-testid="assign-project"]');
        await projectSelect.setValue(20);
        await projectSelect.trigger('change');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('No roles defined for this project');
    });

    it('submitAssign calls assignRole and closes form on success', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [{ id: 1, project_id: 1, name: 'dev' }];
        vi.spyOn(admin, 'assignRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="assign-submit"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(admin.assignRole).toHaveBeenCalledWith({ user_id: 1, role_id: 1, project_id: 1 });
        // Form should be hidden after success
        expect(wrapper.find('[data-testid="assign-role-form"]').exists()).toBe(false);
        // Should refresh assignments and roles after submit
        expect(admin.fetchAssignments).toHaveBeenCalledTimes(2); // once on mount, once after submit
        // fetchRoles is NOT called on mount because roles array is pre-populated, so only 1 call (after submit)
        expect(admin.fetchRoles).toHaveBeenCalledTimes(1);
    });

    it('submitAssign shows error on failure', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [{ id: 1, project_id: 1, name: 'dev' }];
        vi.spyOn(admin, 'assignRole').mockResolvedValue({ success: false, error: 'User already has this role' });

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="assign-submit"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Form should remain visible
        expect(wrapper.find('[data-testid="assign-role-form"]').exists()).toBe(true);
        // Error should be shown
        expect(wrapper.find('[data-testid="assignment-action-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="assignment-action-error"]').text()).toContain('User already has this role');
    });

    it('submitAssign handles error with no error message', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [{ id: 1, project_id: 1, name: 'dev' }];
        vi.spyOn(admin, 'assignRole').mockResolvedValue({ success: false });

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="assign-submit"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Form should remain visible (error result without message)
        expect(wrapper.find('[data-testid="assign-role-form"]').exists()).toBe(true);
    });

    it('submitAssign refreshes assignments with active filter', async () => {
        admin.projects = [
            { id: 1, name: 'Alpha' },
            { id: 2, name: 'Beta' },
        ];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [{ id: 1, project_id: 1, name: 'dev' }];
        vi.spyOn(admin, 'assignRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });

        // Set a filter first
        const filterSelect = wrapper.find('[data-testid="filter-project"]');
        await filterSelect.setValue(2);
        await filterSelect.trigger('change');
        await wrapper.vm.$nextTick();

        // Open form and submit
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-testid="assign-submit"]').trigger('click');
        await wrapper.vm.$nextTick();

        // fetchAssignments should be called with the filter project id
        expect(admin.fetchAssignments).toHaveBeenCalledWith(2);
    });

    it('handleRevoke calls revokeRole after confirmation', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(admin, 'revokeRole').mockResolvedValue({ success: true });

        admin.roleAssignments = [
            { user_id: 1, user_name: 'Alice', username: 'alice', role_id: 5, role_name: 'developer', project_id: 1, project_name: 'Alpha' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="revoke-btn-0"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(confirmSpy).toHaveBeenCalledWith('Revoke role "developer" from Alice on Alpha?');
        expect(admin.revokeRole).toHaveBeenCalledWith({ user_id: 1, role_id: 5 });
        // Should refresh assignments and roles
        expect(admin.fetchAssignments).toHaveBeenCalledTimes(2);
        expect(admin.fetchRoles).toHaveBeenCalledTimes(2);

        confirmSpy.mockRestore();
    });

    it('handleRevoke does nothing when confirmation is cancelled', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        vi.spyOn(admin, 'revokeRole').mockResolvedValue({ success: true });

        admin.roleAssignments = [
            { user_id: 1, user_name: 'Alice', username: 'alice', role_id: 5, role_name: 'developer', project_id: 1, project_name: 'Alpha' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="revoke-btn-0"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(confirmSpy).toHaveBeenCalled();
        expect(admin.revokeRole).not.toHaveBeenCalled();

        confirmSpy.mockRestore();
    });

    it('handleRevoke shows error on failure', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(admin, 'revokeRole').mockResolvedValue({ success: false, error: 'Cannot revoke last admin' });

        admin.roleAssignments = [
            { user_id: 1, user_name: 'Alice', username: 'alice', role_id: 5, role_name: 'admin', project_id: 1, project_name: 'Alpha' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="revoke-btn-0"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="assignment-action-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="assignment-action-error"]').text()).toContain('Cannot revoke last admin');
        // Should NOT refresh assignments on failure
        expect(admin.fetchAssignments).toHaveBeenCalledTimes(1); // only on mount

        confirmSpy.mockRestore();
    });

    it('handleRevoke refreshes with active filter on success', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(admin, 'revokeRole').mockResolvedValue({ success: true });

        admin.projects = [{ id: 3, name: 'Gamma' }];
        admin.roleAssignments = [
            { user_id: 1, user_name: 'Alice', username: 'alice', role_id: 5, role_name: 'dev', project_id: 3, project_name: 'Gamma' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });

        // Set filter first
        const filterSelect = wrapper.find('[data-testid="filter-project"]');
        await filterSelect.setValue(3);
        await filterSelect.trigger('change');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="revoke-btn-0"]').trigger('click');
        await wrapper.vm.$nextTick();

        // fetchAssignments should use the filter
        expect(admin.fetchAssignments).toHaveBeenCalledWith(3);

        confirmSpy.mockRestore();
    });

    it('filter dropdown shows project options from store', async () => {
        admin.projects = [
            { id: 1, name: 'Alpha' },
            { id: 2, name: 'Beta' },
            { id: 3, name: 'Gamma' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const filterOptions = wrapper.find('[data-testid="filter-project"]').findAll('option');
        // First is "All projects", plus 3 project options
        expect(filterOptions).toHaveLength(4);
        expect(filterOptions[0].text()).toBe('All projects');
        expect(filterOptions[1].text()).toBe('Alpha');
        expect(filterOptions[2].text()).toBe('Beta');
        expect(filterOptions[3].text()).toBe('Gamma');
    });

    it('filter dropdown triggers fetchAssignments with selected project', async () => {
        admin.projects = [
            { id: 1, name: 'Alpha' },
            { id: 2, name: 'Beta' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });

        const filterSelect = wrapper.find('[data-testid="filter-project"]');
        await filterSelect.setValue(2);
        await filterSelect.trigger('change');
        await wrapper.vm.$nextTick();

        expect(admin.fetchAssignments).toHaveBeenCalledWith(2);
    });

    it('applyFilter calls fetchAssignments with current filterProjectId', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });

        // Initially filterProjectId is null
        const filterSelect = wrapper.find('[data-testid="filter-project"]');
        await filterSelect.trigger('change');
        await wrapper.vm.$nextTick();

        // The first mount call is with no args, the change call should pass null
        const calls = (admin.fetchAssignments as ReturnType<typeof vi.fn>).mock.calls;
        const lastCall = calls[calls.length - 1];
        expect(lastCall[0]).toBeNull();
    });

    it('renders multiple assignment rows with correct data-testid', async () => {
        admin.roleAssignments = [
            { user_id: 1, user_name: 'Alice', username: 'alice', role_id: 1, role_name: 'dev', project_id: 1, project_name: 'Alpha' },
            { user_id: 2, user_name: 'Bob', username: 'bob', role_id: 2, role_name: 'reviewer', project_id: 1, project_name: 'Alpha' },
            { user_id: 3, user_name: 'Charlie', username: 'charlie', role_id: 3, role_name: 'admin', project_id: 2, project_name: 'Beta' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="assignment-row-0"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="assignment-row-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="assignment-row-2"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="assignment-row-3"]').exists()).toBe(false);
    });

    it('assignment row shows user name, username, role, and project', async () => {
        admin.roleAssignments = [
            { user_id: 1, user_name: 'Alice Wonderland', username: 'awonder', role_id: 1, role_name: 'senior-dev', project_id: 1, project_name: 'Vunnix Core' },
        ];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const row = wrapper.find('[data-testid="assignment-row-0"]');
        expect(row.text()).toContain('Alice Wonderland');
        expect(row.text()).toContain('@awonder');
        expect(row.text()).toContain('senior-dev');
        expect(row.text()).toContain('Vunnix Core');
    });

    it('hides assign button when form is showing', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [{ id: 1, project_id: 1, name: 'dev' }];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="assign-role-btn"]').exists()).toBe(true);

        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="assign-role-btn"]').exists()).toBe(false);
    });

    it('cancel button hides the assign form', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [{ id: 1, project_id: 1, name: 'dev' }];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="assign-role-form"]').exists()).toBe(true);

        const cancelBtn = wrapper.findAll('button').find(b => b.text() === 'Cancel');
        await cancelBtn!.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="assign-role-form"]').exists()).toBe(false);
        // Assign button should reappear
        expect(wrapper.find('[data-testid="assign-role-btn"]').exists()).toBe(true);
    });

    it('assign form populates user dropdown from store', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.users = [
            { id: 10, name: 'Alice', username: 'alice' },
            { id: 20, name: 'Bob', username: 'bob' },
            { id: 30, name: 'Charlie', username: 'charlie' },
        ];
        admin.roles = [{ id: 1, project_id: 1, name: 'dev' }];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const userOptions = wrapper.find('[data-testid="assign-user"]').findAll('option');
        expect(userOptions).toHaveLength(3);
        expect(userOptions[0].text()).toBe('Alice (alice)');
        expect(userOptions[1].text()).toBe('Bob (bob)');
        expect(userOptions[2].text()).toBe('Charlie (charlie)');
    });

    it('assign form populates project dropdown from store', async () => {
        admin.projects = [
            { id: 1, name: 'Alpha' },
            { id: 2, name: 'Beta' },
        ];
        admin.users = [{ id: 1, name: 'Alice', username: 'alice' }];
        admin.roles = [{ id: 1, project_id: 1, name: 'dev' }];

        const wrapper = mount(AdminRoleAssignments, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="assign-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const projectOptions = wrapper.find('[data-testid="assign-project"]').findAll('option');
        expect(projectOptions).toHaveLength(2);
        expect(projectOptions[0].text()).toBe('Alpha');
        expect(projectOptions[1].text()).toBe('Beta');
    });
});
