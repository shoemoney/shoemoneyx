# Reactive dashboard

The dashboard extends the Nanites front page into a black and electric-blue command center. Existing API contracts and execution actions are preserved.

- Interactive ECharts history leads the page: equity, cash, and working capital; 24-hour, 72-hour, and seven-day windows; timestamp tooltips; accessible zoom controls and persistent zoom across refreshed snapshots.
- A bounded Three.js WebGL particle field, orbital robot core, pointer lighting, metric tilts, and snapshot-triggered sweeps provide the atmosphere. CSS decoration remains available without WebGL.
- Actual account values animate to their exact new values. Position P&L bars always include zero; bars and keyboard-operable coin cards share a focus panel. Search and full position details remain available.
- Account/position data refreshes every ten seconds; the filterable event stream refreshes every five seconds. Missing values remain unknown. Failed requests retain visibly labelled last-known data and recover on the next successful refresh.
- Effects can be paused, respect reduced-motion preferences, stop unnecessary rendering offscreen or in hidden tabs, and dispose of resources on unmount.

## Verification

Production builds and 25 frontend tests pass, including six new financial-display tests. Browser checks at 1440px and 390px covered chart measurements/windows/tooltips, zoom persistence/reset, keyboard coin focus, search, details, event filters, pause/resume, and route changes. Test-only preview scenarios verified interrupted-connection recovery, reduced motion, and unavailable-WebGL fallback while ECharts remained available.

All pages and API routes are token-free inside the private, firewalled network. The browser no longer reads a token from its URL or storage, and it does not send a desk-token header. Legacy token configuration, headers, and query parameters no longer gate access. Live execution still requires its explicit confirmation, input validation remains active, and expensive desk actions retain their rate limit. Regression checks cover these boundaries with isolated test data. No trading actions were executed on the running desk during testing.
