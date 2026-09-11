# The Singularity: ten robot editions

Create separate Event Horizon Blue editions for each of Picaso's ten proposed robot effects, and a dedicated screenshot index at `/singularity`. Keep the existing eighteen-design gallery intact and add an entry point to the new collection.

## Work lanes

- Picaso graphics: localized nanite assembly, plasma shoulder wakes, and magnetic particles; detect WebGPU and provide deliberate fallback paths.
- Picaso interaction: eye targeting, quote-fed reactor, hexagonal shield, chrome relighting, actual-data crown, gravity waves, and temporal echoes; compose the shared robot stage.
- Picaso gallery: catalog and dedicated black/blue index with ten preview cards.
- Coordinator: routes, existing Event Horizon integration, real data and motion wiring, browser review, screenshot previews, and final build.

## Editions

| Edition                     | Route                         | Interaction                                                 | Review                  |
| --------------------------- | ----------------------------- | ----------------------------------------------------------- | ----------------------- |
| 01 Singularity eye          | `/singularity/eye?demo=1`     | Pointer and focused market guide the blue lens and beam     | Desktop + phone checked |
| 02 Chest data reactor       | `/singularity/reactor?demo=1` | Received updates feed the chest emblem                      | Desktop + phone checked |
| 03 Nanite arrival           | `/singularity/nanites?demo=1` | Automatic assembly, head sweep and pulsing coin endpoints   | Desktop + phone checked |
| 04 Hexagonal shield         | `/singularity/shield?demo=1`  | Update impacts across a geometric shield                    | Desktop + phone checked |
| 05 Plasma shoulder wakes    | `/singularity/plasma?demo=1`  | Twin flows respond to received-message activity             | Desktop + phone checked |
| 06 Living chrome            | `/singularity/chrome?demo=1`  | Pointer lighting follows the robot's transparent silhouette | Desktop + phone checked |
| 07 Holographic market crown | `/singularity/crown?demo=1`   | Focused-pair price and P&L; quote opens inspection          | Desktop + phone checked |
| 08 Gravity wave             | `/singularity/gravity?demo=1` | Inspection or replay releases a wave                        | Desktop + phone checked |
| 09 Temporal armor echoes    | `/singularity/echoes?demo=1`  | Pointer movement leaves blue afterimages                    | Desktop + phone checked |
| 10 Magnetic star swarm      | `/singularity/swarm?demo=1`   | Magnetic flow follows pointer and market focus              | Desktop + phone checked |

## Integration

Each edition reuses Event Horizon Blue's market data source, black-hole field, performance panels, controls, and inspection dialog. The robot stays visible during market focus so its effect can respond. The original landing routes retain their existing behavior. Preview editions other than Nanites retain a replay control. Nanites now forms automatically and has no replay button or development tabs.

The robot is the existing transparent 2D asset. Visual effects are illustrative; displayed prices and P&L continue to come from the shared snapshot. Explicit demo links use simulated data. GPU and CSS animations must pause under the existing effects control, reduced motion, hidden tabs, and offscreen state, with resources disposed on route changes.

## Verification

- Production build passes. The five existing frontend data tests pass (deduplication, unknown values, all eighteen demo markets, event coverage, and mark-to-market direction). No backend behavior changed.
- Computer-use review at 1440×1100 and 390×844 covers all ten editions. Crown also checked at 320px; its prices fit without clipping. No horizontal page overflow observed.
- Native **WebGPU compute** observed in the browser for nanites, plasma, and swarm. WebGL observed for all three over the HTTP LAN address. The explicit `renderer=2d` path keeps all three usable with CSS and zero canvases. No frame-rate measurement is claimed.
- Checked pointer aiming, arrow-key robot controls, Enter-to-inspect, crown quote-to-dialog, replay, and effects pause. Pausing an active nanite entrance restores the original artwork immediately. On a phone, assembly waits until at least 20% of the robot stage enters view.
- Improved during review: fixed a reserved WGSL identifier that triggered fallback, padded particle bounds to avoid hard clipping, contained holographic quote typography, and increased the visibility of temporal afterimages.
- The dedicated index was checked at 1440px, 390px, and 320px. Search, empty results, motion control, all ten preview image loads, and edition links work. The original gallery retains eighteen cards and links to the new collection. Original Event Horizon Blue still uses its original robot component and single backdrop canvas.
- Preview images in `public/design-previews/singularity/` are 900×600 crops of actual browser screenshots with simulated data. The nanite preview captures the transition between its particle silhouette and original artwork.
- Reduced motion, hidden-tab suspension, device loss, and disposal have explicit code paths. The Picaso particle lane additionally exercised pause/resume/replay/abort lifecycle with a mocked device; OS reduced-motion and forced device-loss behavior were not independently emulated in the browser.

Local preview: `http://127.0.0.1:8018/singularity`.
LAN preview: `http://<lan-host>:8018/singularity` (server bound to `0.0.0.0:8018`).

## Nanite head movement

After formation, the nanite edition alone performs a gentle ten-second head sweep: left, center, right, center. Complementary masks split the original transparent artwork at the jaw, preserving a fixed torso, neck, and chest emblem. The blue eye glow follows the head. No new image asset or 3D model is used.

The loop waits 1.4 seconds after the actual assembly callback so the final particles can fade. Replay resets the pose and delay. Motion pause, offscreen and hidden states suspend the delay and animation; reduced motion keeps the head forward. The initial placeholder assembled state cannot start the movement before the first entrance.

Browser verification: observed both directions over the LAN WebGL renderer at desktop size; body transform stayed unchanged. Replay showed assembly, a stationary formed pose, then the moving head. Effects pause preserved an identical head transform across successive samples. Phone review at 390×844 confirmed entrance timing, visible head movement, and no horizontal overflow. The head sweep also ran with native WebGPU compute and the CSS fallback. Production build passes.

## Winning front page

Nanites is now the real-data home page at `/`. Its large market endpoint dots use each coin's original artwork with staggered blue halos, a gentle scale pulse and hover emphasis. Clicking a GPU coin opens that exact market. The CSS fallback displays the same icons and connections; all effects follow motion, visibility and reduced-motion controls.

Both home and the explicit Nanites preview display the supplied `ShoeMoney AI V3.8` title and Powered By description. Home has no design navigation, replay, renderer diagnostics or gallery link. The explicit preview continues to label simulated data. The gallery thumbnail now shows the final caption and coin ring.

The launch adds real snapshots, fills, bank context and strategy decisions; see [the front-page launch checks](FRONT-PAGE-LAUNCH.md).
