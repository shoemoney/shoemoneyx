# Design Jury — Round 2

**Models parsed:** 4 · **stop votes:** 0

## Deduped findings

### general

| Votes | Agree? | Item |
|---|---|---|
| 1 | REVIEW | Clamp 3D stage height to 340px (down from 440px) to pull the live book and fills table above the fold on 1000px viewports. |
| 1 | REVIEW | Standardize FILLS card into a strict `<table>` structure matching OPEN POSITIONS instead of flex divs to preserve strict column alignment across status shifts. |
| 1 | REVIEW | Add an inline mini-sparkline or 10-tick micro-bar array next to the live price strip showing immediate volatility direction. |
| 1 | REVIEW | Render explicit column headers in FILLS (`TIME`, `PRODUCT`, `SIDE`, `KIND`, `QTY`, `PRICE`, `FEE`, `STATUS`, `NOTE`) with `sticky top-0 bg-zinc-900/90 backdrop-blur`. |
| 1 | REVIEW | Display coin badge asset as an embossed circular coin medallion attached via metallic bracket rather than a flat black square chest plate. |
| 1 | REVIEW | Apply the exact stage reframe: camera (0.3,3.1,8.2), target (0.45,1.15,0), fov 43°, champion at 44–46% of stage height and centred near 69% width. |
| 1 | REVIEW | Raise the four lane ticks from 0.35 to about 0.5 opacity and keep them confined to the 8–38% width corridor so the empty challenger path remains legible. |
| 1 | REVIEW | Replace the chest plate's pure black with zinc-900, add a zinc-700 1px-equivalent rim and slightly round or bevel its corners so it reads as armour rather than a pasted badge. |
| 1 | REVIEW | Use tabular mono for TODAY counts and separate values from labels typographically: zinc-300 values, zinc-500 labels, one shared baseline. |
| 1 | REVIEW | Give the price value a stable ch-width or min-width and right alignment so differing BTC digit lengths never shift the arrow or surrounding layout. |
| 1 | REVIEW | Add two-tier digit formatting to FILLS qty and price columns: bold zinc-100 for significant digits, lighter zinc-500 for trailing precision. |
| 1 | REVIEW | Enforce 10px uppercase tracking-widest on the 'challengers today' and 'promotions today' line in the fight card header. |
| 1 | REVIEW | Change the empty state of OPEN POSITIONS to show 'flat · last exit [time] · [N] promotions today' inline, matching the captured screenshot. |
| 1 | REVIEW | Make the coin select and side toggle consistently use 10px uppercase text with tracking-widest. |
| 1 | REVIEW | Add a subtle amber glow border to the champion card on hover or when active, enhancing premium feel. |
| 1 | REVIEW | Reframe stage: camera (0.12, 3.2, 8.6), lookAt (0.9, 0.95, 0), fov 36; champion scale ×0.66 so the figure is 38–42% of stage height, right third, ≥12% crown headroom, feet ~18% from bottom. |
| 1 | REVIEW | Idle lane: GridHelper opacity 0.50 on the four left-third ticks, 0.28 on the rest; 10px zinc-600 HTML 'LANE' overlay at the ticks so empty left two-thirds reads as designed. |
| 1 | REVIEW | Chest plate: zinc-700 rounded rect, 1px #d97706 rim, BTC PNG at 70% inset, roughness ~0.4 — kill the black square. |
| 1 | REVIEW | Contain the ticker: 10px uppercase coin label left; price+arrow in a tabular well (min-w, bg-zinc-900, px-3 py-1 rounded-sm) so the 600ms wash clips to a cell. |
| 1 | REVIEW | Quiet the page h1 to text-[10px] uppercase tracking-widest text-zinc-500 + amber swords; keep coin select / long-short / CONNECTED as the only right chrome. |

### transitions

| Votes | Agree? | Item |
|---|---|---|
| 1 | REVIEW | Add a 150ms transform scale micro-rebound (`scale(1.03)` -> `scale(1.0)`) on the main price readout when a tick arrives. |
| 1 | REVIEW | Implement a directional sliding flash for fill row insertion: rejected rows flash a subtle `bg-red-950/40` fade, executed fills wipe with `bg-emerald-950/30`. |
| 1 | REVIEW | Animate the champion crown idle with a continuous slow 12s yaw rotation (`rotation.y += 0.005`) independent of the breathing scale tween. |
| 1 | REVIEW | Enforce smooth 200ms opacity cross-fades on stage HUD badge states (`ACTIVE`, `QUEUED`) when challenger counts change. |
| 1 | REVIEW | Apply a 600ms CSS `box-shadow: inset 0 0 12px` glow along with background-color cell flashes during tick updates. |
| 1 | REVIEW | Reduce camera idle drift from ±0.25 to ±0.15 units and use a slow ease-in-out path; the champion, not the viewport, should carry the idle state. |
| 1 | REVIEW | Keep the 2s breathing loop within scale 1.00–1.012 after resizing; 1.02 is conspicuous on the tall silhouette. |
| 1 | REVIEW | During the existing 600ms price pulse, animate a 1px sign-coloured inset rule from full opacity to zero while keeping the background wash restrained. |
| 1 | REVIEW | Add a 2px emerald/red inset-right edge to changed position cells for the same 600ms duration, restarting it on consecutive same-direction updates. |
| 1 | YES | Use 120ms ease-out for arrow appearance and a 700–1000ms linear fade to zinc-500; reduced-motion should swap colour and glyph instantly with no pulse. |
| 1 | REVIEW | Ensure price strip arrow fades to zinc-500 over 1s exactly, not instant; currently may hold color too long. |
| 1 | REVIEW | Increase cell wash opacity from 15% to 25% for both emerald and red to make flashes more noticeable on dark backgrounds. |
| 1 | REVIEW | Add a 200ms scale pulse (1.00 to 1.02) on the champion's crown on each new challenger entry before settlement. |
| 1 | REVIEW | Stagger the appearance of new FILLS rows with a 50ms delay per row on initial load to avoid a jarring batch render. |
| 1 | REVIEW | On side toggle, smoothly transition the active badge background color over 150ms using Tailwind's transition-colors. |
| 1 | YES | Clamp camera drift to ±0.08 and disable it under prefers-reduced-motion (tweens already instant). |
| 1 | REVIEW | Sync price arrow color fade to 600ms to match the cell wash; animate background-color only — no scale or translate on the ticker. |
| 1 | REVIEW | Restartable td wash: bg-emerald-500/20 or bg-red-500/20, 600ms to transparent, keyed per cell, background only. |
| 1 | REVIEW | FILLS insert highlight 200ms bg-zinc-800/70 on background only — no height animation, no layout jump. |
| 1 | YES | Keep 2s champion breathe (scale 1.00–1.02); freeze breathe when reduced-motion; do not tween camera when challengers spawn. |

### professional

| Votes | Agree? | Item |
|---|---|---|
| 1 | YES | Set two-tier digit contrast: significant values in `text-zinc-100 font-semibold`, trailing decimals in `text-zinc-600 font-normal`. |
| 1 | REVIEW | Integrate a subtle horizontal horizon gradient line at `Y = 0` in Three.js using a custom shader or thin fading plane to separate grid from sky void. |
| 1 | REVIEW | Add a muted 1px border separator (`border-zinc-800/60`) between segmented control pills and select triggers for tighter cockpit feel. |
| 1 | REVIEW | Include an explicit monospace latency indicator (`12ms`) inside the CONNECTED Reverb badge pill. |
| 1 | REVIEW | Format rejected notes in FILLS with an amber warning micro-pill rather than bare red text for clearer audit categorization. |
| 1 | REVIEW | Keep the champion crown fully inside a consistent 10–12% top safe area at every supported canvas aspect ratio, not only 890×440. |
| 1 | REVIEW | Separate helmet, crown and body using controlled amber values: #fbbf24 highlight, #d97706 body and a darker amber shadow, avoiding bloom or extra glow. |
| 1 | REVIEW | Make significant digits font-medium zinc-100 and precision tails zinc-600 across price, quantity, entry, mark and peak for stronger numerical hierarchy. |
| 1 | REVIEW | Align all fight-strip stacks to a shared label/value grid so CHAMPION, TRAIN / TEST and TODAY feel like one instrument header rather than three independent blocks. |
| 1 | REVIEW | Use a subtle zinc-800 top edge and soft contact shadow under the chest plate and champion feet; this adds material depth without introducing another colour or decorative effect. |
| 1 | REVIEW | Implement two-tier number formatting consistently: bold zinc-100 for integer and significant decimals, zinc-500 for trailing zeros/precision. |
| 1 | REVIEW | Add a thin amber accent line at the top of the fight card (border-t-2 border-amber-500/30) for visual hierarchy. |
| 1 | REVIEW | Replace the plain select element for coin with a custom dropdown styled like the side toggle: dark bg, zinc-500 text, amber hover. |
| 1 | REVIEW | In the FILLS rejected rows, add a small amber warning icon alongside the red left rule to differentiate from error states. |
| 1 | REVIEW | Use a softer shadow on the champion (diffuse, not hard) and a faint glow on the chest plate logo to improve 3D polish. |
| 1 | REVIEW | Inset the canvas: box-shadow inset 0 0 24px rgb(0 0 0 / 0.55) so WebGL meets card chrome. |
| 1 | REVIEW | All book theads: text-[10px] uppercase tracking-widest text-zinc-500, sticky, bg-zinc-900/80 backdrop-blur. |
| 1 | REVIEW | Two-tier digits: significant zinc-50 font-mono tabular-nums, fraction text-[10px] text-zinc-500, baseline-aligned, right-aligned. |
| 1 | REVIEW | Champion materials: body #d97706 roughness 0.55, helmet #fbbf24 roughness 0.3, crown torus metalness 0.75; tighter contact shadow so feet kiss the grid. |
| 1 | REVIEW | Segmented long/short and CONNECTED at h-7 border-zinc-700; active fills stay sky/red-500/20 — no glow, no extra accent. |

### top3_must

| Votes | Agree? | Item |
|---|---|---|
| 1 | REVIEW | Reframe Three.js camera to position `[1.2, 3.2, 8.4]`, target `[-0.2, 1.4, 0]`, FOV 36, champion scale ~35% height at `X = 1.85`. |
| 1 | REVIEW | Reduce 3D canvas height from 440px to 340px to bring the live book, ticker strip, and top positions above the 1000px fold. |
| 1 | REVIEW | Rebuild FILLS container as a true structured table matching OPEN POSITIONS column alignment with sticky headers. |
| 1 | REVIEW | Reframe and rescale the stage so the full champion occupies 44–46% height at 68–70% width with clear crown headroom. |
| 1 | YES | Make the empty challenger lane unmistakable by increasing tick contrast and defining it across the left 8–38% of the canvas. |
| 1 | REVIEW | Strengthen live-number hierarchy with medium-weight significant digits, zinc-600 precision tails and a brief sign-coloured inset edge on updates. |
| 1 | REVIEW | Fix stage camera and champion scaling: camera (0.0, 1.0, 5.3), target (0.0, 0.8, 0.0), fov 45, champion height ~40% of stage. |
| 1 | REVIEW | Add two-tier digit formatting to FILLS price and qty columns. |
| 1 | REVIEW | Increase cell wash opacity to 25% and ensure price strip arrow fades over 1s. |
| 1 | REVIEW | Camera (0.12, 3.2, 8.6) / lookAt (0.9, 0.95, 0) / fov 36 / scale ×0.66 — whole champion, ~40% height, right third, idle lane visible. |
| 1 | REVIEW | Ticker well + two-tier zinc-50/zinc-500 + 600ms td-only washes (arrow fade 600ms). |
| 1 | REVIEW | Chest plate as zinc armour with amber rim; left-lane ticks at 0.5 opacity. |

## Font suggestions

- **google/gemini-3.7-flash:** ui-monospace, 'JetBrains Mono', 'SF Mono', monospace for all data fields, labels, headers, and metrics. · pair: Inter / system sans-serif strictly for top-level navigation and modal dialogs.
  - `font-mono text-[10px] uppercase tracking-widest text-zinc-500 tabular-nums`
- **openai/gpt-5.6-sol:** Inter — compact, neutral UI shapes preserve dense terminal legibility at 10–12px · pair: JetBrains Mono for all prices, quantities, percentages, timestamps and identifiers
  - `UI 400/500, headings 600; numeric values 500 with tabular figures; labels 10px/600 uppercase at 0.12em tracking.`
- **deepseek/deepseek-v4-flash:** JetBrains Mono for all monospace numbers and identifiers; its tight spacing and clear tabular figures suit dense tables. · pair: Inter for body text (card headers, labels) — clean, neutral, works at 10px.
  - `font-mono: 400/500 weights, tracking-normal for numbers; font-sans: 400, tracking-widest for uppercase labels.`
- **x-ai/grok-4.6:** ui-monospace / JetBrains Mono — locked for every number, HUD, set line, and ticker · pair: system-ui / Inter for 10px uppercase labels only
  - `labels: text-[10px] uppercase tracking-widest text-zinc-500; nums: font-mono tabular-nums text-[11px] text-right; ticker: text-3xl font-bold tabular-nums tracking-tight text-zinc-100`

## Auto-agree implementation queue (by votes)

1. [transitions · 1×] Use 120ms ease-out for arrow appearance and a 700–1000ms linear fade to zinc-500; reduced-motion should swap colour and glyph instantly with no pulse.
2. [transitions · 1×] Clamp camera drift to ±0.08 and disable it under prefers-reduced-motion (tweens already instant).
3. [transitions · 1×] Keep 2s champion breathe (scale 1.00–1.02); freeze breathe when reduced-motion; do not tween camera when challengers spawn.
4. [professional · 1×] Set two-tier digit contrast: significant values in `text-zinc-100 font-semibold`, trailing decimals in `text-zinc-600 font-normal`.
5. [top3_must · 1×] Make the empty challenger lane unmistakable by increasing tick contrast and defining it across the left 8–38% of the canvas.

## Agent decisions

_Fill during implement step: which YES items you shipped, which you skipped and why._

## Stop condition

stop_votes=0 / n_parsed=4. Loop ends when stop_votes >= 3 OR agree queue is empty of shippable UI work.
