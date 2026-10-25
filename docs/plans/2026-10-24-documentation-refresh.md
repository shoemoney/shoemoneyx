# Detailed changelog and documentation refresh

Base: `8836fae404c58cd08419357f5572cb6fcf9cf040`.

Keep this change documentation-only. Describe implemented behavior from source,
historical releases from public tags, and validation only within its recorded scope.
Version 0.3.0 remains pending until its separate release operations are completed.

## Implementation steps

1. Expand `CHANGELOG.md` with detailed pending-0.3.0 changes, failure modes,
   operational effects, source/test references, migration inventory, upgrade
   implications, limitations and a single dated validation snapshot. Add public
   v0.2.0 through v0.2.4 history; distinguish the v0.2.0 source tag from the later
   GitHub releases.
2. Update `README.md`; add `docs/README.md` and `docs/UPGRADING.md`. Separate
   prebuilt images from current-source installation, document both Docker build
   targets and persistent version selection, and cover backups, retained keys and
   volumes, migrations, restarts and password changes.
3. Update `docs/AGENT.md`, `docs/E2E.md`, `docs/RISK.md`,
   `docs/HOSTED_IMAGE.md` and `docs/ARENA_ACCOUNTS.md`. Correct stale runtime,
   validation and recovery descriptions; document account/mode/venue/version
   boundaries, complete tool transcripts, reconciliation and live-image acceptance.

The three steps own disjoint files and may be implemented in parallel. Preserve
existing useful documentation and links. Do not edit application code, dependencies,
workflows, historical release records or unrelated design documents.

## Acceptance

- Verify commands, options, environment variables, migration names and mechanism
  claims against the current implementation.
- Verify local Markdown links and heading anchors. Keep detailed test counts in
  one dated evidence section rather than repeating them throughout the guides.
- Render all changed Mermaid diagrams and inspect their readability. Use clear
  labels and avoid decorative emojis, arrows and tiny badges.
- Check whitespace, exact changed-file scope, public-safe content, secret scans
  and outgoing commit history.
- Obtain two fresh independent reviews tied to each final committed range.
- Publish through the existing PR workflow, wait for required hosted checks and
  merge with the review trailers intact.
- Verify remote content and intended documentation parity while preserving
  repository-specific operational material and existing local files.

Publishing documentation does not create a release tag, publish containers, build
or register an AMI, prove live public-CA behavior or submit a Marketplace delivery.
