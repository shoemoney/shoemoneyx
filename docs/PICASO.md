# Picaso edition

The initial fifteen designs each received five enhancements. The index includes a **Show design enhancements** control so the collection can be compared without opening every page. Pulse Blue is a subsequent branded edition of Pulse.

The company logo now becomes the signature across all sixteen designs. Each gets an individual insignia treatment, retaining the supplied blue artwork and adapting the surrounding motion to its existing visual world. The full [branding workflow and review ledger](BRAND-ROLLOUT.md) records this additional pass. The gallery names each signature treatment beneath its preview.

## 01 Pulse

Preview: [/landing/pulse?demo=1](http://127.0.0.1:8018/landing/pulse?demo=1)

1. Signal shockwaves
2. Market pressure equalizer
3. P&L ignition sweep
4. Magnetic signal cards
5. Agent transmission chase

## 02 Neural

Preview: [/landing/neural?demo=1](http://127.0.0.1:8018/landing/neural?demo=1)

1. Dendrite constellation
2. Synaptic route console
3. P&L synapse bloom
4. Focus-linked neural wires
5. Agent firing cascade

## 03 Terminal

Preview: [/landing/terminal?demo=1](http://127.0.0.1:8018/landing/terminal?demo=1)

1. Packet rain
2. Live packet buffer
3. P&L raster decode
4. Row targeting scanner
5. Pipeline process tracer

## 04 Orbit

Preview: [/landing/orbit?demo=1](http://127.0.0.1:8018/landing/orbit?demo=1)

1. Comet field
2. Capital satellite belt
3. P&L gravitational echo
4. Focused orbital uplinks
5. Agent orbital telemetry

## 05 Prism

Preview: [/landing/prism?demo=1](http://127.0.0.1:8018/landing/prism?demo=1)

1. Refractive ribbons
2. Capital color mosaic
3. P&L chromatic shutter
4. Pointer-lit glass cards
5. Agent color relay

## 06 Supernova

Preview: [/landing/supernova?demo=1](http://127.0.0.1:8018/landing/supernova?demo=1)

1. Solar prominences
2. P&L ejecta bursts
3. Magnetic corona lens
4. P&L shockwave dial
5. Plasma targeting lens

## 07 Neon

Preview: [/landing/neon?demo=1](http://127.0.0.1:8018/landing/neon?demo=1)

1. Holographic scan walls
2. Quote packet fountains
3. Hexagonal targeting lens
4. Holographic P&L scanner
5. Chromatic market projection

## 08 Reactor

Preview: [/landing/reactor?demo=1](http://127.0.0.1:8018/landing/reactor?demo=1)

1. Segmented turbine vanes
2. P&L pressure rings
3. Geared iris lock
4. Segmented profit turbine
5. Armored instrument targeting

## 09 Liquid

Preview: [/landing/liquid?demo=1](http://127.0.0.1:8018/landing/liquid?demo=1)

1. Iridescent wave sheets
2. Quote splash wakes
3. Pointer whirlpool
4. Equity wave reservoir
5. Liquid glass market lens

## 10 Citadel

Preview: [/landing/citadel?demo=1](http://127.0.0.1:8018/landing/citadel?demo=1)

1. Capital skybridges
2. Changing-pair beacon shafts
3. Architectural blueprint lock
4. Capital skyline meter
5. Architectural market blueprint

## 11 Redline

Preview: [/landing/redline?demo=1](http://127.0.0.1:8018/landing/redline?demo=1)

1. Segmented velocity rails
2. Quote afterburner streaks
3. Steerable apex gate
4. Profit rev counter
5. Timing-board telemetry lock

## 12 Synapse

Preview: [/landing/synapse?demo=1](http://127.0.0.1:8018/landing/synapse?demo=1)

1. Braided axon helices
2. Changing-pair synaptic arcs
3. Dendrite focus cage
4. Branching profit cortex
5. Neural market synapse

## 13 Spectrum

Preview: [/landing/spectrum?demo=1](http://127.0.0.1:8018/landing/spectrum?demo=1)

1. Prismatic ribbons
2. Quote dispersion fans
3. Kaleidoscope lens
4. Refracted profit fan
5. Prismatic market loupe

## 14 Event Horizon

Preview: [/landing/horizon?demo=1](http://127.0.0.1:8018/landing/horizon?demo=1)

1. Gravitational photon arcs
2. Quote accretion streams
3. Lensing caustic rings
4. P&L convergence well
5. Gravitational market focus

## 15 Overdrive

Preview: [/landing/overdrive?demo=1](http://127.0.0.1:8018/landing/overdrive?demo=1)

1. Layered circuit raceways
2. Quote packet launch columns
3. Command crosshair matrix
4. Profit packet matrix
5. Market terminal hotlink

## 16 Pulse Blue

Preview: [/landing/pulse-blue?demo=1](http://127.0.0.1:8018/landing/pulse-blue?demo=1)

The supplied ShoeMoney logo is served unchanged from `public/brand/shoemoney-blue.png`. Its transparent artwork integrates into a cyan and royal-blue interpretation of Pulse, including the market pressure equalizer, P&L reactions, signal field, and AI relay. The original orange Pulse remains available at its existing route.

1. Integrated ShoeMoney logo
2. Azure signal field
3. Blue market pressure equalizer
4. Cyan P&L ignition
5. Branded AI relay

Browser verification: reviewed at 1440, 1024, and 390 px wide, with an additional overflow check at 320 px. Confirmed the original logo loads, market cards respond to pointer movement, pair details open and close with Escape and restored focus, search filters pairs, motion pause stops logo animation, and pausing the firehose preserves its rows while P&L continues updating. Pulse ↔ Pulse Blue navigation updates the active tab and title; orange Pulse retains its original styling. The gallery shows 16 designs, six signature layouts, and the new screenshot thumbnail. No browser warnings or errors were reported. Production build and all five existing frontend data tests passed. System-level reduced-motion emulation was not changed; the existing preference handling and explicit motion control remain in place.

## Experience the effects

Watch price and P&L updates, move across the market cards, focus them with the keyboard, and open a pair for its full details. The graphics illustrate the displayed snapshots and connections. They do not represent individual executions or additional financial measurements. Demo previews remain explicitly simulated.

The effects control freezes decorative motion while trading values continue to update. System reduced-motion preferences initialize the same restrained mode. Three.js designs use WebGL; an intentional `renderer=2d` query option exercises the static fallback.

## Verification

Computer-use review covered all fifteen designs at 1440px and 390px widths. All retained their coin icons, one active scene canvas, and no horizontal document overflow. The ten expanded designs retained all eighteen market cards. The original interactive strips provide additional entry points to the same ten demo markets.

Interaction review exercised pointer-driven card materials, keyboard focus and matching neural wires, projections into the 3D scene, pair search, inspection dialogs, Escape/focus restoration, route changes, and page scrolling. Pausing the event feed kept its rows fixed while P&L changed. Motion pause stopped the new CSS effects, and the deliberate 2D fallback rendered no WebGL canvas while retaining all markets and inspection. System reduced-motion initialization is covered by the same motion state and CSS rules; the OS preference itself was not changed during this review.

Visual iterations strengthened Neon/Liquid surfaces and Reactor/Citadel sculptures, reclaimed first-screen space in the originals, improved Prism's delta contrast, prevented decorative hit areas from covering the motion control, and kept Terminal's numeric P&L visible throughout its raster effect. Warning/error logs were empty after route and fallback checks. The production build and five existing frontend data-consistency tests passed. Live-feed performance and physical GPU device-loss recovery were not exercised; this local review used explicit simulated data.

## Final company signature pass

The full sixteen-design rollout is tracked in [BRAND-ROLLOUT.md](BRAND-ROLLOUT.md). Three Picaso lanes handled the six signature layouts, ten explosion layouts, and shared insignia/GPU audit; the coordinator integrated the gallery and performed computer-use review. The supplied PNG is unchanged. Each design surrounds it with its own geometry, lighting, pointer response, and snapshot-driven pulse. Typography and spacing were enlarged, former light surfaces converted to black, and trailing X characters made blue. Updated browser captures replace all sixteen gallery images.

## 17 Event Horizon Blue and 18 Supernova Blue

Two additional editions carry electric blue through the particle fields, logo treatments, instruments, quoted prices, and headline P&L while preserving the original layouts. Event Horizon Blue uses cyan accretion streams and blue lensing arcs; Supernova Blue uses azure plasma shards and electric blue shockwaves. Both originals remain available. The gallery now contains eighteen designs and two new browser-captured previews. Implementation and verification are recorded in [BLUE-EDITIONS.md](BLUE-EDITIONS.md).

## Event Horizon robot centerpiece

Both Event Horizon editions now place the supplied ShoeGPT robot inside the singularity. Its background is transparent, with a soft lower fade, cyan orbital traces, a slow eye pulse, and a restrained chest-emblem light sweep. Header branding remains the company logo. Asset provenance and browser checks are recorded in [GUARDIAN-CENTERPIECE.md](GUARDIAN-CENTERPIECE.md).
