# Nanites front-page launch

The winning blue Nanites design becomes `/`; the design galleries remain at `/designs` and `/singularity`, and the existing operational pages remain available. Picaso owns visual integration and coin artwork, Taytay owns the read-only data contract, Maria independently reviews accounting and database behavior, and the coordinator owns browser checks and deployment.

## Data and access

- Real mode is mandatory on home, including `/?demo=1` and navigation from a simulated preview.
- The user requested removal of the access prompt after launch. The read-only landing snapshot now loads without a token. Trading controls and other desk API routes remain protected, and no credentials are embedded in the page.
- Current quotes, mode-scoped positions, net booked-cost P&L, exposure, closed wins, recent fills, scan decisions and dated bank snapshots are displayed. Shared system/optimizer events remain distinguished from desk-mode events.
- Snapshot reconciliation runs every three seconds. Reverb supplies the actual optimizer firehose, with duplicate-resistant event counters and bounded buffers. Missing data stays unknown and stale records retain their age.
- The Qwen description is user-supplied product copy. No model inference endpoint or model-health integration exists in this repository, and none is fabricated by this change.
- Deployment preserves paper/live execution settings and performs no database migration or trading action.

## Verification before merge

- 28 focused Laravel API/authentication tests passed with 174 assertions; eight frontend accounting/event tests passed. Production builds succeeded locally and on Wick using Wick's actual Reverb configuration.
- Maria reconciled the staged endpoint with real MariaDB records using the exact quote marks returned by that endpoint. Sixteen accounting/privacy checks passed, including pair-to-total P&L, booked costs, long/short counts, exposure, fills/events mode isolation and bank values. No database writes were used.
- Five authenticated staging requests took 18.44–54.55 ms, median 19.52 ms, on the desk host. This is a small diagnostic sample, not a load benchmark.
- Browser checks exercised access rejection and connection with a temporary staging token, actual quote/P&L updates, connected optimizer events, paused-feed buffering with counters continuing, position filtering, market search, actual position inspection, and preview-to-home isolation.
- Desktop and 390px layouts were reviewed. The CSS fallback retained every coin and actual data with zero canvases. Motion controls stopped the robot/coin effects, and there was no horizontal document overflow. Existing reduced-motion and device-loss paths remain; no new OS-level device-loss test or frame-rate claim is made.
- Live-data review identified five additional coin logos (HYPE, NEAR, ENA, HBAR, ONDO), dark scrollbar polish, and mobile feed-status visibility for the final candidate.

## Deployment target

Wick serves the application at `http://<lan-host>:8811/`, managed by the existing `shoemoneyx-serve` PM2 process. The candidate is first built and exercised in a separate worktree on port 8818. After final visual checks, the tested commit is fast-forwarded to the repository's main branch (`master`) and installed on Wick. Previous code revision and built assets are retained for rollback.
