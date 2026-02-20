<script setup lang="ts">
import type { AdminRole } from '@/types';
import { computed, onMounted, ref } from 'vue';
import { useAdminStore } from '@/features/admin';
import BaseBadge from './ui/BaseBadge.vue';
import BaseButton from './ui/BaseButton.vue';
import BaseEmptyState from './ui/BaseEmptyState.vue';

interface Permission {
    name: string;
    description?: string;
    group?: string;
}

interface NewRoleForm {
    project_id: number | null;
    name: string;
    description: string;
    permissions: string[];
}

interface EditRoleForm {
    name: string;
    description: string;
    permissions: string[];
}

const admin = useAdminStore();
const actionError = ref<string | null>(null);
const showCreateForm = ref(false);

// Create form state
const newRole = ref<NewRoleForm>({ project_id: null, name: '', description: '', permissions: [] });

// Edit state
const editingRole = ref<number | null>(null);
const editForm = ref<EditRoleForm>({ name: '', description: '', permissions: [] });

const projectOptions = computed(() => admin.projects);

const permissionsByGroup = computed(() => {
    const groups: Record<string, Permission[]> = {};
    for (const p of admin.permissions as Permission[]) {
        const group = p.group || 'other';
        if (!groups[group])
            groups[group] = [];
        groups[group].push(p);
    }
    return groups;
});

onMounted(() => {
    admin.fetchRoles();
    admin.fetchPermissions();
    if (admin.projects.length === 0)
        admin.fetchProjects();
});

function startCreate() {
    newRole.value = { project_id: projectOptions.value[0]?.id || null, name: '', description: '', permissions: [] };
    showCreateForm.value = true;
    actionError.value = null;
}

async function submitCreate() {
    actionError.value = null;
    const result = await admin.createRole(newRole.value as { name: string; project_id: number; description?: string; permissions?: string[] });
    if (!result.success) {
        actionError.value = result.error ?? null;
        return;
    }
    showCreateForm.value = false;
}

function startEdit(role: AdminRole) {
    editingRole.value = role.id;
    editForm.value = {
        name: role.name,
        description: role.description || '',
        permissions: [...role.permissions],
    };
    actionError.value = null;
}

async function submitEdit(roleId: number) {
    actionError.value = null;
    const result = await admin.updateRole(roleId, editForm.value);
    if (!result.success) {
        actionError.value = result.error ?? null;
        return;
    }
    editingRole.value = null;
}

function cancelEdit() {
    editingRole.value = null;
}

async function handleDelete(role: AdminRole) {
    if (!confirm(`Delete role "${role.name}"? This cannot be undone.`))
        return;
    actionError.value = null;
    const result = await admin.deleteRole(role.id);
    if (!result.success) {
        actionError.value = result.error ?? null;
    }
}

function togglePermission(list: string[], permName: string) {
    const idx = list.indexOf(permName);
    if (idx === -1) {
        list.push(permName);
    } else {
        list.splice(idx, 1);
    }
}
</script>

<template>
    <div>
        <!-- Error banner -->
        <div v-if="actionError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="role-action-error">
            {{ actionError }}
        </div>

        <!-- Header with create button -->
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-medium">
                Roles
            </h2>
            <BaseButton
                v-if="!showCreateForm"
                variant="primary"
                size="sm"
                data-testid="create-role-btn"
                @click="startCreate"
            >
                Create Role
            </BaseButton>
        </div>

        <!-- Create form -->
        <div v-if="showCreateForm" class="mb-6 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-800 dark:bg-blue-900/10" data-testid="create-role-form">
            <h3 class="text-sm font-medium mb-3">
                New Role
            </h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Project</label>
                    <select v-model="newRole.project_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="create-role-project">
                        <option v-for="p in projectOptions" :key="p.id" :value="p.id">
                            {{ p.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Name</label>
                    <input v-model="newRole.name" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="create-role-name" placeholder="e.g. developer, reviewer">
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Description</label>
                    <input v-model="newRole.description" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="create-role-description" placeholder="Optional description">
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-2">Permissions</label>
                    <div v-for="(perms, group) in permissionsByGroup" :key="group" class="mb-2">
                        <div class="text-xs font-semibold text-zinc-500 uppercase mb-1">
                            {{ group }}
                        </div>
                        <label v-for="p in perms" :key="p.name" class="flex items-center gap-2 text-sm py-0.5">
                            <input
                                type="checkbox"
                                :checked="newRole.permissions.includes(p.name)"
                                class="rounded"
                                @change="togglePermission(newRole.permissions, p.name)"
                            >
                            <span>{{ p.name }}</span>
                            <span class="text-xs text-zinc-400">{{ p.description }}</span>
                        </label>
                    </div>
                </div>
                <div class="flex gap-2">
                    <BaseButton variant="primary" size="sm" data-testid="create-role-submit" @click="submitCreate">
                        Create
                    </BaseButton>
                    <BaseButton variant="secondary" size="sm" @click="showCreateForm = false">
                        Cancel
                    </BaseButton>
                </div>
            </div>
        </div>

        <!-- Loading state -->
        <div v-if="admin.rolesLoading" class="py-8 text-center text-zinc-500">
            Loading roles...
        </div>

        <!-- Empty state -->
        <BaseEmptyState v-else-if="admin.roles.length === 0">
            <template #title>
                No roles defined
            </template>
            <template #description>
                Create a role to get started.
            </template>
        </BaseEmptyState>

        <!-- Role list -->
        <div v-else class="space-y-3">
            <div
                v-for="role in admin.roles"
                :key="role.id"
                class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
                :data-testid="`role-row-${role.id}`"
            >
                <!-- View mode -->
                <template v-if="editingRole !== role.id">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-3">
                                <h3 class="text-sm font-medium">
                                    {{ role.name }}
                                </h3>
                                <span class="text-xs text-zinc-400">{{ role.project_name }}</span>
                                <span v-if="role.is_default" class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">
                                    Default
                                </span>
                            </div>
                            <p v-if="role.description" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ role.description }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <BaseBadge v-for="perm in role.permissions" :key="perm" variant="neutral">
                                    {{ perm }}
                                </BaseBadge>
                                <span v-if="role.permissions.length === 0" class="text-xs text-zinc-400 italic">No permissions</span>
                            </div>
                            <p class="mt-1 text-xs text-zinc-400">
                                {{ role.user_count }} user(s)
                            </p>
                        </div>
                        <div class="ml-4 flex-shrink-0 flex gap-2">
                            <BaseButton variant="secondary" size="sm" :data-testid="`edit-role-btn-${role.id}`" @click="startEdit(role)">
                                Edit
                            </BaseButton>
                            <BaseButton variant="danger" size="sm" :data-testid="`delete-role-btn-${role.id}`" @click="handleDelete(role)">
                                Delete
                            </BaseButton>
                        </div>
                    </div>
                </template>

                <!-- Edit mode -->
                <template v-else>
                    <div class="space-y-3" :data-testid="`edit-role-form-${role.id}`">
                        <div>
                            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Name</label>
                            <input v-model="editForm.name" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Description</label>
                            <input v-model="editForm.description" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-2">Permissions</label>
                            <div v-for="(perms, group) in permissionsByGroup" :key="group" class="mb-2">
                                <div class="text-xs font-semibold text-zinc-500 uppercase mb-1">
                                    {{ group }}
                                </div>
                                <label v-for="p in perms" :key="p.name" class="flex items-center gap-2 text-sm py-0.5">
                                    <input
                                        type="checkbox"
                                        :checked="editForm.permissions.includes(p.name)"
                                        class="rounded"
                                        @change="togglePermission(editForm.permissions, p.name)"
                                    >
                                    <span>{{ p.name }}</span>
                                </label>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <BaseButton variant="primary" size="sm" :data-testid="`save-role-btn-${role.id}`" @click="submitEdit(role.id)">
                                Save
                            </BaseButton>
                            <BaseButton variant="secondary" size="sm" @click="cancelEdit">
                                Cancel
                            </BaseButton>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
