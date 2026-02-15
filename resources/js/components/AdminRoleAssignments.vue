<script setup>
import { ref, onMounted } from 'vue';
import { useAdminStore } from '@/stores/admin';

const admin = useAdminStore();
const actionError = ref(null);
const showAssignForm = ref(false);

const assignForm = ref({ user_id: null, role_id: null, project_id: null });

const filterProjectId = ref(null);

onMounted(() => {
    admin.fetchAssignments();
    admin.fetchUsers();
    if (admin.roles.length === 0) admin.fetchRoles();
    if (admin.projects.length === 0) admin.fetchProjects();
});

function rolesForProject(projectId) {
    return admin.roles.filter((r) => r.project_id === projectId);
}

function startAssign() {
    const firstProject = admin.projects[0];
    assignForm.value = {
        user_id: admin.users[0]?.id || null,
        role_id: rolesForProject(firstProject?.id)?.[0]?.id || null,
        project_id: firstProject?.id || null,
    };
    showAssignForm.value = true;
    actionError.value = null;
}

function onProjectChange() {
    const projectRoles = rolesForProject(assignForm.value.project_id);
    assignForm.value.role_id = projectRoles[0]?.id || null;
}

async function submitAssign() {
    actionError.value = null;
    const result = await admin.assignRole(assignForm.value);
    if (!result.success) {
        actionError.value = result.error;
        return;
    }
    showAssignForm.value = false;
    admin.fetchAssignments(filterProjectId.value);
    admin.fetchRoles(); // Refresh user counts
}

async function handleRevoke(assignment) {
    if (!confirm(`Revoke role "${assignment.role_name}" from ${assignment.user_name} on ${assignment.project_name}?`)) return;
    actionError.value = null;
    const result = await admin.revokeRole({
        user_id: assignment.user_id,
        role_id: assignment.role_id,
        project_id: assignment.project_id,
    });
    if (!result.success) {
        actionError.value = result.error;
        return;
    }
    admin.fetchAssignments(filterProjectId.value);
    admin.fetchRoles(); // Refresh user counts
}

function applyFilter() {
    admin.fetchAssignments(filterProjectId.value);
}
</script>

<template>
  <div>
    <!-- Error banner -->
    <div v-if="actionError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="assignment-action-error">
      {{ actionError }}
    </div>

    <!-- Header with assign button -->
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-medium">Role Assignments</h2>
      <button
        v-if="!showAssignForm"
        class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
        data-testid="assign-role-btn"
        @click="startAssign"
      >
        Assign Role
      </button>
    </div>

    <!-- Filter -->
    <div class="mb-4 flex items-center gap-2">
      <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Filter by project:</label>
      <select v-model="filterProjectId" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="filter-project" @change="applyFilter">
        <option :value="null">All projects</option>
        <option v-for="p in admin.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
    </div>

    <!-- Assign form -->
    <div v-if="showAssignForm" class="mb-6 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-800 dark:bg-blue-900/10" data-testid="assign-role-form">
      <h3 class="text-sm font-medium mb-3">Assign Role to User</h3>
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Project</label>
          <select v-model="assignForm.project_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="assign-project" @change="onProjectChange">
            <option v-for="p in admin.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">User</label>
          <select v-model="assignForm.user_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="assign-user">
            <option v-for="u in admin.users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.username }})</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Role</label>
          <select v-model="assignForm.role_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="assign-role">
            <option v-for="r in rolesForProject(assignForm.project_id)" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
          <p v-if="rolesForProject(assignForm.project_id).length === 0" class="mt-1 text-xs text-zinc-400">No roles defined for this project. Create one in the Roles tab first.</p>
        </div>
        <div class="flex gap-2">
          <button class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" data-testid="assign-submit" @click="submitAssign">Assign</button>
          <button class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300" @click="showAssignForm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-if="admin.roleAssignments.length === 0" class="py-8 text-center text-zinc-500">
      No role assignments found. Assign a role to a user to get started.
    </div>

    <!-- Assignment list -->
    <div v-else class="space-y-2">
      <div
        v-for="(assignment, i) in admin.roleAssignments"
        :key="i"
        class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700"
        :data-testid="`assignment-row-${i}`"
      >
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ assignment.user_name }}</span>
            <span class="text-xs text-zinc-400">@{{ assignment.username }}</span>
          </div>
          <div class="mt-0.5 flex items-center gap-2 text-xs text-zinc-500">
            <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 dark:bg-zinc-800">{{ assignment.role_name }}</span>
            <span>on</span>
            <span class="font-medium">{{ assignment.project_name }}</span>
          </div>
        </div>
        <button
          class="ml-4 rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20"
          :data-testid="`revoke-btn-${i}`"
          @click="handleRevoke(assignment)"
        >
          Revoke
        </button>
      </div>
    </div>
  </div>
</template>
