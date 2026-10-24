import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { publicDemo } from './demoMode.js';
import { deskHeaders } from './api.js';
import { createDemoEcho } from './demoEcho.js';

window.Pusher = Pusher;

// Reverb on the desk host. The dashboard is served from the same box, so the websocket goes to the same hostname on
// the Reverb port; VITE_REVERB_* are baked in at build time from .env on that box.
const port = Number(import.meta.env.VITE_REVERB_PORT || 8812);
const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http';

export const echo = publicDemo ? createDemoEcho() : new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'unconfigured',
    wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    // Read the CSRF token when a channel is authorized, not once at construction: another tab's login rotates it.
    authorizer: (channel) => ({
        authorize: (socketId, callback) => {
            fetch('/broadcasting/auth', {
                method: 'POST',
                headers: deskHeaders({ Accept: 'application/json', 'Content-Type': 'application/json' }),
                body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`channel auth ${res.status}`))))
                .then((data) => callback(null, data))
                .catch((error) => callback(error, null));
        },
    }),
});

if (!publicDemo && !import.meta.env.VITE_REVERB_APP_KEY) echo.disconnect();

/** Connection state for the UI: 'connected' | 'connecting' | 'unavailable' | 'disconnected' … */
export function onConnectionState(cb) {
    if (publicDemo) { cb('demo'); return () => {}; }
    const conn = echo.connector?.pusher?.connection;
    if (!conn) return () => {};
    cb(conn.state);
    const handler = (s) => cb(s.current);
    conn.bind('state_change', handler);
    return () => conn.unbind('state_change', handler);
}
