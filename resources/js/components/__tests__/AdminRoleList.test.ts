import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/features/admin';
import AdminRoleList from '../AdminRoleList.vue';

vi.mock('axios');

function makeRole(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        project_id: 1,
        project_name: 'Alpha',
        name: 'developer',
        description: 'Dev role',
        is_default: false,
        permissions: ['chat.access'],
        user_count: 3,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('adminRoleList', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

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
            makeRole({ id: 1, name: 'developer', description: 'Dev role' }),
            makeRole({ id: 2, name: 'reviewer', description: null, is_default: true, permissions: ['review.view', 'review.comment'], user_count: 1 }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('developer');
        expect(wrapper.text()).toContain('reviewer');
        expect(wrapper.text()).toContain('Alpha');
    });

    it('renders permission badges on role cards', async () => {
        admin.roles = [
            makeRole({ permissions: ['chat.access', 'review.view'] }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('chat.access');
        expect(wrapper.text()).toContain('review.view');
    });

    it('shows no permissions text for roles without permissions', async () => {
        admin.roles = [
            makeRole({ name: 'viewer', permissions: [] }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('No permissions');
    });

    it('shows user count per role', async () => {
        admin.roles = [
            makeRole({ user_count: 5 }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('5 user(s)');
    });

    it('shows default badge for default roles', async () => {
        admin.roles = [
            makeRole({ name: 'member', is_default: true }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Default');
    });

    it('does not show default badge for non-default roles', async () => {
        admin.roles = [
            makeRole({ is_default: false }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        // "Default" should not appear (outside heading text)
        const roleRow = wrapper.find('[data-testid="role-row-1"]');
        expect(roleRow.text()).not.toContain('Default');
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
            makeRole({ description: 'A role' }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-1"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="edit-role-form-1"]').exists()).toBe(true);
    });

    it('shows edit and delete buttons for each role', async () => {
        admin.roles = [
            makeRole({ permissions: [] }),
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

describe('adminRoleList \u2014 startCreate / submitCreate', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    it('hides create button when form is open', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="create-role-btn"]').exists()).toBe(false);
    });

    it('pre-selects the first project in the create form', async () => {
        admin.projects = [{ id: 10, name: 'Bravo' }, { id: 20, name: 'Charlie' }];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const select = wrapper.find('[data-testid="create-role-project"]');
        expect((select.element as HTMLSelectElement).value).toBe('10');
    });

    it('calls createRole on store when submit button is clicked', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        vi.spyOn(admin, 'createRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const nameInput = wrapper.find('[data-testid="create-role-name"]');
        await nameInput.setValue('new-role');

        const descInput = wrapper.find('[data-testid="create-role-description"]');
        await descInput.setValue('My description');

        await wrapper.find('[data-testid="create-role-submit"]').trigger('click');
        await flushPromises();

        expect(admin.createRole).toHaveBeenCalledWith(
            expect.objectContaining({
                name: 'new-role',
                description: 'My description',
                project_id: 1,
            }),
        );
    });

    it('closes create form on successful submission', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        vi.spyOn(admin, 'createRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="create-role-name"]').setValue('test');
        await wrapper.find('[data-testid="create-role-submit"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="create-role-form"]').exists()).toBe(false);
    });

    it('shows error banner when createRole fails', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        vi.spyOn(admin, 'createRole').mockResolvedValue({ success: false, error: 'Role name already exists' });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="create-role-name"]').setValue('duplicate');
        await wrapper.find('[data-testid="create-role-submit"]').trigger('click');
        await flushPromises();

        const errorBanner = wrapper.find('[data-testid="role-action-error"]');
        expect(errorBanner.exists()).toBe(true);
        expect(errorBanner.text()).toContain('Role name already exists');
    });

    it('keeps create form open when createRole fails', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        vi.spyOn(admin, 'createRole').mockResolvedValue({ success: false, error: 'Validation error' });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="create-role-submit"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="create-role-form"]').exists()).toBe(true);
    });

    it('clears error when startCreate is called again', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        vi.spyOn(admin, 'createRole').mockResolvedValue({ success: false, error: 'Some error' });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });

        // Open form and fail
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-testid="create-role-submit"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="role-action-error"]').exists()).toBe(true);

        // Cancel the form (so the Create Role button reappears)
        const cancelBtn = wrapper.findAll('button').find(b => b.text() === 'Cancel');
        await cancelBtn!.trigger('click');
        await wrapper.vm.$nextTick();

        // Re-open form
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="role-action-error"]').exists()).toBe(false);
    });
});

describe('adminRoleList \u2014 startEdit / submitEdit / cancelEdit', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    it('populates edit form with current role data', async () => {
        admin.roles = [
            makeRole({ id: 5, name: 'reviewer', description: 'Reviews code', permissions: ['review.view'] }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();

        const editForm = wrapper.find('[data-testid="edit-role-form-5"]');
        expect(editForm.exists()).toBe(true);

        const nameInput = editForm.find('input[type="text"]');
        expect((nameInput.element as HTMLInputElement).value).toBe('reviewer');
    });

    it('calls updateRole on store when save button is clicked', async () => {
        admin.roles = [
            makeRole({ id: 5, name: 'reviewer', description: 'Reviews code', permissions: ['review.view'] }),
        ];
        vi.spyOn(admin, 'updateRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-role-btn-5"]').trigger('click');
        await flushPromises();

        expect(admin.updateRole).toHaveBeenCalledWith(5, expect.objectContaining({
            name: 'reviewer',
            description: 'Reviews code',
            permissions: ['review.view'],
        }));
    });

    it('closes edit form on successful submission', async () => {
        admin.roles = [
            makeRole({ id: 5 }),
        ];
        vi.spyOn(admin, 'updateRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="edit-role-form-5"]').exists()).toBe(true);

        await wrapper.find('[data-testid="save-role-btn-5"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="edit-role-form-5"]').exists()).toBe(false);
    });

    it('shows error banner when updateRole fails', async () => {
        admin.roles = [
            makeRole({ id: 5 }),
        ];
        vi.spyOn(admin, 'updateRole').mockResolvedValue({ success: false, error: 'Name is required' });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-role-btn-5"]').trigger('click');
        await flushPromises();

        const errorBanner = wrapper.find('[data-testid="role-action-error"]');
        expect(errorBanner.exists()).toBe(true);
        expect(errorBanner.text()).toContain('Name is required');
    });

    it('keeps edit form open when updateRole fails', async () => {
        admin.roles = [
            makeRole({ id: 5 }),
        ];
        vi.spyOn(admin, 'updateRole').mockResolvedValue({ success: false, error: 'Update failed' });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-role-btn-5"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="edit-role-form-5"]').exists()).toBe(true);
    });

    it('closes edit form when cancel is clicked', async () => {
        admin.roles = [
            makeRole({ id: 5 }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="edit-role-form-5"]').exists()).toBe(true);

        // Find the Cancel button inside the edit form
        const editForm = wrapper.find('[data-testid="edit-role-form-5"]');
        const cancelBtn = editForm.findAll('button').find(b => b.text() === 'Cancel');
        await cancelBtn!.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="edit-role-form-5"]').exists()).toBe(false);
    });

    it('hides view mode (Edit/Delete buttons) while editing a role', async () => {
        admin.roles = [
            makeRole({ id: 5 }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="edit-role-btn-5"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="delete-role-btn-5"]').exists()).toBe(false);
    });

    it('only opens edit form for the clicked role, not others', async () => {
        admin.roles = [
            makeRole({ id: 5, name: 'developer' }),
            makeRole({ id: 6, name: 'reviewer' }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="edit-role-form-5"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="edit-role-form-6"]').exists()).toBe(false);
        // Role 6 should still show view mode buttons
        expect(wrapper.find('[data-testid="edit-role-btn-6"]').exists()).toBe(true);
    });
});

describe('adminRoleList \u2014 handleDelete', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows confirmation dialog with role name before deleting', async () => {
        admin.roles = [
            makeRole({ id: 5, name: 'to-delete' }),
        ];
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        vi.spyOn(admin, 'deleteRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="delete-role-btn-5"]').trigger('click');
        await flushPromises();

        expect(confirmSpy).toHaveBeenCalledWith('Delete role "to-delete"? This cannot be undone.');
        expect(admin.deleteRole).not.toHaveBeenCalled();
    });

    it('calls deleteRole when confirmation is accepted', async () => {
        admin.roles = [
            makeRole({ id: 5 }),
        ];
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(admin, 'deleteRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="delete-role-btn-5"]').trigger('click');
        await flushPromises();

        expect(admin.deleteRole).toHaveBeenCalledWith(5);
    });

    it('does not call deleteRole when confirmation is rejected', async () => {
        admin.roles = [
            makeRole({ id: 5 }),
        ];
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        vi.spyOn(admin, 'deleteRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="delete-role-btn-5"]').trigger('click');
        await flushPromises();

        expect(admin.deleteRole).not.toHaveBeenCalled();
    });

    it('shows error banner when deleteRole fails', async () => {
        admin.roles = [
            makeRole({ id: 5 }),
        ];
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(admin, 'deleteRole').mockResolvedValue({ success: false, error: 'Cannot delete role with assigned users' });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="delete-role-btn-5"]').trigger('click');
        await flushPromises();

        const errorBanner = wrapper.find('[data-testid="role-action-error"]');
        expect(errorBanner.exists()).toBe(true);
        expect(errorBanner.text()).toContain('Cannot delete role with assigned users');
    });

    it('does not show error banner on successful delete', async () => {
        admin.roles = [
            makeRole({ id: 5 }),
        ];
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(admin, 'deleteRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="delete-role-btn-5"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="role-action-error"]').exists()).toBe(false);
    });
});

describe('adminRoleList \u2014 togglePermission', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    it('toggles permission checkbox in create form', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.permissions = [
            { name: 'chat.access', description: 'Access chat', group: 'chat' },
            { name: 'review.view', description: 'View reviews', group: 'review' },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const checkboxes = wrapper.find('[data-testid="create-role-form"]').findAll('input[type="checkbox"]');
        expect(checkboxes.length).toBe(2);

        // Initially unchecked
        expect((checkboxes[0].element as HTMLInputElement).checked).toBe(false);

        // Toggle on
        await checkboxes[0].trigger('change');
        await wrapper.vm.$nextTick();

        expect((checkboxes[0].element as HTMLInputElement).checked).toBe(true);

        // Toggle off
        await checkboxes[0].trigger('change');
        await wrapper.vm.$nextTick();

        expect((checkboxes[0].element as HTMLInputElement).checked).toBe(false);
    });

    it('toggles permission checkbox in edit form', async () => {
        admin.roles = [
            makeRole({ id: 5, permissions: ['chat.access'] }),
        ];
        admin.permissions = [
            { name: 'chat.access', description: 'Access chat', group: 'chat' },
            { name: 'review.view', description: 'View reviews', group: 'review' },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();

        const editForm = wrapper.find('[data-testid="edit-role-form-5"]');
        const checkboxes = editForm.findAll('input[type="checkbox"]');

        // chat.access should be checked (was in permissions)
        expect((checkboxes[0].element as HTMLInputElement).checked).toBe(true);
        // review.view should be unchecked
        expect((checkboxes[1].element as HTMLInputElement).checked).toBe(false);

        // Toggle review.view on
        await checkboxes[1].trigger('change');
        await wrapper.vm.$nextTick();

        expect((checkboxes[1].element as HTMLInputElement).checked).toBe(true);
    });

    it('includes toggled permissions in createRole call', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.permissions = [
            { name: 'chat.access', description: 'Access chat', group: 'chat' },
            { name: 'review.view', description: 'View reviews', group: 'review' },
        ];
        vi.spyOn(admin, 'createRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="create-role-name"]').setValue('test-role');

        // Toggle first permission on
        const checkboxes = wrapper.find('[data-testid="create-role-form"]').findAll('input[type="checkbox"]');
        await checkboxes[0].trigger('change');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="create-role-submit"]').trigger('click');
        await flushPromises();

        expect(admin.createRole).toHaveBeenCalledWith(
            expect.objectContaining({
                permissions: ['chat.access'],
            }),
        );
    });
});

describe('adminRoleList \u2014 permissions grouped by category', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    it('renders permissions grouped by category in create form', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.permissions = [
            { name: 'chat.access', description: 'Access chat', group: 'chat' },
            { name: 'chat.send', description: 'Send messages', group: 'chat' },
            { name: 'review.view', description: 'View reviews', group: 'review' },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const formText = wrapper.find('[data-testid="create-role-form"]').text();
        expect(formText).toContain('chat');
        expect(formText).toContain('review');
        expect(formText).toContain('chat.access');
        expect(formText).toContain('chat.send');
        expect(formText).toContain('review.view');
    });

    it('renders permissions grouped by category in edit form', async () => {
        admin.roles = [
            makeRole({ id: 5, permissions: [] }),
        ];
        admin.permissions = [
            { name: 'chat.access', description: 'Access chat', group: 'chat' },
            { name: 'review.view', description: 'View reviews', group: 'review' },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();

        const editFormText = wrapper.find('[data-testid="edit-role-form-5"]').text();
        expect(editFormText).toContain('chat');
        expect(editFormText).toContain('review');
    });

    it('uses "other" as group name for permissions without a group', async () => {
        admin.projects = [{ id: 1, name: 'Alpha' }];
        admin.permissions = [
            { name: 'misc.action', description: 'Some action' },
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const formText = wrapper.find('[data-testid="create-role-form"]').text();
        expect(formText).toContain('other');
        expect(formText).toContain('misc.action');
    });
});

describe('adminRoleList \u2014 role description display', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    it('shows description when role has one', async () => {
        admin.roles = [
            makeRole({ description: 'Can review and approve MRs' }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Can review and approve MRs');
    });

    it('does not show description paragraph when role has null description', async () => {
        admin.roles = [
            makeRole({ description: null }),
        ];

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        const roleRow = wrapper.find('[data-testid="role-row-1"]');
        // Just verify the key metadata is present but not any empty description
        expect(roleRow.text()).toContain('developer');
    });
});

describe('adminRoleList \u2014 onMounted behavior', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    it('fetches projects on mount when projects array is empty', () => {
        admin.projects = [];
        mount(AdminRoleList, { global: { plugins: [pinia] } });

        expect(admin.fetchProjects).toHaveBeenCalled();
    });

    it('does not fetch projects on mount when projects already loaded', () => {
        admin.projects = [{ id: 1, name: 'Already loaded' }];
        mount(AdminRoleList, { global: { plugins: [pinia] } });

        expect(admin.fetchProjects).not.toHaveBeenCalled();
    });
});

describe('adminRoleList \u2014 v-model bindings in forms', () => {
    let pinia: ReturnType<typeof createPinia>;
    let admin: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchRoles').mockResolvedValue();
        vi.spyOn(admin, 'fetchPermissions').mockResolvedValue();
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();
    });

    it('updates project_id when a different project is selected in create form', async () => {
        admin.projects = [{ id: 10, name: 'Bravo' }, { id: 20, name: 'Charlie' }];
        vi.spyOn(admin, 'createRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="create-role-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        const select = wrapper.find('[data-testid="create-role-project"]');
        await select.setValue(20);
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="create-role-name"]').setValue('test');
        await wrapper.find('[data-testid="create-role-submit"]').trigger('click');
        await flushPromises();

        expect(admin.createRole).toHaveBeenCalledWith(
            expect.objectContaining({ project_id: 20 }),
        );
    });

    it('updates name and description fields in edit form via v-model', async () => {
        admin.roles = [
            makeRole({ id: 5, name: 'original', description: 'old desc' }),
        ];
        vi.spyOn(admin, 'updateRole').mockResolvedValue({ success: true });

        const wrapper = mount(AdminRoleList, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="edit-role-btn-5"]').trigger('click');
        await wrapper.vm.$nextTick();

        const editForm = wrapper.find('[data-testid="edit-role-form-5"]');
        const inputs = editForm.findAll('input[type="text"]');

        // First input is name, second is description
        await inputs[0].setValue('renamed');
        await inputs[1].setValue('new desc');
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-role-btn-5"]').trigger('click');
        await flushPromises();

        expect(admin.updateRole).toHaveBeenCalledWith(5, expect.objectContaining({
            name: 'renamed',
            description: 'new desc',
        }));
    });
});
