import { getEcho } from '@/composables/useEcho';
import { useDashboardStore } from '@/stores/dashboard';

/**
 * Composable for dashboard real-time subscriptions.
 * Subscribes to project-level Reverb channels:
 *   - project.{id}.activity — task status changes for the activity feed
 *   - metrics.{id} — aggregated metrics updates
 *
 * Usage:
 *   const { subscribe, unsubscribe } = useDashboardRealtime();
 *   onMounted(() => subscribe(authStore.projects));
 *   onUnmounted(() => unsubscribe());
 */
export function useDashboardRealtime() {
    let subscribedChannels = [];

    function subscribe(projects) {
        // Clean up previous subscriptions if re-subscribing
        if (subscribedChannels.length > 0) {
            unsubscribe();
        }

        if (!projects || projects.length === 0)
            return;

        const echo = getEcho();
        const store = useDashboardStore();

        for (const project of projects) {
            const activityChannel = `project.${project.id}.activity`;
            const metricsChannel = `metrics.${project.id}`;

            echo.private(activityChannel).listen('.task.status.changed', (event) => {
                store.addActivityItem(event);
            });

            echo.private(metricsChannel).listen('.metrics.updated', (event) => {
                store.addMetricsUpdate(event);
            });

            subscribedChannels.push(activityChannel, metricsChannel);
        }
    }

    function unsubscribe() {
        const echo = getEcho();
        for (const channel of subscribedChannels) {
            echo.leave(channel);
        }
        subscribedChannels = [];
    }

    return { subscribe, unsubscribe };
}
