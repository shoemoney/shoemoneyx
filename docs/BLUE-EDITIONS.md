# Picaso blue cosmic editions

Create separate blue interpretations of Event Horizon and Supernova using the existing company logo, black backgrounds, larger type, and full effect density. Replace their warm artistic colors with electric blue, royal blue, and cyan; make quoted prices and the headline P&L blue. Keep values and signs intact.

## Editions

| Number | Name               | Route                            | Family        |
| ------ | ------------------ | -------------------------------- | ------------- |
| 17     | Event Horizon Blue | `/landing/horizon-blue?demo=1`   | Event Horizon |
| 18     | Supernova Blue     | `/landing/supernova-blue?demo=1` | Supernova     |

## Ownership

- Picaso UI: edition configurations, inherited layouts and instruments, price colors, control and panel palettes.
- Picaso graphics: original logo with blue theme geometry, Three.js materials, particle/link/impact colors, and existing cleanup behavior.
- Coordinator: gallery ordering and labels, enhancement descriptions, browser review, thumbnails, documentation, and final integration.

## Acceptance

- [x] Both routes load their intended layouts with all eighteen markets.
- [x] Warm artistic colors are replaced by blue throughout each edition.
- [x] Prices and headline P&L are blue; negative values keep explicit signs.
- [x] Supplied logos load and retain their original artwork.
- [x] Desktop and phone layouts retain readable text and avoid horizontal overflow.
- [x] Keyboard pair inspection, focus restoration, effects pause, and search work.
- [x] Original-to-blue route changes retain correct titles and one renderer.
- [x] Intentional CSS fallback works with no WebGL canvas.
- [x] Gallery shows eighteen designs, six signatures and twelve explosion editions, with two new thumbnails.
- [x] Production build and source checks pass.

## Implementation notes

The blue editions use a family key for existing layout and visual-instrument selection, while each edition keeps its own route, title, active navigation state, renderer key, and logo theme. The renderer remains Three.js WebGL2, with the existing CSS fallback. No additional trading subscription or renderer backend is introduced.

## Browser verification

Reviewed both editions at 1440, 390, and 320 px viewport widths using computer use. Each retained eighteen market cards, the original logo artwork, the intended family instrument, one scene canvas, and no horizontal document overflow. Headline P&L and quoted prices remain blue during live simulated updates and effects pause. Negative values retain their minus signs with a distinct lavender accent where applicable.

Exercised keyboard pair inspection, Escape and focus restoration, pair search, effects pause/resume, and actual navigation between original and blue editions. The navigation strip now reveals the selected edition without scrolling the page vertically. Original Supernova and Event Horizon retained their warm palettes. Both blue editions also rendered the intentional CSS fallback with no canvas and all markets available.

The gallery shows eighteen cards in numeric order, six signature layouts, twelve explosion layouts, and ninety enhancement entries. Both new 960 × 640 browser captures load, and the featured blue-edition links remain visible without overflow at 390 and 320 px. Warning/error logs were empty after review. The preview also loads through `<lan-host>:8018` on the existing network-bound server.

This review used simulated data. System-level reduced-motion preferences, physical GPU device loss, and access from a separate computer were not exercised. Existing preference handling and motion controls remain in place; no frame-rate or WebGPU claim is made.
