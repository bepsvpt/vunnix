import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useDashboardStore = defineStore('dashboard', () => {
    const activityFeed = ref([]);
    const metricsUpdates = ref([]);

    // Filter state for activity feed tabs (§5.3)
    const activeFilter = ref(null); // null = 'All', or: 'code_review', 'feature_dev', 'ui_adjustment', 'prd_creation'
    const projectFilter = ref(null); // null = all projects, or project_id

    const FEED_CAP = 200;

    /**
     * Add or update an activity feed item.
     * Deduplicates by task_id (updates in-place on status change).
     * Prepends new items (most recent first). Caps at FEED_CAP.
     */
    function addActivityItem(item) {
        const idx = activityFeed.value.findIndex((a) => a.task_id === item.task_id);
        if (idx !== -1) {
            activityFeed.value.splice(idx, 1);
        }
        activityFeed.value.unshift(item);
        if (activityFeed.value.length > FEED_CAP) {
            activityFeed.value = activityFeed.value.slice(0, FEED_CAP);
        }
    }

    /**
     * Add or update a metrics snapshot for a project.
     * Keeps one entry per project_id (latest wins).
     */
    function addMetricsUpdate(update) {
        const idx = metricsUpdates.value.findIndex((m) => m.project_id === update.project_id);
        if (idx !== -1) {
            metricsUpdates.value.splice(idx, 1, update);
        } else {
            metricsUpdates.value.push(update);
        }
    }

    /**
     * Filtered activity feed respecting activeFilter (type) and projectFilter.
     */
    const filteredFeed = computed(() => {
        let items = activityFeed.value;
        if (activeFilter.value) {
            items = items.filter((i) => i.type === activeFilter.value);
        }
        if (projectFilter.value) {
            items = items.filter((i) => i.project_id === projectFilter.value);
        }
        return items;
    });

    function $reset() {
        activityFeed.value = [];
        metricsUpdates.value = [];
        activeFilter.value = null;
        projectFilter.value = null;
    }

    return {
        activityFeed,
        metricsUpdates,
        activeFilter,
        projectFilter,
        filteredFeed,
        addActivityItem,
        addMetricsUpdate,
        $reset,
    };
});
