import type { RouteLocationRaw, RouteRecordRaw } from 'vue-router';
import { createRouter, createWebHistory } from 'vue-router';
import AdminPage from '@/pages/AdminPage.vue';
import ChatPage from '@/pages/ChatPage.vue';
import DashboardPage from '@/pages/DashboardPage.vue';
import SignInPage from '@/pages/SignInPage.vue';
import { useAuthStore } from '@/stores/auth';

const routes: RouteRecordRaw[] = [
    { path: '/', redirect: '/chat' },
    { path: '/sign-in', name: 'sign-in', component: SignInPage },
    { path: '/chat', name: 'chat', component: ChatPage },
    { path: '/chat/:id', name: 'chat-conversation', component: ChatPage },
    { path: '/dashboard', name: 'dashboard', component: DashboardPage },
    { path: '/admin', name: 'admin', component: AdminPage },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Auth guard — redirects unauthenticated users to the sign-in page (D194).
// On first navigation, waits for fetchUser() to resolve before deciding.
// Subsequent navigations use the cached auth state.
router.beforeEach(async (to): Promise<RouteLocationRaw | boolean | void> => {
    const auth = useAuthStore();

    // If auth state is unknown (null), fetch user first
    if (auth.user === null && !auth.isLoading) {
        await auth.fetchUser();
    }

    // Guest: allow sign-in page, redirect everything else there
    if (auth.isGuest) {
        if (to.name === 'sign-in')
            return;
        return { name: 'sign-in' };
    }

    // Authenticated: redirect away from sign-in to chat
    if (to.name === 'sign-in') {
        return { name: 'chat' };
    }
});

export default router;
