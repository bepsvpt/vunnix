import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { readonly, ref } from 'vue';

// Pusher must be on window for Echo's reverb broadcaster
window.Pusher = Pusher;

let echoInstance: Echo<'reverb'> | null = null;

/** Reactive connection state — true when the WebSocket is connected. */
const connected = ref(false);

/** Pending promise resolved when next connected. */
let connectedResolve: (() => void) | null = null;
let connectedPromise: Promise<void> | null = null;

/** Callbacks to fire on reconnection (previous state was not 'initialized'). */
const reconnectCallbacks: Array<() => void> = [];

/**
 * Access the Pusher instance from Echo's connector.
 * Echo<'reverb'> wraps PusherConnector which stores the Pusher instance.
 */
function getPusher(echo: Echo<'reverb'>): Pusher {
    return (echo.connector as unknown as { pusher: Pusher }).pusher;
}

/**
 * Get the singleton Laravel Echo instance.
 * Reads Reverb connection config from VITE_REVERB_* env vars (set at build time).
 * Falls back to the current page hostname so WebSocket connects through
 * the same proxy/tunnel the browser used to load the page.
 */
export function getEcho(): Echo<'reverb'> {
    if (echoInstance) {
        return echoInstance;
    }

    const isTLS = import.meta.env.VITE_REVERB_SCHEME === 'https'
        || (typeof window !== 'undefined' && window.location.protocol === 'https:');

    echoInstance = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY || '',
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT) || (isTLS ? 443 : 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT) || 443,
        forceTLS: isTLS,
        enabledTransports: ['ws', 'wss'],
    });

    bindConnectionState(echoInstance);

    return echoInstance;
}

/**
 * Bind to Pusher connection state changes for reactive state tracking
 * and reconnection callback dispatch.
 */
function bindConnectionState(echo: Echo<'reverb'>): void {
    const pusher = getPusher(echo);

    // Handle state transitions
    pusher.connection.bind('state_change', (states: { previous: string; current: string }) => {
        connected.value = states.current === 'connected';

        if (states.current === 'connected') {
            // Resolve any waiters
            if (connectedResolve) {
                connectedResolve();
                connectedResolve = null;
                connectedPromise = null;
            }

            // Fire reconnect callbacks (skip initial connection from 'connecting')
            if (states.previous === 'unavailable' || states.previous === 'disconnected') {
                for (const cb of reconnectCallbacks) {
                    cb();
                }
            }
        }
    });

    // In case we bind after the connection is already established
    if (pusher.connection.state === 'connected') {
        connected.value = true;
    }
}

/**
 * Returns a promise that resolves when the Echo WebSocket is connected.
 * Resolves immediately if already connected.
 */
export function whenConnected(): Promise<void> {
    // Ensure Echo is initialized
    getEcho();

    if (connected.value) {
        return Promise.resolve();
    }

    if (!connectedPromise) {
        connectedPromise = new Promise<void>((resolve) => {
            connectedResolve = resolve;
        });
    }

    return connectedPromise;
}

/**
 * Register a callback to be called when Echo reconnects after a drop.
 * Returns an unsubscribe function.
 */
export function onReconnect(callback: () => void): () => void {
    reconnectCallbacks.push(callback);
    return () => {
        const index = reconnectCallbacks.indexOf(callback);
        if (index !== -1)
            reconnectCallbacks.splice(index, 1);
    };
}

/**
 * Reactive connection state for UI binding (e.g. connection indicator).
 */
export function useEchoState(): { connected: ReturnType<typeof readonly<typeof connected>> } {
    return { connected: readonly(connected) };
}

/**
 * Destroy the Echo instance (for cleanup/testing).
 */
export function destroyEcho(): void {
    if (echoInstance) {
        try {
            getPusher(echoInstance).disconnect();
        } catch {
            // Pusher not initialized or already disconnected
        }
    }
    echoInstance = null;
    connected.value = false;
    connectedResolve = null;
    connectedPromise = null;
    reconnectCallbacks.length = 0;
}
