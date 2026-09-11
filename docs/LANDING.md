# Landing design collection

The home page opens the blue Nanites trading desk with the ShoeMoney AI V3.8 robot, pulsing coin endpoints, and real desk data. The thumbnail index remains at `/landing` and `/designs`; robot editions remain at `/singularity`. The existing operational dashboard lives at `/dashboard`, and all existing desk, chart, optimizer, and arena routes remain available. Home always uses real data, including when a demo query is supplied. Explicit preview routes continue to support labelled demo data.

The Picaso edition added five enhancements to each of the initial fifteen designs. Pulse Blue adds a separate branded interpretation of Pulse using the supplied ShoeMoney logo. The gallery's **Show design enhancements** control lists the features beside their previews; [PICASO.md](PICASO.md) contains the per-design manifest and interaction notes.

The signature branding rollout extends the original company logo to all sixteen pages and the gallery, with different surrounding geometry and light for each design. [BRAND-ROLLOUT.md](BRAND-ROLLOUT.md) tracks the implementation lanes, individual treatments, browser review, and thumbnail refresh.

| Route                     | Design                                                                                    |
| ------------------------- | ----------------------------------------------------------------------------------------- |
| `/landing/pulse`          | Orange signal rail, large P&L, connected market cards                                     |
| `/landing/neural`         | Blue AI network with animated paths to every pair                                         |
| `/landing/terminal`       | Green terminal, compact market table, price traces                                        |
| `/landing/orbit`          | Violet constellation centered on the strategy                                             |
| `/landing/prism`          | Black editorial layout, luminous lime/blue accents, market data wall                      |
| `/landing/supernova`      | Orange particle accelerator, dense market cards, signal pipes                             |
| `/landing/neon`           | Magenta/cyan holographic grid above an eighteen-market wall                               |
| `/landing/reactor`        | Amber industrial reactor, rotating torus, compact instruments                             |
| `/landing/liquid`         | Teal price field, moving particle waves, flowing market strips                            |
| `/landing/citadel`        | Isometric capital skyline with illuminated, instanced towers                              |
| `/landing/redline`        | Red racing timing board and a fast particle tunnel                                        |
| `/landing/synapse`        | Violet neural cloud, connected nodes, acid-green traces                                   |
| `/landing/spectrum`       | Black heatmap tiles with a luminous chromatic particle waveform                           |
| `/landing/horizon`        | Black-hole disk surrounded by two live market columns                                     |
| `/landing/overdrive`      | Blue command wall with eighteen dense cells and a signal fabric                           |
| `/landing/pulse-blue`     | Branded blue Pulse with the original ShoeMoney logo and cyan signal effects               |
| `/landing/horizon-blue`   | Event Horizon in electric and royal blue, with blue prices and a luminous ShoeMoney crest |
| `/landing/supernova-blue` | Supernova in azure and cyan, with blue particle eruptions, prices, and a plasma insignia  |

Use the index to compare all designs or filter to six signature layouts and twelve data-explosion designs. Cards open explicitly labelled demo previews. Every design links back to the index. Switching within either series preserves its data subscription. Append `?demo=1` for simulated presentation mode when opening a design directly. Demo values never replace missing live data automatically.

## Thumbnail index

`DesignIndex.vue` uses the catalog in `resources/js/landing/designCatalog.js`. The local JPEG thumbnails in `public/design-previews` are screenshots of the actual pages in demo mode, captured through computer use at a 1440 × 960 viewport and resized to 960 px wide. Lower cards load lazily. The index runs no live trading subscriptions or 3D canvases. If a design changes significantly, replace its corresponding thumbnail after reviewing the page in the browser.

Browser review checks distinct destinations, both series filters, return links, demo labels, desktop and mobile layouts, and thumbnail loading. Page titles are applied after mounting so navigation out of the index retains the selected design's title.

## GPU effects

The new series uses the installed Three.js package and WebGL shader rendering. It does not require WebGPU support. Each scene renders 4,400–8,200 background particles in one batched draw. Picaso adds a world-specific mesh sculpture, a bounded 1,296-particle impact pool responding to changed market observations, and an interactive focus lens. Animated paths connect every market in the active universe to the central AI, and incoming event throughput changes their activity. Citadel's instanced tower heights use each pair's deployed notional; other fields illustrate connections and are not price charts or individual executions.

The new demos show 18 markets, marks every 640 ms, and five simulated events every 160 ms (about 31 events/second). Their P&L respects position direction and reconciles to the individual market totals. The original demos retain ten markets and their slower event rate. Coin logos use the existing cryptocurrency asset mapping; UI symbols use the installed Font Awesome Pro set.

Effects respect reduced motion, the page's effects toggle, tab visibility, and whether the scene is on screen. Pixel ratio is capped at 1.5. Geometry, materials, WebGL contexts, resize observers, and input listeners are released when a scene is replaced or initialization fails. A static 2D field remains available if WebGL fails; all trading data stays in accessible Vue-rendered elements. Add `&renderer=2d` to an explicit demo URL to exercise that fallback. The originals now have distinct Canvas atmospheres and compact, interactive market instruments with the same visibility and motion handling.

The new layouts include pair search, position/profit filters, P&L/movement sorting, a live details dialog, an AI-only event filter, and independent event-feed pause/resume. Narrow layouts retain all pairs and move the 3D scene above the market grid. The event panel scrolls inside its own bounds.

## Data

- `GET /api/landing` is a public, read-only snapshot so the front page loads without an access token. It reads the configured universe plus **all** open and historical position markets. Position counts, fills, decisions, and P&L are scoped to the current paper/live mode. All application pages and desk API routes use the private network access boundary without a desk token. Live execution confirmation, request validation, and expensive-action throttling remain in place.
- P&L follows the application's current accounting: closed net P&L plus partial realisation plus side-aware open P&L, less fees and funding still attached to open quantities. Future exit costs are excluded. A short read transaction keeps open and closed totals consistent while positions close. Exposure is labelled separately from capital, and dated bank snapshots supply equity and cash without triggering trading or account-refresh operations.
- Marks come from the existing Redis feeder. Cached product/position marks are explicitly labelled. An unpriced open position makes aggregate open P&L unknown instead of manufacturing a zero.
- Snapshots refresh every three seconds with one bounded request in flight. Ten-second-old snapshots and request failures show a delayed state. Quotes independently lose live status at the feeder's 60-second freshness threshold.
- The existing Reverb optimizer channel streams backtest scores, scored rounds, and champion promotions after the first successful snapshot. Configure `VITE_REVERB_*` as for the Arena page, then rebuild. Snapshot history includes bounded desk events, fills and completed rounds; a shared event ledger deduplicates repeated polls and reconnects. The UI distinguishes desk-mode events from shared system/optimizer activity.
- The latest scan, per-pair decisions, pipeline heartbeats and bank freshness are exposed as actual recorded state. The model caption is user-supplied product copy; the repository does not implement a Qwen inference connection or claim a verified model health state.
- Sparklines in live mode contain values observed during the current browser session. No historical performance is fabricated.
- Event display pause buffers up to 80 events while P&L keeps updating. Motion controls and the system reduced-motion preference disable decorative animations. Subscriptions, request timeouts, listeners, and timers are cleaned up on navigation.

## Local preview

Build with `npm run build`, then serve the worktree with `php artisan serve`. Configure the worktree's `.env` for its own local database, or for the existing desk when live data is desired. The presentation mode does not need trading credentials or a running market feeder.

The design review used a separate local SQLite database and the explicit demo URLs. No live orders or trading settings were changed.

## Verification

```sh
php artisan test --compact --filter='LandingApiTest|QuoteApiTest|DeskApiTest'
node --test tests/Unit/Frontend/landing.test.mjs
npm run build
```

Computer-use review covered the original five designs and all ten additions at desktop and phone widths. The new series was visually inspected at 1440 px and checked at 390 px for 18 rendered pairs, one active canvas, no broken coin images, and no horizontal document overflow. Iterations corrected event-panel growth, search padding inherited from the app, the narrow Overdrive P&L, and improved the Citadel tower rendering. Browser warning/error logs remained clear through scene changes.

Interaction checks covered pair search, the details dialog and keyboard dismissal, event display pause/resume while P&L continued updating, and the effects toggle. The terminal's original table scrolls horizontally on narrow screens. The local desk has no live feeder; live mode is checked as an offline state, while animation and firehose review use explicit demo mode.

### Signature branding and final polish

All sixteen designs now share the exact supplied ShoeMoney logo, with a distinct animated treatment and blue illumination integrated into each palette. Picaso's final pass enlarged trading values, controls, labels, and event text; Prism and Spectrum now use black surfaces; all visible trailing wordmark X characters are blue. The gallery has matching branding, larger descriptions, and fresh previews for every design. See [the completed rollout workflow](BRAND-ROLLOUT.md) for ownership, verification, and renderer details.

### Blue cosmic editions

Designs 17 and 18 add separate blue versions of Event Horizon and Supernova. The original routes remain available. Both editions inherit their original family layout, instruments, targeting behavior, and effects, then use a dedicated blue palette for particles, logo geometry, prices, and P&L. The catalog keeps numbered order and includes fresh browser thumbnails. See [BLUE-EDITIONS.md](BLUE-EDITIONS.md) for the implementation and review record.
