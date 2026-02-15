<script setup>
import { ref, onMounted, computed } from 'vue';
import { useAdminStore } from '@/stores/admin';

const admin = useAdminStore();
const actionError = ref(null);
const showCreateForm = ref(false);

// Create form state
const newRole = ref({ project_id: null, name: '', description: '', permissions: [] });

// Edit state
const editingRole = ref(null);
const editForm = ref({ name: '', description: '', permissions: [] });

const projectOptions = computed(() => admin.projects);

const permissionsByGroup = computed(() => {
    const groups = {};
    for (const p of admin.permissions) {
        const group = p.group || 'other';
        if (!groups[group]) groups[group] = [];
        groups[group].push(p);
    }
    return groups;
});

onMounted(() => {
    admin.fetchRoles();
    admin.fetchPermissions();
    if (admin.projects.length === 0) admin.fetchProjects();
});

function startCreate() {
    newRole.value = { project_id: projectOptions.value[0]?.id || null, name: '', description: '', permissions: [] };
    showCreateForm.value = true;
    actionError.value = null;
}

async function submitCreate() {
    actionError.value = null;
    const result = await admin.createRole(newRole.value);
    if (!result.success) {
        actionError.value = result.error;
        return;
    }
    showCreateForm.value = false;
}

function startEdit(role) {
    editingRole.value = role.id;
    editForm.value = {
        name: role.name,
        description: role.description || '',
        permissions: [...role.permissions],
    };
    actionError.value = null;
}

async function submitEdit(roleId) {
    actionError.value = null;
    const result = await admin.updateRole(roleId, editForm.value);
    if (!result.success) {
        actionError.value = result.error;
        return;
    }
    editingRole.value = null;
}

function cancelEdit() {
    editingRole.value = null;
}

async function handleDelete(role) {
    if (!confirm(`Delete role "${role.name}"? This cannot be undone.`)) return;
    actionError.value = null;
    const result = await admin.deleteRole(role.id);
    if (!result.success) {
        actionError.value = result.error;
    }
}

function togglePermission(list, permName) {
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
      <h2 class="text-lg font-medium">Roles</h2>
      <button
        v-if="!showCreateForm"
        class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
        data-testid="create-role-btn"
        @click="startCreate"
      >
        Create Role
      </button>
    </div>

    <!-- Create form -->
    <div v-if="showCreateForm" class="mb-6 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-800 dark:bg-blue-900/10" data-testid="create-role-form">
      <h3 class="text-sm font-medium mb-3">New Role</h3>
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Project</label>
          <select v-model="newRole.project_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="create-role-project">
            <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Name</label>
          <input v-model="newRole.name" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="create-role-name" placeholder="e.g. developer, reviewer" />
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Description</label>
          <input v-model="newRole.description" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="create-role-description" placeholder="Optional description" />
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-2">Permissions</label>
          <div v-for="(perms, group) in permissionsByGroup" :key="group" class="mb-2">
            <div class="text-xs font-semibold text-zinc-500 uppercase mb-1">{{ group }}</div>
            <label v-for="p in perms" :key="p.name" class="flex items-center gap-2 text-sm py-0.5">
              <input
                type="checkbox"
                :checked="newRole.permissions.includes(p.name)"
                @change="togglePermission(newRole.permissions, p.name)"
                class="rounded"
              />
              <span>{{ p.name }}</span>
              <span class="text-xs text-zinc-400">{{ p.description }}</span>
            </label>
          </div>
        </div>
        <div class="flex gap-2">
          <button class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" data-testid="create-role-submit" @click="submitCreate">Create</button>
          <button class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300" @click="showCreateForm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="admin.rolesLoading" class="py-8 text-center text-zinc-500">
      Loading roles...
    </div>

    <!-- Empty state -->
    <div v-else-if="admin.roles.length === 0" class="py-8 text-center text-zinc-500">
      No roles defined. Create a role to get started.
    </div>

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
                <h3 class="text-sm font-medium">{{ role.name }}</h3>
                <span class="text-xs text-zinc-400">{{ role.project_name }}</span>
                <span v-if="role.is_default" class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">
                  Default
                </span>
              </div>
              <p v-if="role.description" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ role.description }}</p>
              <div class="mt-2 flex flex-wrap gap-1.5">
                <span v-for="perm in role.permissions" :key="perm" class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                  {{ perm }}
                </span>
                <span v-if="role.permissions.length === 0" class="text-xs text-zinc-400 italic">No permissions</span>
              </div>
              <p class="mt-1 text-xs text-zinc-400">{{ role.user_count }} user(s)</p>
            </div>
            <div class="ml-4 flex-shrink-0 flex gap-2">
              <button class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800" :data-testid="`edit-role-btn-${role.id}`" @click="startEdit(role)">Edit</button>
              <button class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20" :data-testid="`delete-role-btn-${role.id}`" @click="handleDelete(role)">Delete</button>
            </div>
          </div>
        </template>

        <!-- Edit mode -->
        <template v-else>
          <div class="space-y-3" :data-testid="`edit-role-form-${role.id}`">
            <div>
              <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Name</label>
              <input v-model="editForm.name" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
            </div>
            <div>
              <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Description</label>
              <input v-model="editForm.description" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
            </div>
            <div>
              <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-2">Permissions</label>
              <div v-for="(perms, group) in permissionsByGroup" :key="group" class="mb-2">
                <div class="text-xs font-semibold text-zinc-500 uppercase mb-1">{{ group }}</div>
                <label v-for="p in perms" :key="p.name" class="flex items-center gap-2 text-sm py-0.5">
                  <input
                    type="checkbox"
                    :checked="editForm.permissions.includes(p.name)"
                    @change="togglePermission(editForm.permissions, p.name)"
                    class="rounded"
                  />
                  <span>{{ p.name }}</span>
                </label>
              </div>
            </div>
            <div class="flex gap-2">
              <button class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" :data-testid="`save-role-btn-${role.id}`" @click="submitEdit(role.id)">Save</button>
              <button class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300" @click="cancelEdit">Cancel</button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
