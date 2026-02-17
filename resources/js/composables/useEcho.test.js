import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { destroyEcho, getEcho } from './useEcho';

// Use vi.hoisted() so mock variables survive vi.mock() hoisting
const { MockEcho } = vi.hoisted(() => {
    const mockPrivate = vi.fn().mockReturnValue({
        listen: vi.fn().mockReturnThis(),
        stopListening: vi.fn().mockReturnThis(),
    });
    const mockLeave = vi.fn();
    const MockEcho = vi.fn().mockImplementation(function () {
        this.private = mockPrivate;
        this.leave = mockLeave;
        this.connector = { pusher: { connection: { state: 'connected' } } };
    });
    return { MockEcho, mockPrivate, mockLeave };
});

vi.mock('pusher-js', () => {
    return { default: vi.fn() };
});

vi.mock('laravel-echo', () => ({ default: MockEcho }));

describe('useEcho', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        destroyEcho();
        window.__REVERB_CONFIG__ = {
            key: 'test-key',
            host: 'localhost',
            port: 8080,
            scheme: 'http',
        };
    });

    afterEach(() => {
        delete window.__REVERB_CONFIG__;
    });

    it('creates an Echo instance on first call', () => {
        const echo = getEcho();
        expect(echo).toBeDefined();
        expect(MockEcho).toHaveBeenCalledTimes(1);
    });

    it('returns the same instance on subsequent calls (singleton)', () => {
        const echo1 = getEcho();
        const echo2 = getEcho();
        expect(echo1).toBe(echo2);
        expect(MockEcho).toHaveBeenCalledTimes(1);
    });

    it('configures Echo with Reverb settings from window config', () => {
        getEcho();
        expect(MockEcho).toHaveBeenCalledWith(
            expect.objectContaining({
                broadcaster: 'reverb',
                key: 'test-key',
            }),
        );
    });

    it('destroyEcho resets singleton so next call creates a new instance', () => {
        getEcho();
        destroyEcho();
        getEcho();
        expect(MockEcho).toHaveBeenCalledTimes(2);
    });
});
