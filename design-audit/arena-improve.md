# Arena design-jury log

Panel: gpt-5.6-sol, gemini-3.7-flash, grok-4.6, kimi-k3, qwen3.8-max, glm-5.3-flash (vision, OpenRouter). Screenshots of the deployed page on wick:8811.

## Round 2 — 2026-09-05 13:40 CDT (judging the deployed round 1)

| juror | look & feel | matches original | numbers pop | stop |
|---|---|---|---|---|
| gpt-5.6-sol | 8 | yes | 6 | no |
| gemini-3.7-flash | 8 | yes | 7 | no |
| grok-4.6 | 7 | no | 5 | no |
| kimi-k3 | 7 | no | 6 | no |
| qwen3.8-max | 8 | yes | 6 | no |
| glm-5.3-flash | 8 | yes | 7 | no |

Stop votes 0/6. Mean look & feel 7.7, mean numbers pop 6.2.

### SHIP (≥2 votes or material)
- Price strip: trailing precision must read as a second tier at ship size (5). Glyph never rests on `→` after the first tick; hold the last ▲/▼ coloured 1 s then fade to zinc-500 (5).
- HIT flash blows the champion to cream-white; cap at an amber tint ≤200 ms plus a stagger (5).
- HUD active/queued/last: values zinc-100 tabular, LAST as an emerald/red chip, 600 ms wash on change (6).
- TODAY counters: digits zinc-100, promoted count amber, wash on increment, thousands grouping (5).
- Train/test values one size up, semibold, signs coloured (2).
- Coin emblem: the square black chest plate reads as a glitch; becomes a round medallion (6).
- Promotion: drop the stray torus halo and detached disc (2) — absorbed by the sprite rewrite.
- Canvas reads as a black void beyond the floor; extend the floor/fog and fade the grid to the horizon (4).
- Arena is the only page whose h1 carries an icon; "the live book" carries a flag no Dashboard section header has. Both icons removed (3).
- Sprite staging consensus: champion ≈60 % of the 440 px canvas, challengers ≈45 %; feet on the floor with a soft elliptical contact shadow; camera lowered toward eye level; champion right third, challengers from the lower-left lane with depth offsets; labels below feet; HIT = amber tint + recoil, MISS = desaturate/sink/fade, promotion = fall then grow; amber ground ring marks the title holder.

### SKIP (with reason)
- "Kill blue / long should be emerald" (3): sky-500 for long and red for short is the desk convention (Optimizer uses the same badge). Locked.
- "'the live book' should be uppercase" (1): Dashboard section headers are lowercase. Matches the original.
- "Right-align numeric columns" (2): they already are (`text-right` on every numeric th/td). Misread.
- "$ and thousands grouping on the price" (1): eight-significant-digit crypto prices; grouping fights sub-dollar coins.
- "Move HUD to top-right" (1): the lane runs on the floor, the HUD sits above it; glm voted keep.
- "Colour-only direction" (1): values are already signed.

## Coordination note from session shoemoneyx-37 — 2026-09-05 14:05 CDT

Two sessions are editing the Arena at once; cross-session messages to this session are refused, so this note is the channel.

- shoemoneyx-37 shipped 92bf2a3 (jury round 1), 9c64bcb (`GET /api/quote`: the ticker now reads the feeder's 1 s `ws:price:<coin>` Redis hash; `/api/products` is written hourly and must not be used for the strip), 02afc52 (jury round 2: 360 px stage, camera (0.12, 3.2, 8.6) → (-1.7, 0.95, 0) fov 36, champion ×0.62, zinc chest plate with amber rim, transparent badge, two-tier digits, ticker well, sticky arrow). All pushed to origin/master and built on wick. Pull before editing; the mid-round `priceDir→lastDir` and ticker-well collisions were the two of us in the same file.
- From this note on, shoemoneyx-37 does not touch `ArenaFight.vue`, `ArenaBook.vue`, `Arena.vue` or the Arena keyframes in `app.css`, and runs no further Arena jury rounds. They are yours, along with the sprite rewrite. shoemoneyx-37 keeps the farm, optimizers, board and desk. My round-2 dedupe log is `docs/design/arena-improve.md`.
- `public/arena/` (your sprite PNGs) made `/arena` a 404 on wick from 13:55: a real directory at the route path shadows the SPA route under the PHP dev server. shoemoneyx-37 is fixing the server side so directories no longer shadow routes; you can keep the path.

### Round 2 shipped (commits 02afc52, 4624e90, 83ea5e7 — deployed to wick 14:08 CDT)
Six photoreal warrior sprites (champion = owner likeness via jshoe-flux-v3, three steel challengers), contact shadows, amber ring, round medallions, eye-level camera at z 6.1 (champion ≈57 % of the 360 px stage), amber-tint HIT, strike/win poses, HUD chip + washes, two-tier price with sticky ▲/▼, semibold train/test, grouped TODAY with amber promoted count, icons removed, floor/fog widened, reduced-motion rules. Dropped: the peer session's boxed chest plate (superseded by the medallion).

## Round 3 — 2026-09-05 14:15 CDT (judging the deployed round 2, sprites live)

| juror | look & feel | Δ | numbers pop | Δ | warriors | reads real | stop |
|---|---|---|---|---|---|---|---|
| gpt-5.6-sol | 8 | ? | 8 | ? | 6 | no | no |
| gemini-3.7-flash | 9 | +1 | 9 | +1 | 9 | yes | **yes** |
| grok-4.6 | 7 | ? | 7 | ? | 5 | no | no |
| kimi-k3 | 8 | +1 | 8 | +1 | 7 | yes | **yes** |
| qwen3.8-max | 8 | +1 | 8 | +1 | 7 | yes | no |
| glm-5.3-flash | 9 | +1 | 8 | +1 | 8 | yes | no |

Stop votes 2/6. Means: look & feel 8.2 (was 7.7), numbers pop 8.0 (was 6.2), warriors 7.0. Four of six say the fighters read as real warriors on a real floor.

### SHIP
- Promotion handoff: falling and growing champions interpenetrate and two medallions show side by side (6). Sequence fall → single medallion → grow.
- HUD ACTIVE/QUEUED values one size up, zinc-100 semibold tabular (5).
- Contact shadows deeper, especially under challengers; shadows persist through the fade (4).
- Challengers on a farther baseline so their smaller size reads as depth, and a touch taller (3).
- Champion medallion smaller and matte (3); challenger hip coins occlude behind torsos, replaced by a small overhead medallion (3).

### SKIP (with reason)
- "HIT frame shows idle champion, no amber" (2, marked verify): the amber tint lasts 200 ms and the still was taken 0.7 s after impact; the tint and recoil are implemented and were confirmed by glm/kimi in the same round. Still-capture timing, not a defect.
- "Warm rim light on the champion" (1): sprites are unlit billboards with baked lighting; a floor glow would be a gradient, which the look forbids.
- "Grid dashes only on the left" (1, verify): they are the challenger lane ticks, deliberate.
- "Stage is a 3D pit vs flat cards" (1): the arena metaphor is locked.
- "Fill heads to zinc-100" (1): already zinc-50 semibold since round 2.

### Round 3 shipped (commit 8899323, deployed 14:29 CDT)
Promotion handoff (fall → single medallion → grow), HUD digits 13 px semibold, deeper contact shadows that fade with the fighter, challengers on a farther baseline at 2.05 units, matte 0.21 champion medallion, challenger overhead medallions.

## Round 4 — 2026-09-05 14:33 CDT (judging the deployed round 3) — final scheduled round

| juror | look & feel | numbers pop | warriors | reads real | stop |
|---|---|---|---|---|---|
| gpt-5.6-sol | 7 | 8 | 6 | no | no |
| gemini-3.7-flash | 9 | 9 | 9 | yes | **yes** |
| grok-4.6 | 8 | 7 | 5 | no | no |
| kimi-k3 | 8 | 8 | 7 | yes | no |
| qwen3.8-max | 9 | 8 | 8 | yes | no |
| glm-5.3-flash | 8 | 8 | 8 | yes | no |

Stop votes 1/6. Means: look & feel 8.2, numbers pop 8.0, warriors 7.2. Four of six say the fighters read as real warriors.

### Capture artifact, not a defect (6 of 6 flagged it)
Every juror called the "impact" frame an empty stage with dashed headers. That frame was shot by a script that navigated to `/` and then pushed `/arena` client-side, and captured before the champions API answered: blank coin selector, `—` train/test, `0 challengers`. A direct-navigation recapture (`r4-fight-impact.png`) shows three challengers at impact with the champion, ring, shadows and medallions all present. The only real thing in that frame is the sub-second first-load state, which the desk's other pages share.

### SHIP (commits 2e30a0f, 07b09b7 — deployed 14:41 CDT)
- Price glyph is blank until the first direction is known instead of resting on `→` (4).
- CHAMPION label follows the incoming champion through the handoff and the ring/shadow stay full size while only the body grows (3).
- Medallion closer to the head and matte (4); during the grow-in it rides the body height.

### SKIP (with reason)
- "Replace the coin medallion with a flat amber token" (2): the coin identifies which market the champion holds; the desk's coin icons are the same set. Matte treatment applied instead.
- "Warm rim / floor bounce light" (2): unlit sprites; any floor glow is a gradient.
- "Winner growth too fast to read" (2): the grow-in occupies 0.77 s of the 1.4 s handoff; the strip `r4b-promote-strip.jpg` shows four distinct beats.
- "LAST chip dash at rest" (2): `—` in a zinc badge is the desk's empty-value convention.
- "'6 promotions today' should be amber" (1): it is amber; misread.
- "Left-only floor dashes" (1): challenger lane ticks, deliberate.

## Loop complete
- Rounds run: 3 (rounds 2–4; round 1 was shipped earlier by another session).
- Final stop votes: 1/6 — terminated by the iteration cap, never reached the stop floor of 3. Round 3 was the high-water mark at 2/6.
- Score trend: look & feel 7.7 → 8.2 → 8.2; numbers pop 6.2 → 8.0 → 8.0; warriors — → 7.0 → 7.2.
- Outstanding nits (taste, not material): lighting grade between warm sprites and the matte floor; medallion still reads "sticker" to two jurors; the first-load empty stage could hold a placeholder champion.

## Round 5 — 2026-09-05 18:38 CDT (owner-requested extra round, judging the deployed round 4)

| juror | look & feel | numbers pop | warriors | reads real | stop |
|---|---|---|---|---|---|
| gpt-5.6-sol | 8 | 8 | 7 | yes | no |
| gemini-3.7-flash | 9 | 9 | 9 | yes | **yes** |
| grok-4.6 | 8 | 7 | 7 | yes | no |
| kimi-k3 | 9 | 9 | 8 | yes | **yes** |
| qwen3.8-max | 8 | 7 | 7 | yes | no |
| glm-5.3-flash | 9 | 8 | 8 | yes | **yes** |

**Stop votes 3/6 — stop floor reached.** Means: look & feel 8.5, numbers pop 8.0, warriors 7.7. Six of six say the fighters read as real warriors.

### The finding that was real, and not what it looked like
Three jurors saw an empty stage with dashed headers in the at-rest frame and a blank price glyph in every frame. The frame was a stalled first load, not a render bug: `/api/arena` took **20 s** on wick. Every backtests row is same-day (the table is a rolling day), so the today filter pruned nothing and each poll parsed the JSON params of ~800k rows — and the page polls it every 5 s. This is also what the owner hit at 18:19 ("I am not seeing anything").

### SHIP (commits 4b1d379 → 4ef0ce8 and the two migrations, deployed 18:58 CDT)
- Indexed VIRTUAL columns `opt_coin`, `opt_side`, `opt_window` on backtests, plus `created_at` in the index so the challengers-today count is index-only. Arena and optimizer-candidates queries use them. `/api/arena`: 20 s → 0.2 s. Migrations ran inplace on wick in 30 s and 48 s.
- Recent-candidates scan reads 400 rows instead of 2000 (30 pairs never needed more).
- Six distinct challenger slots (three depth lanes × two columns) so silhouettes and overhead coins never overlap at impact (3 votes).
- Grow-in starts at 30 % scale so the winner never reads as a miniature inside the ring (1 vote, cheap).

### SKIP (with reason)
- "Restore the price glyph after the first tick" (3, verify): it does; the live DOM shows ▼. The frames were captured while the 20 s API stalled the quote poll behind it. Fixed by the perf work above.
- "Render the champion in the zero-challenger rest state" (1): it is rendered; same stalled-load frame.
- Worded empty states for the stage and train/test (1): the desk's dashes are the convention; the stall that made them linger is fixed.
- Lighting grade / specular on the steel armour (2): baked into the sprites; taste.
- "QUEUED wash emerald on a decrement" (1, verify): the wash direction follows the sign of the change in code.

## Loop complete (second closing)
- Rounds run: 4 (rounds 2–5). Stop floor of 3 reached in round 5.
- Score trend: look & feel 7.7 → 8.2 → 8.2 → 8.5; numbers pop 6.2 → 8.0 → 8.0 → 8.0; warriors — → 7.0 → 7.2 → 7.7.
- The loop's most valuable output was not a design item: a 20-second API that made the page look empty.
