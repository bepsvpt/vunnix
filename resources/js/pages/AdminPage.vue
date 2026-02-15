<script setup>
import { ref, onMounted } from 'vue';
import { useAdminStore } from '@/stores/admin';
import AdminProjectList from '@/components/AdminProjectList.vue';
import AdminRoleList from '@/components/AdminRoleList.vue';
import AdminRoleAssignments from '@/components/AdminRoleAssignments.vue';
import AdminGlobalSettings from '@/components/AdminGlobalSettings.vue';

const admin = useAdminStore();

const activeTab = ref('projects');

const tabs = [
    { key: 'projects', label: 'Projects' },
    { key: 'roles', label: 'Roles' },
    { key: 'assignments', label: 'Assignments' },
    { key: 'settings', label: 'Settings' },
];

onMounted(() => {
    admin.fetchProjects();
});
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold mb-6">Admin</h1>

    <!-- Tabs -->
    <div class="flex items-center gap-2 mb-6">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        :data-testid="`admin-tab-${tab.key}`"
        class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors"
        :class="activeTab === tab.key
          ? 'border-zinc-500 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300'
          : 'border-zinc-300 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Tab content -->
    <AdminProjectList v-if="activeTab === 'projects'" />
    <AdminRoleList v-else-if="activeTab === 'roles'" />
    <AdminRoleAssignments v-else-if="activeTab === 'assignments'" />
    <AdminGlobalSettings v-else-if="activeTab === 'settings'" />
  </div>
</template>
