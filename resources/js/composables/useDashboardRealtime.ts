import { getEcho, whenConnected } from '@/composables/useEcho';
import { useDashboardStore } from '@/stores/dashboard';

/**
 * Composable for dashboard real-time subscriptions.
 * Subscribes to project-level Reverb channels:
 *   - project.{id}.activity — task status changes for the activity feed
 *   - metrics.{id} — aggregated metrics updates
 *
 * Waits for the WebSocket connection to be established before subscribing
 * to prevent silent subscription failures.
 *
 * Usage:
 *   const { subscribe, unsubscribe } = useDashboardRealtime();
 *   onMounted(() => subscribe(authStore.projects));
 *   onUnmounted(() => unsubscribe());
 */
export function useDashboardRealtime(): { subscribe: (projects: { id: number }[]) => void; unsubscribe: () => void } {
    let subscribedChannels: string[] = [];

    function subscribe(projects: { id: number }[]): void {
        // Clean up previous subscriptions if re-subscribing
        if (subscribedChannels.length > 0) {
            unsubscribe();
        }

        if (!projects || projects.length === 0)
            return;

        const store = useDashboardStore();
        const channels: string[] = [];

        for (const project of projects) {
            channels.push(`project.${project.id}.activity`, `metrics.${project.id}`);
        }

        // Track channels immediately so unsubscribe works even if still connecting
        subscribedChannels = channels;

        // Wait for WebSocket connection before subscribing to private channels
        whenConnected().then(() => {
            // Guard: unsubscribe may have been called while waiting
            if (subscribedChannels.length === 0)
                return;

            const echo = getEcho();

            for (const project of projects) {
                const activityChannel = `project.${project.id}.activity`;
                const metricsChannel = `metrics.${project.id}`;

                echo.private(activityChannel).listen('.task.status.changed', (event: unknown) => {
                    store.addActivityItem(event as Parameters<typeof store.addActivityItem>[0]);
                });

                echo.private(metricsChannel).listen('.metrics.updated', (event: unknown) => {
                    store.addMetricsUpdate(event as Parameters<typeof store.addMetricsUpdate>[0]);
                });
            }
        });
    }

    function unsubscribe(): void {
        if (subscribedChannels.length === 0)
            return;

        const echo = getEcho();
        for (const channel of subscribedChannels) {
            echo.leave(channel);
        }
        subscribedChannels = [];
    }

    return { subscribe, unsubscribe };
}
