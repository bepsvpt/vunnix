import { createRouter, createWebHistory } from 'vue-router';
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

// Auth guard — redirects unauthenticated users to GitLab OAuth
// The auth store's `isAuthenticated` starts as `null` (unknown) until checked.
// T62 will implement the full auth check; for now, skip guard when auth is unknown.
router.beforeEach((to, from, next) => {
    // Placeholder — T62 will wire this to the auth Pinia store
    next();
});

export default router;
