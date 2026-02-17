import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Pusher must be on window for Echo's reverb broadcaster
window.Pusher = Pusher;

let echoInstance: Echo<'reverb'> | null = null;

/**
 * Get the singleton Laravel Echo instance.
 * Reads Reverb connection config from window.__REVERB_CONFIG__
 * (injected by Blade layout) or falls back to defaults.
 */
export function getEcho(): Echo<'reverb'> {
    if (echoInstance) {
        return echoInstance;
    }

    const config = window.__REVERB_CONFIG__ || {};

    echoInstance = new Echo({
        broadcaster: 'reverb',
        key: config.key || import.meta.env.VITE_REVERB_APP_KEY || '',
        wsHost: config.host || import.meta.env.VITE_REVERB_HOST || 'localhost',
        wsPort: config.port || Number(import.meta.env.VITE_REVERB_PORT) || 8080,
        wssPort: config.port || Number(import.meta.env.VITE_REVERB_PORT) || 443,
        forceTLS: (config.scheme || import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return echoInstance;
}

/**
 * Destroy the Echo instance (for cleanup/testing).
 */
export function destroyEcho(): void {
    echoInstance = null;
}
