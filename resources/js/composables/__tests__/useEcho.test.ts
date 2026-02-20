import type { Mock } from 'vitest';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { destroyEcho, getEcho, onReconnect, useEchoState, whenConnected } from '../useEcho';

// Pusher connection state change handler capture
let stateChangeHandler: ((states: { previous: string; current: string }) => void) | null = null;
let connectionState = 'initialized';

// Use vi.hoisted() so mock variables survive vi.mock() hoisting
const { MockEcho } = vi.hoisted(() => {
    const mockPrivate = vi.fn().mockReturnValue({
        listen: vi.fn().mockReturnThis(),
        stopListening: vi.fn().mockReturnThis(),
    });
    const mockLeave = vi.fn();
    const mockDisconnect = vi.fn();
    const MockEcho: Mock = vi.fn().mockImplementation(function (this: Record<string, unknown>) {
        this.private = mockPrivate;
        this.leave = mockLeave;
        this.connector = {
            pusher: {
                connection: {
                    get state() {
                        return connectionState;
                    },
                    bind: vi.fn((event: string, handler: (...args: unknown[]) => void) => {
                        if (event === 'state_change') {
                            stateChangeHandler = handler as typeof stateChangeHandler;
                        }
                    }),
                },
                disconnect: mockDisconnect,
            },
        };
    });
    return { MockEcho, mockPrivate, mockLeave, mockDisconnect };
});

vi.mock('pusher-js', () => {
    return { default: vi.fn() };
});

vi.mock('laravel-echo', () => ({ default: MockEcho }));

// Simulate Pusher state transition
function simulateStateChange(previous: string, current: string): void {
    connectionState = current;
    if (stateChangeHandler) {
        stateChangeHandler({ previous, current });
    }
}

function setupEnv(): void {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'test-key');
    vi.stubEnv('VITE_REVERB_HOST', 'localhost');
    vi.stubEnv('VITE_REVERB_PORT', '8080');
    vi.stubEnv('VITE_REVERB_SCHEME', 'http');
}

describe('useEcho', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        destroyEcho();
        stateChangeHandler = null;
        connectionState = 'initialized';
        setupEnv();
    });

    afterEach(() => {
        vi.unstubAllEnvs();
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

    it('configures Echo with Reverb settings from VITE env vars', () => {
        getEcho();
        expect(MockEcho).toHaveBeenCalledWith(
            expect.objectContaining({
                broadcaster: 'reverb',
                key: 'test-key',
                wsHost: 'localhost',
                wsPort: 8080,
                forceTLS: false,
            }),
        );
    });

    it('destroyEcho resets singleton so next call creates a new instance', () => {
        getEcho();
        destroyEcho();
        getEcho();
        expect(MockEcho).toHaveBeenCalledTimes(2);
    });

    it('binds to connection state_change on creation', () => {
        getEcho();
        expect(stateChangeHandler).not.toBeNull();
    });

    it('tracks connected state reactively', () => {
        getEcho();
        const { connected } = useEchoState();
        expect(connected.value).toBe(false);

        simulateStateChange('connecting', 'connected');
        expect(connected.value).toBe(true);

        simulateStateChange('connected', 'disconnected');
        expect(connected.value).toBe(false);
    });

    it('detects already-connected state on creation', () => {
        connectionState = 'connected';
        getEcho();
        const { connected } = useEchoState();
        expect(connected.value).toBe(true);
    });

    it('falls back to window.location.hostname when VITE_REVERB_HOST is unset', () => {
        vi.stubEnv('VITE_REVERB_HOST', '');
        getEcho();
        expect(MockEcho).toHaveBeenCalledWith(
            expect.objectContaining({
                wsHost: 'localhost', // jsdom default
            }),
        );
    });
});

describe('whenConnected', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        destroyEcho();
        stateChangeHandler = null;
        connectionState = 'initialized';
        setupEnv();
    });

    afterEach(() => {
        vi.unstubAllEnvs();
    });

    it('resolves immediately when already connected', async () => {
        connectionState = 'connected';
        getEcho();

        const resolved = await whenConnected();
        expect(resolved).toBeUndefined();
    });

    it('waits until connected state is reached', async () => {
        getEcho();
        let resolved = false;
        whenConnected().then(() => {
            resolved = true;
        });

        // Not resolved yet
        await Promise.resolve();
        expect(resolved).toBe(false);

        // Simulate connection
        simulateStateChange('connecting', 'connected');
        await Promise.resolve();
        expect(resolved).toBe(true);
    });

    it('initializes Echo if not yet created', async () => {
        connectionState = 'connected';
        await whenConnected();
        expect(MockEcho).toHaveBeenCalledTimes(1);
    });
});

describe('onReconnect', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        destroyEcho();
        stateChangeHandler = null;
        connectionState = 'initialized';
        setupEnv();
    });

    afterEach(() => {
        vi.unstubAllEnvs();
    });

    it('fires callback on reconnection from disconnected state', () => {
        getEcho();
        const callback = vi.fn();
        onReconnect(callback);

        // Initial connection — should NOT fire reconnect
        simulateStateChange('connecting', 'connected');
        expect(callback).not.toHaveBeenCalled();

        // Disconnect then reconnect — SHOULD fire
        simulateStateChange('connected', 'disconnected');
        simulateStateChange('disconnected', 'connected');
        expect(callback).toHaveBeenCalledTimes(1);
    });

    it('fires callback on reconnection from unavailable state', () => {
        getEcho();
        const callback = vi.fn();
        onReconnect(callback);

        simulateStateChange('connecting', 'connected');
        simulateStateChange('connected', 'unavailable');
        simulateStateChange('unavailable', 'connected');
        expect(callback).toHaveBeenCalledTimes(1);
    });

    it('returns unsubscribe function that prevents further calls', () => {
        getEcho();
        const callback = vi.fn();
        const unsub = onReconnect(callback);

        simulateStateChange('connecting', 'connected');
        simulateStateChange('connected', 'disconnected');

        unsub();

        simulateStateChange('disconnected', 'connected');
        expect(callback).not.toHaveBeenCalled();
    });

    it('cleans up all callbacks on destroyEcho', () => {
        getEcho();
        const callback = vi.fn();
        onReconnect(callback);

        destroyEcho();

        // Re-create and simulate reconnect
        getEcho();
        simulateStateChange('connecting', 'connected');
        simulateStateChange('connected', 'disconnected');
        simulateStateChange('disconnected', 'connected');
        expect(callback).not.toHaveBeenCalled();
    });
});
