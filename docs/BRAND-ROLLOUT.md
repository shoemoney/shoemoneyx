# Picaso signature branding workflow

## Brief

Integrate the supplied ShoeMoney logo into all sixteen landing designs, with a distinct visual treatment in each. Preserve the recognizable blue artwork and each page's existing palette, market data, and dense composition. Extend the same identity to the gallery.

The new attachment is byte-identical to `public/brand/shoemoney-blue.png`. The transparent original remains unchanged; surrounding light, geometry, and animation create the flare. The user's follow-up specifically asks for natural blending and blue in the color schemes. Carry cyan and royal blue through secondary lighting and adjacent UI, while retaining each design's primary character. CSS blending may soften the black shield into dark scenes; the asset itself stays untouched and the final polish request converts every former light design to black.

## Work stages

- [x] Inventory all sixteen routes, existing layouts, and shared data hooks.
- [x] Verify the supplied asset and agree on the shared component contract.
- [x] Build the shared animated insignia with theme-specific materials.
- [x] Integrate the six signature layouts, including Pulse Blue.
- [x] Integrate the ten data-explosion layouts.
- [x] Brand the gallery and describe each signature effect.
- [x] Build and run the existing frontend data checks.
- [x] Review every design at desktop and narrow widths.
- [x] Exercise pointer/focus, motion pause, navigation, and pair details.
- [x] Refresh all sixteen gallery thumbnails from actual browser captures.
- [x] Verify gallery counts, filters, links, and image loading.
- [x] Record the completed review and commit the rollout.

## Implementation lanes

Picaso owns the reusable insignia, the six signature integrations, and the ten data-explosion integrations in separate file groups. The coordinating agent owns the gallery, workflow, browser review, thumbnails, and final integration. Only the coordinator builds and drives the browser.

`BrandInsignia.vue` accepts `theme`, `placement`, `motion`, and `pulse`. Theme controls surrounding art; placement controls composition; motion follows the existing page control; pulse follows the existing snapshot beat. It uses no additional GPU canvas. The logo must never cover financial figures or intercept navigation. The original logo stays blue on every design.

## Design ledger

| Design     | Signature treatment | Composition                  | Desktop | Narrow | Thumbnail |
| ---------- | ------------------- | ---------------------------- | ------- | ------ | --------- |
| Pulse      | Signal fins         | Amber signal-origin badge    | Pass    | Pass   | Refreshed |
| Neural     | Electric branches   | Network core insignia        | Pass    | Pass   | Refreshed |
| Terminal   | Scanning brackets   | Green command seal           | Pass    | Pass   | Refreshed |
| Orbit      | Satellite rings     | Orbital AI core              | Pass    | Pass   | Refreshed |
| Prism      | Folded glass        | Editorial emblem             | Pass    | Pass   | Refreshed |
| Supernova  | Solar shards        | Solar medallion              | Pass    | Pass   | Refreshed |
| Neon       | Hologram portals    | Holographic projector        | Pass    | Pass   | Refreshed |
| Reactor    | Turbine teeth       | Armored central seal         | Pass    | Pass   | Refreshed |
| Liquid     | Fluid ripples       | Floating glass insignia      | Pass    | Pass   | Refreshed |
| Citadel    | Architectural crest | Skyline crest                | Pass    | Pass   | Refreshed |
| Redline    | Velocity chevrons   | Tilted timing badge          | Pass    | Pass   | Refreshed |
| Synapse    | Axon web            | Suspended neural token       | Pass    | Pass   | Refreshed |
| Spectrum   | Chromatic prisms    | Midnight prism seal          | Pass    | Pass   | Refreshed |
| Horizon    | Lensing arcs        | Gravitational center         | Pass    | Pass   | Refreshed |
| Overdrive  | Circuit matrix      | Terminal insignia            | Pass    | Pass   | Refreshed |
| Pulse Blue | Shield shockwaves   | Signature blue signal origin | Pass    | Pass   | Refreshed |

## Review procedure

Open each route in explicit demo mode. Inspect the first screen at 1440 × 960 and 390 × 844, plus compact edge cases where needed. Check logo loading, readable P&L, all expected markets, control access, and absence of horizontal document overflow. Trigger logo/card interaction, inspect a pair, pause motion, and navigate between design families. Check browser errors. Verify a forced 2D renderer on an explosion design; the new identity must not depend on GPU availability. Record any verification limits honestly.

Capture each approved page at the same desktop size and save a 960-pixel-wide JPEG in `public/design-previews`. Refresh the thumbnail version in the catalog only after the captures are ready. Confirm both gallery filters and all sixteen destinations still work.

## Network preview

At the user's request, the preview server listens on `0.0.0.0:8018`. The current LAN address is `http://<lan-host>:8018/`; Pulse Blue is available at `/landing/pulse-blue?demo=1`. The LAN request returned HTTP 200 from the host. A separate physical computer has not been used for verification.

## Final polish fanout

The user expanded the final pass to larger typography, clearer colors, black backgrounds everywhere, and a consistently blue X in ShoeMoneyX. The initial logo compositions were reviewed on all sixteen pages at desktop and phone widths. This final pass supersedes those previews and requires fresh captures.

All three available Picaso agents work alongside the coordinating agent:

| Lane              | Ownership                                                            | Final-pass responsibility                                                                                   |
| ----------------- | -------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Signature layouts | Landing, original market components and atmosphere, landing styles   | Readable text across six layouts, black Prism, blue X, spacing and touch targets                            |
| Data explosion    | DataExplosion, market cards, P&L/focus instruments, explosion styles | Readable text across ten layouts, black Spectrum and Redline panels, blue X, larger cards                   |
| GPU and insignia  | BrandInsignia, design configurations and renderer audit              | Luminous Spectrum on black, dark insignia materials, lifecycle and fallback checks, independent brand audit |
| Coordinator       | Gallery, documentation, runtime and browser                          | Gallery readability, workflow, integration, visual checks, thumbnails, final delivery                       |

Skills applied: Picaso, frontend-design, interaction-design, and threejs-webgl. The installed renderer is Three.js r185 WebGL2; the intentional 2D fallback uses CSS. This pass does not claim a WebGPU renderer or measured frame rate.

Final acceptance checklist:

- [x] Every design uses black or very dark surfaces; former cream and pastel backgrounds are removed.
- [x] Primary trading prices and P&L are comfortably readable, with brighter secondary text.
- [x] Controls and meaningful labels are enlarged; layouts can grow vertically to preserve the information.
- [x] Every visible trailing X in the landing and gallery wordmarks is blue.
- [x] All sixteen final pages pass desktop and phone review; compact and intermediate widths receive targeted checks.
- [x] Logo hover/keyboard feedback, motion pause, pair inspection and route switching work.
- [x] The CSS fallback retains the brand and trading controls without a WebGL canvas.
- [x] All sixteen final thumbnails replace the previous versions, and gallery links/filters remain correct.
- [x] Build, frontend data tests, source review and browser error checks pass.

## Final browser findings and corrections

All sixteen layouts were viewed at 1440 × 960 and 390 × 844. Every route loaded its original logo, all ten signature markets or eighteen explosion markets, and one existing atmosphere canvas; no horizontal document overflow was found. Header and footer X colors resolved to `rgb(50, 185, 255)` throughout. Signature card prices resolved to 20px on desktop and 18px on phones; explosion prices resolved to 14px, with enlarged controls and secondary labels. Terminal and Orbit retain their specialized table/orbit presentations.

The visual pass caught three remaining cascade/composition problems and they were fixed: Redline's profitable-count text inherited dark ink from its former bright panel; Neon's message span kept a 7px child size despite its enlarged parent; and Synapse's curved stage clipped its heading. Redline secondary figures now have bright protected text, all explosion event messages resolve to 12px, and Synapse's title sits within the curved safe area. Liquid and the other P&L instruments retain their effects with subtle dark protection behind secondary copy.

Interaction checks passed for keyboard pair inspection and Escape/focus return in both page families, search, profitable filtering, pointer-reactive logo tilt, visible keyboard focus on the brand link, and independent motion/feed controls. While the event feed was paused its sixteen visible rows remained unchanged while P&L continued updating. Motion pause suppressed decorative CSS animations and hid pulse echoes; resuming restored effects. Neural and Orbit core spacing and market cards received additional desktop/phone review.

## Completed verification

- All thirty-two desktop/phone route checks passed, followed by a precise repeat against `documentElement.clientWidth`; every page's scroll width matched its available client width.
- Redline and Liquid also passed at 320px. Horizon passed at 1024px. Gallery passed at 1440, 1024, 390, and 320px, including the larger enhancement lists. A five-pixel decorative gallery overflow at intermediate width was corrected with horizontal clipping of the gallery's atmosphere.
- The forced CSS fallback (`/landing/spectrum?demo=1&renderer=2d`) retained both logos, all eighteen markets, dark detail dialogs, and working controls with zero canvases. Its mode chip was moved clear of telemetry and checked on desktop and phone.
- All sixteen thumbnails were refreshed from final browser captures, verified at 960 × 640, and loaded successfully with the new `signature-polish` cache version. Gallery counts were 16 / 6 / 10, all sixteen destinations retained explicit demo mode, and expanding enhancements exposed eighty entries.
- Browser warning/error checks returned no entries. The production build and all five existing frontend data tests passed. The build retains its existing large-chunk advisory. Diff and formatting checks passed. The source PNG remains byte-identical to the supplied attachment.

Verification limits: motion controls were exercised in-browser and system reduced-motion wiring was reviewed in code; the operating system preference itself was not changed. Renderer cleanup was reviewed and repeated route changes maintained the expected canvas count; no frame-rate or memory benchmark is claimed. Three.js uses WebGL2, with the CSS fallback described above. LAN HTTP requests succeeded from this host; a separate physical networked computer was not used.
