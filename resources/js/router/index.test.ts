import { describe, expect, it } from 'vitest';
import router from './index';

describe('router', () => {
    it('uses history mode (no hash)', () => {
        // createWebHistory stores base as '' (root) — not hash-based
        expect(router.options.history.base).toBe('');
    });

    it('has four named routes: chat, chat-conversation, dashboard, admin', () => {
        const routeNames = router.getRoutes().map(r => r.name).filter(Boolean);
        expect(routeNames).toContain('chat');
        expect(routeNames).toContain('chat-conversation');
        expect(routeNames).toContain('dashboard');
        expect(routeNames).toContain('admin');
    });

    it('redirects / to /chat', () => {
        const rootOption = router.options.routes.find(r => r.path === '/');
        expect(rootOption!.redirect).toBe('/chat');
    });

    it('defines /chat/:id route for deep-linking conversations', () => {
        const route = router.options.routes.find(r => r.path === '/chat/:id');
        expect(route).toBeDefined();
        expect(route!.name).toBe('chat-conversation');
    });
});
