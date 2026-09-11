import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { publicDemo } from './demoMode.js';
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
