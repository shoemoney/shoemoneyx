# ShoeMoneyX / Nanites command deck

Design handoff from Picaso to Taytay. Scope: Laravel-owned shared header and footer, and a coherent visual pass across Dashboard, Chart, Desk, Positions, Backtests, Optimizer, Arena and Settings. Preserve the winning home robot composition and every existing data/control behavior.

## Visual direction

Carry the robot's black titanium armor and electric-blue eye light into the working application. The header is a machined instrument with a glowing insignia and a circuit rail; the content consists of readable instruments on true black; the footer is a low illuminated service rail. This should feel like entering the same machine after clicking Enter desk. The warm amber generic dashboard identity is replaced by blue branding, but warning/error/profit/loss colors continue to express their existing meanings.

The application currently uses a Vue SPA delivered by Laravel's app Blade view. The old 208px left navigation, emoji icons, amber X and 10px text are the main sources of visual inconsistency. Removing the old navigation frees width for the tables and charts. Keep the working page components and the current API, status provider, timers, chart widgets and route parameters.

## Surface and typography tokens

Use a scoped shell class such as .smx-app and a single shared stylesheet. Do not load the many landing edition styles onto the desk just to copy its palette. Reuse local fonts/assets already used by the homepage; system fallbacks are required.

| Token | Value | Application |
| --- | --- | --- |
| --smx-bg | #000000 | Body, main canvas, chart canvas |
| --smx-panel | #04090e | Cards and form panels |
| --smx-panel-raised | #07111b | Hovered instruments and nav selection |
| --smx-ink | #e4f4ff | Body text and metallic wordmark |
| --smx-muted | #a9bfd2 | Descriptions, timestamps, secondary data |
| --smx-faint | #87a0b5 | Tertiary metadata; never disabled-looking ordinary content |
| --smx-blue | #32b9ff | Brand X, active links, focus, primary controls |
| --smx-blue-bright | #9dddff | Large financial values and highlight edges |
| --smx-blue-deep | #1782ff | Circuit trace and atmospheric glow |
| --smx-line | #23465f | Card/control boundaries |
| --smx-positive | #75e0bc | Positive and successful states; keep existing meaning |
| --smx-negative | #ff8495 | Negative/error states |
| --smx-warning | #f1c27a | Warning/degraded states only |

Display/body font: Outfit, system sans-serif. Monospace: IBM Plex Mono or existing mono stack, tabular numerals. Wordmark 23–28px, weight 700, 0.02em tracking. Page title clamp(28px, 2.4vw, 38px), weight 600. Eyebrow/section headers 12px, 0.1em tracking; body and controls 14px minimum, 16px preferred for descriptions. Tables 13–14px with 1.5 line-height; visible labels never 9–10px. Metric values 26–32px desktop / 22–26px narrow. Do not enlarge numbers by clipping their decimals.

## Shared header design

Implement real Laravel Blade header/footer components outside #app, with one server-provided navigation source and a small router enhancement for internal links and active states. Keep Vue responsible for routed content and existing reactive state; bridge real status to the shared chrome without creating a second polling loop. Ordinary route changes must not remount the whole app or lose live subscriptions. Do not duplicate visible mastheads or footers.

Desktop header is two bands, not a sidebar:

1. Main masthead, approximately 82px high; 28px horizontal padding; black with a slight blue illumination under the insignia. Left: a 56px blue company insignia followed by SHOEMONEY<span class="smx-brand-x">X</span>. Middle: small TRADING INTELLIGENCE eyebrow and page context. Right: real mode/status summary plus a clear Live overview link. No fake connected badge or invented AI activity. Use the existing /status health when available, otherwise a neutral unavailable/loading state. On the home page use its actual source state rather than triggering a protected desk request only to decorate the header.
2. Navigation band, 50px high; nine links in order: Live overview, Dashboard, Chart, Desk, Positions, Backtests, Optimizer, Arena, Settings. Use installed Font Awesome icons, no emoji. Active route has a blue-tinted background, bright bottom rail and a small illuminated icon bracket. Chart/symbol, Desk/run and Backtests/id keep their parent tab selected. Links retain meaningful hrefs and current route uses aria-current="page".

Suggested available icon names (verify exports in existing fontawesome.js before adding): bolt, chart-line, chart-candlestick, microchip, layer-group, clock-rotate-left, chart-scatter, swords, sliders. Names are not permission to replace the installed icon library.

A 1px blue circuit line crosses the bottom; a broad soft comet traverses it slowly every 10–14s. It is decorative, not a live-data indicator. Keep text stationary. Header need not be sticky if it compromises Chart height; if sticky, preserve focus visibility and account for both bands.

Home integration: preserve Every market. One mind., source status and Enter desk as the Nanites hero's own content. The reusable global brand/navigation can replace the existing equivalent home brand/actions instead of stacking a second logo masthead above it. Do not add design-gallery tabs to live routes. All occurrences of the ending ShoeMoneyX X are electric blue.

Mobile header: 68px brand row, 40px insignia, 21px wordmark, a labeled Menu button with at least 44px target. On activation reveal the same nav as a two-column black instrument grid or stacked links. Escape closes; selecting a route closes; button exposes aria-expanded and aria-controls. Status wraps below wordmark or to a dedicated narrow row. No clipped nine-link horizontal strip as the only navigation. Ordinary links work without waiting for animation.

## Footer design

A real shared footer after the page content, with a minimum height of 72px and top circuit border. Left: SHOEMONEY blue X and current year, plus Trading intelligence. Center/right: existing mode, strategy and loop/ready state in concise labeled values. If this is only available in the authenticated desk, render a public neutral brand footer on home. Move all health details formerly trapped at the bottom of the sidebar into a coherent footer/status region; halted reason and failed checks must remain visible and readable, not hidden by a collapsed mobile menu. Error state never becomes a blue success dot.

Footer status chips use 12px labels and 14px values, with a 6px light beside actual state. Decorative blue trace and small geometric corners create the home-page family resemblance. Links to Dashboard and Settings can sit at the end. The footer is in normal flow, never an overlay covering the last table row. On phones it stacks with 16px gaps; long strategy/check names wrap. The live robot gallery and render notes do not belong here.

## Signature effects

These are composed into the shared chrome and the data panels; none should require a second WebGPU renderer on every route.

1. **Insignia energy chamber:** reuse the approved blue logo inside a 56px chamber with layered cyan halo, two orbital trace arcs, and a very slow glint. Use BrandInsignia's existing lifecycle/reduced-motion handling if Vue owns this node; Blade equivalent can use the same artwork and bounded CSS effects. Keep the art's black transparency/blending natural.
2. **Circuit rail:** one traveling 120px blue energy packet beneath the main header, ~12s circuit. Several static trace breaks/corner notches give it a fabricated metal structure rather than a plain gradient.
3. **Navigation ignition:** hover/focus reveals the blue bottom edge and a 200ms glow around its icon. The active tab has a steady illuminated bracket. No endless text movement.
4. **Nanite atmosphere:** a sparse fixed pattern of small blue points and angled circuit strokes behind the top 240px of content, fading to true black. Animate a single low-opacity background drift over 30–40s. No extra metric labels, simulated event numbers or random candles.
5. **Instrument lighting:** cards have an inset top-edge reflection and diagonal corner brackets. Hover/focus-within warms the blue border and lifts the edge by a shadow; cards containing controls do not tilt away from the pointer. Metric numbers receive a restrained blue bloom, never a blurry body-text shadow.
6. **Route arrival:** the new page title and leading instrument row enter through a 180–260ms opacity/6px vertical settle. Route changes remain immediate. Limit stagger to first six visible instruments, maximum 50ms each; data rows never reanimate continuously on polling.
7. **Real event accents:** preserve current flash-up/flash-down, Optimizer promotion and Arena event effects. Color them to the shared palette only where that does not destroy existing semantic distinctions. A new event may trigger a short edge pulse, but source disconnection must stop implying live activity.

Every decorative layer is aria-hidden and pointer-events:none, confined by isolation. Pause animated shared backgrounds when document.hidden. No continual framework rerenders for effects. No flashing/strobing. Reduced motion disables drift, rail travel, logo rotation, page transforms and pulses; leaves steady blue edges, static traces and full content/controls. No additional settings screen is necessary.

## Working pages

Add explicit stable layout classes to roots so page-specific fixes do not depend on fragile Tailwind selector strings. Suggested classes below are contracts, not mandatory file names. Use a small shared page-heading component or consistent markup where practical.

| Page | Heading treatment | Concrete content changes |
| --- | --- | --- |
| Dashboard / .smx-dashboard | Eyebrow COMMAND OVERVIEW; keep Dashboard h1 and actions | Six account metrics become large black instruments with blue values; P&L retains sign colors. Equity plot and agents panel gain the shared frame. Make agent age/status and last cycle readable. Preserve all cycle/risk/halt/resume controls and errors. Table/footer event feed use 13–14px rows. |
| Chart / .smx-chart | Compact MARKET INTELLIGENCE + Chart heading | Existing symbol and timeframe toolbar becomes a black instrument strip with blue active timeframe. TradingView uses its existing Dark mode with black pane/background overrides supported by installed library; no light canvas. Keep all intervals/fill marks/SmxPanel. Desktop position/SCAN-VET panel ~300px; on narrow layouts move it below chart. Chart canvas needs an explicit useful height (~520px desktop minimum, ~420px narrow) rather than collapsing under removed h-full sidebar shell. |
| Desk / .smx-desk | EXECUTION PIPELINE + Desk; preserve SCAN → VET → SIZE → FILLS copy | Cycle list becomes a framed black side instrument (~270px) with blue active rail, retaining status colors/counts. Main report metric row matches Dashboard. Candidate and fill tables horizontally scroll inside their own panels. At <=960px cycle history stacks above main and has bounded height (~240px), never consumes most of phone width. |
| Positions / .smx-positions | EXPOSURE & FILLS + Positions | Open/closed/fills are large blue active segmented controls. Main tables in named overflow containers with at least 13px text; product identity high contrast. Detail panel has illuminated header and retains close/fill/RISK data. Destructive close-position remains red; it does not become an attractive primary blue action. |
| Backtests / .smx-backtests | STRATEGY RESEARCH + Backtests | Backtest form/history panel ~320px desktop, black inputs, blue run action. Results metric instruments match Dashboard; return/drawdown remain semantic. At <=960px controls/history stack before results; fields keep visible labels and textarea. Eight metrics use 2/4/8 responsive columns without tiny text. |
| Optimizer / .smx-optimizer | CONTINUOUS RESEARCH + Optimizer | Make real connection/rate metadata and filters wrap without clipping. Firehose rows 12–13px minimum and more generous leading. Candidate-space/heatmap panels gain blue machined borders; preserve color categories. ECharts legend/axis/tooltip labels rise toward 12px, heatmap cell labels >=11px where space permits. Preserve all selectors, farm controls, champion voice, toast duration, candidate evidence and promotion values. |
| Arena / .smx-arena | STRATEGY ARENA + Arena | Arena.vue renders an add-seat form and a live scoreboard table (seat, strategy, status, equity, pnl, vs champion, drawdown, win rate, trades, open positions) — no fight/book canvas exists here. Frame the scoreboard card with cyan edge reflections and black background. Make equity/pnl/vs-champion values larger, keep the champion row badge readable. Table scrolls horizontally on phones. Retain red for drawdown/risk and existing meaningful promotion colors; blue is the shared structure. |
| Settings / .smx-settings | DESK CONFIGURATION + Settings | Group Mode, Strategy, Controls as three large readable panels. Inputs 16px on phones to prevent zoom. Parameter groups get strong 14px headers; parameter rows must reflow label then field/actions below on narrow widths rather than a fixed w-56 label crushing input. Brand and override emphasis blue; live mode and destructive actions stay red. Preserve every warning, save/reset handler and original values. |

## Shared component finishing

.card and .tile need a coherent replacement at the shell scope: 12px radius, 1px line, 18–22px padding, black base, subtle top reflection. Tables have 10–12px vertical cell padding, low-contrast horizontal rules and a blue row hover wash. Selected history items have a visible left accent. Controls use 40px minimum desktop height / 44px touch targets, 8px radius, black fields and explicit blue focus outline; textarea included. Badges use 11–12px minimum and never substitute color for existing text. Empty states retain their honest messages in a well-lit card rather than disappearing into gray.

Avoid a global blanket recolor of .text-amber-* because some existing uses are actual warnings. Replace branding/primary-action/override amber intentionally, retain warning semantics. Avoid !important font inflation for everything: apply a consistent floor in the application scope, then explicit classes for dense chart labels. Text should not be made larger without checking all overflow containers.

The chart widgets render outside ordinary DOM text styling; update options in LineChart/OptimizerScatter/OptimizerHeatmap/SmxPanel only where needed for real visibility. Never claim a canvas label is fixed based solely on a global CSS rule. Preserve up/down colors and series categories.

## Acceptance and browser review

Test each functional route at desktop and at 390px width. Root app must never horizontally overflow; wide tables get local scroll. Verify header active state on detail routes and browser back/forward, mobile menu keyboard behavior, current footer states, focus visibility, existing chart/tabs/filters/detail actions, no duplicated polling or listeners across navigation, and no console errors. Use existing live data read-only for observation; do not trigger run/halt/close/live-mode mutations as visual QA. Reduced-motion and graphics fallback should remain honest and readable. Snapshot the shared header+Dashboard, one dense table, Chart and the mobile menu/settings form. The parent owns browser QA and production rollout; this document records design intent, not claims that those checks have passed.
