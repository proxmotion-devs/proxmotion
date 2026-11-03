import { usePage } from '@inertiajs/vue3';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * The panel's WebSocket connection to Reverb (docs/adr/0036), created on first
 * use; null when Reverb is not configured, and pages poll instead.
 */
let instance: Echo<'reverb'> | null | undefined;

/** The XSRF-TOKEN cookie Laravel sets on every page, read when it is needed. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function echo(): Echo<'reverb'> | null {
    if (instance !== undefined) {
        return instance;
    }

    // Sent with the page rather than built in, so one image fits every installation.
    const settings =
        typeof window === 'undefined' ? null : usePage().props.reverb;

    if (!settings?.key) {
        instance = null;

        return instance;
    }

    // Unset parts are the panel's own: behind nginx, Reverb is at /app. The page's
    // port only fits the page's host and scheme.
    const pageScheme = window.location.protocol === 'http:' ? 'http' : 'https';
    const scheme = settings.scheme ?? pageScheme;
    const port =
        settings.port ??
        (settings.host === null && scheme === pageScheme && window.location.port
            ? Number(window.location.port)
            : scheme === 'https'
              ? 443
              : 80);

    instance = new Echo({
        broadcaster: 'reverb',
        key: settings.key,
        Pusher,
        wsHost: settings.host ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // The page has no CSRF meta tag; the cookie is read for every
        // authorization, so a session renewed since the page loaded still works.
        channelAuthorization: {
            customHandler: ({ socketId, channelName }, callback) => {
                fetch('/broadcasting/auth', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    body: JSON.stringify({
                        socket_id: socketId,
                        channel_name: channelName,
                    }),
                })
                    .then(async (response) => {
                        if (!response.ok) {
                            throw new Error(String(response.status));
                        }

                        callback(null, await response.json());
                    })
                    .catch((error: Error) => callback(error, null));
            },
        },
    });

    return instance;
}

/**
 * Calls `listener` with whether the socket is connected, now and on every
 * change; returns a function that stops listening.
 */
export function onConnection(
    connection: Echo<'reverb'>,
    listener: (connected: boolean) => void,
): () => void {
    const socket = connection.connector.pusher.connection;
    const handler = ({ current }: { current: string }) =>
        listener(current === 'connected');

    socket.bind('state_change', handler);
    listener(socket.state === 'connected');

    return () => socket.unbind('state_change', handler);
}
