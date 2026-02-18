<script setup lang="ts">
import { onMounted, ref } from 'vue';
import AdminDeadLetterQueue from '@/components/AdminDeadLetterQueue.vue';
import AdminGlobalSettings from '@/components/AdminGlobalSettings.vue';
import AdminPrdTemplate from '@/components/AdminPrdTemplate.vue';
import AdminProjectConfig from '@/components/AdminProjectConfig.vue';
import AdminProjectList from '@/components/AdminProjectList.vue';
import AdminRoleAssignments from '@/components/AdminRoleAssignments.vue';
import AdminRoleList from '@/components/AdminRoleList.vue';
import BaseTabGroup from '@/components/ui/BaseTabGroup.vue';
import { useAdminStore } from '@/stores/admin';

interface ProjectRef {
    id: number;
    name: string;
}

const admin = useAdminStore();

const activeTab = ref('projects');
const configuringProject = ref<ProjectRef | null>(null);
const editingTemplate = ref<ProjectRef | null>(null);

const tabs = [
    { key: 'projects', label: 'Projects' },
    { key: 'roles', label: 'Roles' },
    { key: 'assignments', label: 'Assignments' },
    { key: 'settings', label: 'Settings' },
    { key: 'dlq', label: 'Dead Letter' },
];

onMounted(() => {
    admin.fetchProjects();
});
</script>

<template>
    <div class="max-w-[var(--width-content)] mx-auto">
        <!-- Page header -->
        <div class="px-6 lg:px-8 pt-6 pb-0">
            <h1 class="text-xl font-semibold mb-4">
                Admin
            </h1>
            <BaseTabGroup
                :tabs="tabs"
                :model-value="activeTab"
                @update:model-value="activeTab = $event"
            />
        </div>

        <!-- Tab content -->
        <div class="px-6 lg:px-8 py-6">
            <AdminPrdTemplate
                v-if="activeTab === 'projects' && editingTemplate"
                :project-id="editingTemplate.id"
                :project-name="editingTemplate.name"
                @back="editingTemplate = null"
            />
            <AdminProjectConfig
                v-else-if="activeTab === 'projects' && configuringProject"
                :project-id="configuringProject.id"
                :project-name="configuringProject.name"
                @back="configuringProject = null"
                @edit-template="editingTemplate = configuringProject"
            />
            <AdminProjectList v-else-if="activeTab === 'projects'" @configure="configuringProject = $event" />
            <AdminRoleList v-else-if="activeTab === 'roles'" />
            <AdminRoleAssignments v-else-if="activeTab === 'assignments'" />
            <AdminGlobalSettings v-else-if="activeTab === 'settings'" />
            <AdminDeadLetterQueue v-else-if="activeTab === 'dlq'" />
        </div>
    </div>
</template>
