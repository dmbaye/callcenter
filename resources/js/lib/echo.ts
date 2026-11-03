import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

type ReverbEcho = Echo<'reverb'>;

let instance: ReverbEcho | null = null;

/**
 * Lazily creates the shared Reverb connection. Returns null when Reverb is not
 * configured (no VITE_REVERB_APP_KEY), so callers can fall back to polling.
 */
export function getEcho(): ReverbEcho | null {
    if (instance) {
        return instance;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!key) {
        return null;
    }

    (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

    const forceTLS =
        (import.meta.env.VITE_REVERB_SCHEME ??
            window.location.protocol.replace(':', '')) === 'https';
    const port = Number(
        import.meta.env.VITE_REVERB_PORT ?? (forceTLS ? 443 : 80),
    );

    instance = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost:
            (import.meta.env.VITE_REVERB_HOST as string | undefined) ||
            window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS,
        enabledTransports: ['ws', 'wss'],
    });

    return instance;
}
