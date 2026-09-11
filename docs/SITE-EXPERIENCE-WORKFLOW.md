# ShoeMoneyX site experience workflow

## Objective and acceptance

Bring every working application page to the winning Nanites front page's visual level: black titanium, electric blue, readable typography, recognizable ShoeMoney robot, authored 3D effects, and responsive real-data interaction. Preserve functioning controls and real data. Award candidacy is an aspiration, not a certification we can claim.

Scope: Dashboard, Chart (including symbol routes), Desk (including run routes), Positions and detail panels, Backtests (including results), Optimizer, Arena, Settings, plus verification that the existing front page and design collections remain intact.

For each page prove desktop and narrow layout, readable labels, actual robot/effect composition, useful hover/focus/selection, correct loading/empty/error states, no accidental action execution, and no root horizontal overflow. Verify shared navigation/footer and actual route transitions. Verify reduced motion, graphics fallback and renderer cleanup. Build/tests alone do not prove the visual result.

## Current work

Baseline: `9dc2cf0` on `codex/live-landing`, `/Users/shoemoney/Projects/shoemoneyx-live-landing`.

| Owner | Scope | Status |
| --- | --- | --- |
| Picaso / page identity | Shared cinematic robot PageHeading and Three.js choreography | Implemented and browser reviewed |
| Picaso / research theater | Optimizer, Arena and their visual instruments | Implemented and browser reviewed |
| Taytay / research controls | Settings and Backtests form/results experience | Implemented and browser reviewed |
| Root | Chart, Positions, Desk; integration; dashboard consistency; release | Released; final artwork delivery verified on staging |

## Implementation constraints

- Keep existing shared Laravel header/footer, navigation, API contracts and trading modes.
- Per the user's subsequent instruction, all pages and API routes are token-free within the private network. The retired middleware, boot checks, browser credential handling, configuration and watchdog header are removed. Input validation, live execution confirmation and expensive-action throttling remain. Staging verification uses no token or credential-injecting proxy.
- Decorative effects never invent account, strategy or connection data.
- Keep true black backgrounds, brand X blue, body/control text at least 14px, touch inputs 16px and usable targets.
- Artistic effects may be ambient. Data-triggered pulses must follow actual updates.
- Bound GPU work, pause hidden/offscreen scenes, dispose resources, and preserve a designed fallback.
- No trading, backtest-launch, promotion, settings-save or execution-mode mutation during visual checks.

## Implemented experience

The inner pages share a cinematic blue robot identity, page-specific orbits, pointer lighting, armor scanning, dimensional particles and pause controls. Most use the shared robot heading; Chart integrates its guardian inside the plot. The existing Laravel navigation and footer remain consistent with the front page. GPU work uses Three.js/WebGL, with an authored CSS fallback; this release does not claim WebGPU rendering. Hidden/offscreen effects stop, reduced motion is respected, and renderer resources are disposed on departure.

Positions has a selectable exposure chart and coin focus; Desk has an interactive decision pipeline; Backtests has a zoomable equity observatory and detailed evidence; Settings has searchable sections and bounded parameter rendering; Optimizer has interactive candidate plots, streamed evidence and compute telemetry; Arena uses the ShoeMoney robot and actual candidate comparisons. Dashboard inherits the same heading alongside its existing reactive instruments.

Chart now defaults to 1m and offers only 15s, 1m and 5m. The old history endpoint could perform up to twelve synchronous exchange downloads; the indicator endpoint could also download missing history. Both now read bounded local snapshots, capped at 2,000 bars. Five-minute candles derive from the live one-minute store. Feeders/backfill commands own ingestion, so unavailable history returns an honest empty response. Legacy API resolutions remain accepted for compatibility but are not advertised in the UI. One chart widget is reused across selections, and stale series/indicator responses are rejected.

The bundled chart library cannot calculate its built-in Volume study on second bars. The 15s view retains OHLCV candles and the separate SMX panel; the built-in Volume study is restored on 1m/5m. The adapter normalizes failed-history callbacks to the library's string error contract.

## Verification evidence — 2026-09-07

- Desktop and 390px layouts reviewed for all eight inner pages. Root horizontal overflow checks passed. Mobile parameter fields compute to 16px; small new labels have a 14px floor. Fixed a phone-only paused-heading scroll issue with `overflow: clip`.
- Exercised position focus/detail retrieval, Desk pipeline selection, saved backtest selection/zoom/reset, parameter search and reversible unsaved edits, optimizer coin selection/chart controls, and Arena market/direction changes. No live trading or settings mutations were used for QA.
- Repeated real chart selections 15s → 5m → ETH → 1m; the same iframe remained mounted. Verified actual candles, minute-volume restoration and SMX rendering, including a 390px 15s view. The previous 15s study exception no longer reproduced after the fix.
- Forced WebGL unavailability in the local preview: the heading used CSS, Arena retained the robot fallback and real evidence, and no canvas remained. Forced API failures showed explicit unavailable states instead of fabricated zeroes. Removed both test overrides afterward.
- Executable lifecycle checks cover reduced motion, offscreen/hidden rendering, context loss, initialization/render failures, cleanup, rapid chart selection, stale requests and unmount races.
- Isolated backend suite: 34 tests / 394 assertions passed, including chart aggregation/bounds, zero exchange downloads, token-free reads/mutations, request validation and retained throttling. Related agent checks covered 56 backend tests / 485 assertions. These overlapping suites must not be added together.
- Frontend suite: 29 tests passed, including the portable chart and renderer lifecycle regressions added in this release. Production Vite builds passed. The existing large Three.js/ECharts chunk warning remains; these features load in separate chunks.
- Direct staging reads on port 8818 returned real candles without a token. One LAN sample returned 15s history in 172ms, 1m in 55ms, 5m in 42ms and SMX in 70ms; these are observed samples, not a latency guarantee. Arena's websocket showed connected using a build generated on the deployment host.
- Existing Nanites front page, design index and ten-effect Singularity index were revisited successfully.

Screenshots are stored in `/Users/shoemoney/.codex/visualizations/2026/09/06/01a07454-de0c-7831-942f-81fe3dfb30f0/site-experience/`. Useful final artifacts include `optimizer-phone.png`, `arena-phone.png`, `backtests-result-phone.png`, `chart-phone-final.png` and `arena-graphics-fallback.png`.

## Final chart and delivery pass

`ae062f5` released the shared experience and bounded chart reads. `38d6d98` added the Flare 50 chart observatory: a holographic robot inside the candle plot, eye lighting that follows the chart cursor, dimensional particles, live quote pulses and observed session traces, quick coin selection, and a focus mode that preserves the chart iframe. Feed volume is labeled in coin units; the product-summary fallback retains its USD units. The effects toggle, mobile layout, rapid selections and fallback were reviewed in the browser before release.

The final visual audit revisited real desktop data on Dashboard, Desk, Positions, saved Backtests, Settings, Optimizer and Arena. Exercised dashboard time/capital views, Desk pipeline selection, position search and detail-panel dismissal, saved equity zoom/reset, parameter search, optimizer candidate focus and Arena direction selection. No trading or configuration actions were submitted.

Robot artwork now uses exact lossless WebP, preserving all decoded RGBA pixels and transparency. The armor image is 784,288 bytes and the typing image is 841,312 bytes; both are less than half their PNG originals. PNGs remain available for already-open older bundles. The shared Laravel layout preloads the route's main robot, and heading/chart portraits receive high fetch priority. Exact decoded-image SHA-256 equality was verified for each PNG/WebP pair, including the artwork sampled by the particle renderer.

Final staging review used port 8819 and checked all eight inner pages at 390px. Every robot loaded successfully with no root horizontal overflow. The front-page particle assembly, coin-directed eye beam, typing hands and keyboard remained intact. The 18-design gallery and ten-effect Singularity index still rendered and linked correctly. Final artifacts include `final-*-phone.png` and `final-frontpage-robot.png` in the screenshot folder above.

The final frontend suite passed 33 tests. The isolated SQLite SiteShell suite passed 4 tests / 110 assertions after the preload change. The deployment-host Vite build passed with the previously noted large-chunk warning. The artwork-format change does not alter the already-tested renderer lifecycle, API contracts or trading behavior.

## Release procedure

Fast-forward the repository's `master` branch from the reviewed worktree. Build assets on the deployment host so its public websocket configuration is compiled correctly. Copy hashed assets while retaining old files for already-open browsers, replace the manifest atomically, clear Laravel route/config/view caches, and restart only `shoemoneyx-serve`. Verify the live site at `http://<lan-host>:8811/`, token-free API reads, the three chart intervals and the connected event stream. Trading, feeder and research workers do not need a restart for this change.
