import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import ChatPage from '@/pages/ChatPage.vue';
import DashboardPage from '@/pages/DashboardPage.vue';
import AdminPage from '@/pages/AdminPage.vue';

const routes = [
    { path: '/', redirect: '/chat' },
    { path: '/chat', name: 'chat', component: ChatPage },
    { path: '/dashboard', name: 'dashboard', component: DashboardPage },
    { path: '/admin', name: 'admin', component: AdminPage },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Auth guard — redirects unauthenticated users to GitLab OAuth.
// On first navigation, waits for fetchUser() to resolve before deciding.
// Subsequent navigations use the cached auth state.
router.beforeEach(async (to) => {
    const auth = useAuthStore();

    // If auth state is unknown (null), fetch user first
    if (auth.user === null && !auth.isLoading) {
        await auth.fetchUser();
    }

    // If guest after check, redirect to GitLab OAuth
    if (auth.isGuest) {
        auth.login();
        return false; // Abort navigation — full-page redirect happening
    }
});

export default router;
