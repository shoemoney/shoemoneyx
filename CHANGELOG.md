# Changelog

This file records user-visible behavior, operational changes and compatibility
notes for ShoeMoneyX. Entries describe the source associated with each version;
they do not imply that an image, AMI or Marketplace delivery contains newer code.

> **Release status:** 0.3.0 is pending. Its application changes are merged into
> public `main`, but the latest published GitHub release remains v0.2.4 as of
> October 24, 2026. Pulling a published container is different from building the
> current checkout. See the [upgrade guide](docs/UPGRADING.md) before updating.

## Contents

- [0.3.0: pending release](#030-pending-release)
- [Database migrations and compatibility](#database-migrations-and-compatibility)
- [Validation snapshot](#validation-snapshot)
- [Release and live-acceptance boundaries](#release-and-live-acceptance-boundaries)
- [Historical public versions](#historical-public-versions)

## 0.3.0: pending release

**Source baseline:** [public commit `8836fae`](https://github.com/shoemoney/shoemoneyx/commit/8836fae404c58cd08419357f5572cb6fcf9cf040),
merged through [public PR #28](https://github.com/shoemoney/shoemoneyx/pull/28).
The changes below are measured against the [v0.2.4 source tag](https://github.com/shoemoney/shoemoneyx/tree/v0.2.4).

### Upgrade priorities

| Area | What operators should do |
|---|---|
| Passwords | Use **Settings / Master password** after onboarding; update scripts to the new owner password after a rotation. |
| Installation | Decide explicitly between a published image and a build of current source. Do not assume `docker compose pull` deploys the checked-out commit. |
| Database | Back up the database and retain the existing application key before applying the migrations listed below. |
| Open positions | Review recorded venue and strategy/version ownership, especially older rows whose ownership could not be inferred. |
| Background work | Restart the relevant application processes after an upgrade; retain a single intended desk-loop or scheduler configuration. |
| AWS images | Require provenance and live acceptance for a newly built candidate. An older AMI's successful boot does not validate these changes. |

### Authentication and password changes

#### Password rotation is available after onboarding

Earlier releases had a password-change endpoint, but a completed first-run
wizard redirected back to the dashboard and Settings had no password-change
form. A user following the old release advice could therefore have no usable
screen from which to rotate the master password.

Settings now contains a **Master password** panel with current-password,
new-password and confirmation fields. It uses the existing onboarding password
endpoint, so setup and later rotation share the server's credential rules.
This addresses [public issue #13](https://github.com/shoemoney/shoemoneyx/issues/13).

- The current password is required when replacing an existing owner password.
- The new password must contain at least 12 Unicode characters and fit within
  72 UTF-8 bytes. Multibyte characters count toward the byte limit.
- Matching confirmation is required. The UI displays validation errors rather
  than treating every rejected request as an undifferentiated failure.
- A successful change clears the form fields and installs the returned CSRF
  token before other Settings writes are allowed to resume.
- The browser performing the change keeps its authenticated session. Other
  sessions and the previous password no longer authorize subsequent requests.
- Public-demo mode cannot submit password changes.

Settings also coordinates the change with its other operations: rotation is
disabled or refused while a Settings write is running, and save/reset/desk actions
are blocked while rotation is in flight. This prevents an avoidable stale-CSRF
race between a password change and a neighboring configuration operation.

Implementation: [Settings page](resources/js/pages/Settings.vue) and
[shared API client](resources/js/api.js). Regression coverage includes
[password-form tests](tests/Unit/Frontend/settingsPassword.test.mjs) and
[API/CSRF tests](tests/Unit/Frontend/api.test.mjs).

#### Exact password verification includes the bcrypt byte boundary

Setup already limited the size of new passwords. Verification now applies the
same byte boundary before bcrypt or a successful-token cache entry can accept a
credential. An exact-length password with an appended suffix must not authenticate
as the original merely because bcrypt ignores input after its byte limit.

Owner passwords retain their exact spelling, case and whitespace. The forgiving
trim/case normalization used for a manually pasted bootstrap key does not apply
to an owner password. The owner also cannot choose the bootstrap key as the new
password.

Implementation: [credential verification and storage](app/Desk/Settings.php).
Coverage: [password security](tests/Feature/PasswordSecurityTest.php) and
[master-password behavior](tests/Feature/MasterPasswordTest.php).

#### First-owner claims and later rotations are conditional writes

Concurrent requests no longer get to replace a credential merely because each
request saw an earlier state before another request committed.

- Initial setup uses an atomic insert-or-ignore claim for the password row.
- Replacements lock and compare the current stored credential against the
  credential snapshot that authorized the request.
- Classified transaction conflicts can be retried; a displaced credential is
  checked again rather than silently becoming the new owner.
- A request that loses the ownership race returns `409 credential_changed`.
- A session grant is bound to the credential authorized by that request, rather
  than a newer credential read after the write.

Authentication reads the password row fresh instead of trusting the ordinary
merged-settings cache. A stale generic settings-cache refill therefore cannot
extend the lifetime of the previous credential after rotation. A database read
failure is an unavailable authentication service, not evidence that no password
exists and bootstrap access should reopen.

Implementation: [Settings](app/Desk/Settings.php),
[session fingerprints](app/Desk/DeskLogin.php) and
[password endpoint](app/Http/Controllers/Api/OnboardingController.php).
Coverage includes deliberate displaced-reader and overlapping-write cases in
[password security tests](tests/Feature/PasswordSecurityTest.php).

#### AWS first login waits for the intended trusted HTTPS activation

An AWS-style bootstrap credential is refused until the request is HTTPS and the
certificate-activation marker names the requested host. A marker for a previous
public IP or a different hostname does not open the set-password path.

The check applies to bootstrap login and protected setup paths, including a
previous bootstrap session or an API-header attempt. A missing, malformed,
unreadable or mismatched marker leaves bootstrap access blocked. Forwarded
headers alone do not establish a secure connection.

This gate is specific to AWS first-owner setup. Once an owner password exists,
its normal authentication is not made dependent on the first-login marker.
Direct local setup retains its separate host and network restrictions.

Implementation: [BootstrapTls](app/Desk/BootstrapTls.php),
[desk authentication](app/Http/Controllers/DeskAuthController.php) and
[API authentication middleware](app/Http/Middleware/DeskToken.php).
Coverage: [authentication hardening](tests/Feature/DeskAuthHardeningTest.php) and
[disposable password journeys](tests/e2e/password-security.md).

#### Password material stays outside generic configuration reads

The generic settings API remains unable to read, replace, delete or shadow the
master-password key. CLI configuration reads now also refuse password keys and
aliases instead of becoming an alternate way to retrieve password material.
The visible `master_password_hint` is explanatory login text, not the credential.

Failure throttling groups IPv6 failures by their /64 prefix so rotating the low
address bits does not reset the failure budget. IPv4 retains address-keyed buckets.

Implementation: [desk control command](app/Console/Commands/DeskControl.php),
[settings-key rules](app/Desk/Settings.php) and
[authentication throttling](app/Desk/DeskAuthThrottle.php).

### LangChain and LangGraph strategy-agent runtime

#### The agent executes through the pinned PHP SDK

The strategy agent now invokes `LangGraph\Agents\Agent::create()` through
`shoemoney/langchain-php`. Composer pins the public HTTPS repository to
`8a588b040d943fe8d7d42fef8fad05d7a0624340`; installation does not require a local
SDK checkout or a Composer path override.

The graph is an orchestration change. Provider calls still travel through the
application's `ChatClient` contract and existing OpenRouter transport. Connected
account selection, authorization, usage auditing and transport retry behavior
remain in that application layer; the SDK adapter does not create its own
provider connection.

The application registers its existing tools as structured SDK tools and runs
each response's tool batch sequentially. Conversation rows remain the persisted
history; this integration does not enable a second checkpoint database or replace
the conversation API.

Implementation: [StrategyAgent](app/Ai/StrategyAgent.php),
[StrategyGraph](app/Ai/LangChain/StrategyGraph.php),
[ChatClientModel](app/Ai/LangChain/ChatClientModel.php) and
[Composer manifest](composer.json). The [agent guide](docs/AGENT.md) describes the
runtime boundary and tool contract in more detail.

#### Tool execution is bounded without corrupting the transcript

The per-turn limit is six executed tool calls, not six model rounds. Requests
over the remaining budget receive explicit skipped results rather than being
left without the protocol response their assistant call expects.

When the execution budget is spent, the concluding model request has no tools.
If a provider ignores that disabled-tool request, the graph completes the
transcript with skipped tool results and ends the turn instead of continuing an
unbounded chain of model calls. A blank initial response can be retried once
before any tools have executed.

One tool exception becomes that call's error result; it does not discard the
remaining protocol responses in the batch. The graph journals completed tool
effects and results so they can still be persisted if a later provider request
fails. This is not a rollback of side effects and should not be interpreted as
permission to replay a completed tool automatically.

Coverage: [graph execution tests](tests/Feature/Ai/StrategyGraphTest.php) and
[agent behavior tests](tests/Feature/Ai/StrategyAgentTest.php).

#### History repair does not repeat historical side effects

Older stored conversations can contain an assistant tool batch with a missing
result, or a sliced history that begins with an orphan tool response. History
normalization repairs the protocol shape before the next model request:

- Missing historical results become explicit unknown/unrecorded-result messages.
- Orphan and duplicate tool-result messages are not sent as unmatched responses.
- Recovery does not rerun the historical tool to manufacture a result.
- Whole user turns are retained around the normal message-history target.
- The newest complete turn is retained even when its batch exceeds that target.

The model adapter also reserves historical tool-call identifiers. Empty or reused
provider identifiers are replaced so a new call cannot be mistaken for an
already-answered historical call.

Implementation: [message codec](app/Ai/LangChain/MessageCodec.php),
[model adapter](app/Ai/LangChain/ChatClientModel.php) and
[history retention](app/Ai/StrategyAgent.php).

#### Community data and registration credentials keep separate boundaries

Community-written results and MCP output remain fenced as untrusted data. They
are data for the agent to consider, not authority to publish, import or perform
another side effect without the application's confirmation boundary.

`hub_register` now returns registration guidance. Registration credentials belong
in the Hub connection panel, not in conversation arguments. Legacy credential
arguments are scrubbed before graph state, events and transcript persistence see
them, including obsolete calls emitted by a provider or recovered from history.

Implementation: [registration guidance](app/Ai/Tools/HubRegisterTool.php),
[message conversion](app/Ai/LangChain/MessageCodec.php) and
[tool-result fencing](app/Ai/StrategyAgent.php).
Coverage: [untrusted-input tests](tests/Feature/Ai/UntrustedToolInputTest.php).

### MCP initialization and child-process isolation

The stdio MCP client now sends `notifications/initialized` after the initialize
response and before operational tool discovery. A server that waits for the
initialized notification can therefore become usable instead of timing out
before the client asks for its tools.

Child processes no longer receive the complete parent environment by default.
The base inherited names are `PATH`, `HOME`, `LANG`, `LC_ALL` and `TMPDIR`.
Additional parent names must be listed in `inherit_env`; explicit `env` values
remain an intentional handoff to that child.

This reduces accidental inheritance of application, database and provider
credentials. It is not a sandbox for an untrusted executable: the configured
process still runs with the operating-system permissions of the application.
Review and pin a chosen server before enabling it.

Implementation: [MCP client](app/Ai/Mcp/McpClient.php),
[tool loader](app/Ai/Mcp/McpToolLoader.php) and
[MCP configuration](config/mcp.php).
Coverage: [client tests](tests/Unit/Ai/Mcp/McpClientTest.php).

### Immutable strategy versions and safer configuration

Background agent backtests now use one captured immutable strategy version for
the interval/definition check, queued backtest parameters, recorded version ID
and review prompt. Editing the mutable plugin while a tick is preparing work
must not switch the tested definition midway through that tick.

Open JSON-plugin positions also record the immutable version selected for their
opening entry. Later risk sweeps use the recorded strategy and version where
available, rather than applying an unrelated newly selected strategy to an old
position. Legacy rows without a usable recorded strategy/version retain the
documented active-strategy fallback; history is not invented during upgrade.

Schema-v2 definitions preserve their schema version and parameter substitution
instead of being described as if every definition were normalized into v1.
Add-size and add-count validation agree with the runtime limits, so an invalid
definition can be rejected before it reaches the desk.

The retired `scan.min_buy_sell_ratio` and
`vet.min_rejections_per_day_warning` overrides are removed because the desk did
not read them. A visible saved value for either key was not an enforced filter.

Implementation: [background loop](app/Jobs/AgentBacktestLoop.php),
[JSON-plugin strategy](app/Desk/Strategies/JsonPluginStrategy.php),
[schema validator](app/Desk/Strategies/StrategySchemaValidator.php) and
[per-position risk selection](app/Desk/Desk.php).
Coverage: [background-loop tests](tests/Feature/AgentBacktestLoopJobTest.php),
[per-position risk tests](tests/Feature/DeskRiskSweepPerPositionStrategyTest.php)
and [retired-knob tests](tests/Feature/DeadDeskKnobsTest.php).

### Order intent, reconciliation and venue ownership

#### Booking and intent resolution share the transaction boundary

A terminal executor result carries the live intent identifier until the desk
books the fill and position update. The desk resolves that intent inside the
booking transaction. If booking fails, the intent remains available for later
reconciliation instead of disappearing as an apparently finished order with no
local fill.

This applies to entries, adds, trims and closes. Repeated reconciliation of an
already-booked intent must not add another fill or another position adjustment.
Ambiguous exchange outcomes remain unknown until reconciliation supplies the
missing evidence; a communication failure is not proof of a rejected order.

Implementation: [idempotent submission](app/Desk/Execution/Concerns/SubmitsOrdersIdempotently.php),
[result metadata](app/Desk/Execution/OrderResult.php) and
[desk booking](app/Desk/Desk.php).
Coverage: [live-order idempotency tests](tests/Feature/LiveOrderIdempotencyTest.php).

#### Dust-decorated sell results retain their intent identifier

Adding dust information creates a new `OrderResult`. That copy previously lost
the mutable `intentId`, even though the original terminal sell result had it.
The desk could book the sell and then leave the same live intent pending for a
later reconciliation pass.

`OrderResult::withDust()` now preserves the identifier along with its existing
constructor fields and deferred ledger closure. The original result is not
mutated, and copying it does not execute the ledger closure.

Regression coverage follows the real Coinbase executor through fake-venue sell
quantization, desk close booking and repeated reconciliation. The tests require
one submitted order and one booked fill, with a resolved intent.

Coverage: [OrderResult tests](tests/Unit/OrderResultTest.php) and
[live-order idempotency tests](tests/Feature/LiveOrderIdempotencyTest.php).

#### Positions remain attached to the venue that opened them

Live spot and perpetual-futures positions can share the same desk product ID.
Changing the configured executor must not close, trim or add to a position on a
different venue merely because its product matches.

Positions now have a venue field. Matching and mutation checks use that recorded
venue, and a risk sweep can return `venue_refused` rather than send an order
through the wrong executor. Reconciled fills likewise do not modify an existing
position owned by another venue; recovery can be recorded separately when needed.

Migration backfill uses confirmed opening-intent evidence where it is
unambiguous. A short or recorded perps margin can provide a perps hint when no
opening-intent evidence exists. Contradictory or ambiguous older rows remain
unset. Those legacy rows keep the current-executor fallback with warning behavior,
so operators should review them instead of assuming an exact venue was recovered.

Implementation: [position model](app/Models/Position.php),
[desk venue checks](app/Desk/Desk.php) and
[venue migration](database/migrations/2026_10_01_000001_add_venue_to_positions.php).
Coverage: [venue-pinning tests](tests/Feature/DeskPositionVenuePinTest.php).

#### Quantity floors, dust and degraded fill reporting are explicit

Decimal-safe flooring is centralized in `Quantize` rather than relying on binary
floating-point multiplication followed by a raw floor. Coinbase spot sells apply
the product quantity increment and distinguish a sellable amount from a residual
tail that cannot meet the venue's order requirements.

Unsellable residual quantity can be written off as dust rather than keeping a
position open forever with a remainder no valid order can clear. The write-off
reduces remaining book cost and records the quantity in position metadata; it is
not represented as a profitable exchange execution.

When a venue reports a filled quantity without a usable price or notional,
fill-basis recovery is explicit. A recovered basis is flagged and does not report
fabricated zero slippage as a measured execution result. Extreme measured
slippage is bounded to fit the database column rather than losing an already
executed fill record to strict-database overflow.

Implementation: [Quantize](app/Desk/Execution/Quantize.php),
[Coinbase spot executor](app/Exchange/Coinbase/CoinbaseExecutor.php),
[fill-result semantics](app/Desk/Execution/OrderResult.php) and
[dust bookkeeping](app/Desk/Desk.php).
Coverage: [quantity-floor tests](tests/Unit/QuantizeTest.php),
[spot sell tests](tests/Unit/CoinbaseExecutorSellQtyTest.php) and
[dust-close tests](tests/Feature/DeskDustCloseTest.php).

### Risk sizing, adds and exit behavior

Risk-driven re-entry and scan-driven adds now use the shared add-bookkeeping
path. The runtime checks free cash, leverage room, the original entry size,
the aggregate position cap and the add/re-entry count before sending another
entry-sized operation.

An individual add cannot exceed the position's initial cost. The total position
cap is tied to twice the applicable Kelly allocation, and the shared maximum
add/re-entry count is enforced at runtime as well as in schema validation.
If the remaining allowed ticket falls below the minimum viable ticket, it is
refused rather than rounded up past a cap.

Plugin-defined sizing is applied under the desk's final risk limits. Risk-control
edits persist at one intended save point, and warning state is kept in sync when
a definition is imported, validated, saved or restored.

Perpetual-futures exit paths verify the intended close direction rather than
turning a failed close into new opposite-side exposure. Unknown outcomes remain
subject to reconciliation. A configured strategy change does not rewrite the
exit rules already recorded for an existing position.

Implementation: [desk add and risk paths](app/Desk/Desk.php),
[schema validator](app/Desk/Strategies/StrategySchemaValidator.php) and
[Coinbase perps executor](app/Exchange/Coinbase/CoinbasePerpsExecutor.php).
Coverage: [add caps](tests/Feature/DeskAddSizingCapsTest.php),
[risk-driven adds](tests/Feature/DeskRiskAddEntryFenceTest.php),
[single-save controls](tests/Feature/StrategyCapsSingleSavePointTest.php) and
[perps exit guards](tests/Unit/CoinbasePerpsExecutorExitGuardTest.php).
See [RISK](docs/RISK.md) for operational recovery and ownership checks.

### Cycle ownership, restarts and graceful stopping

The desk's cycle-owner token and heartbeat changes are serialized through an
owner guard. A replacement or stale-lock reclaim must not race a heartbeat
written by a displaced owner and accidentally make that old owner current again.

- Cycle ownership is checked again at entry-submission boundaries, including
  after blocking preparation and before a paper cash debit or live submission.
- A displaced owner stops creating new entries or re-entry orders.
- An already-submitted order can still be read back and booked; losing entry
  ownership does not erase a real fill that needs reconciliation.
- Exit work does not acquire a new entry permission requirement simply because
  a stop or ownership change has interrupted candidate processing.
- Heartbeats continue through long scan/reconciliation work instead of reporting
  an active loop as dead merely because it is between top-level loop ticks.

`desk:run` handles stop signals and checks the stop request before new work.
Scheduler startup retries cycle-mutex cleanup only within a bounded policy and
fails startup when it cannot establish that cleanup completed.

`DESK_RECLAIM_FOREIGN_CYCLE_LOCKS` is an explicit deployment assumption. Enable
foreign-host reclaim only where exactly one intended `desk:run` exists, such as
the supplied Compose desk role. It is not a general instruction to clear locks
held by another host in a multi-runner deployment. A missing heartbeat record is
not, by itself, proof that a historical owner stopped.

Implementation: [cycle ownership](app/Desk/Desk.php),
[desk runner](app/Console/Commands/DeskRun.php) and
[entry fence](app/Desk/Execution/OrderBudget.php).
Coverage: [restart locks](tests/Feature/DeskRestartLocksTest.php),
[submission fences](tests/Feature/EntrySubmissionFenceTest.php) and
[scheduler startup](tests/Feature/DeskSchedulerStartupTest.php).

### Main-desk and arena account isolation

Main-desk and arena activity now carry their ownership through positions, paper
cash accounting, fills, bank history, cycle records, status responses and reports.
An arena seat's paper operation must not adjust the main desk's cash or take
credit for its fills. Daily PnL inputs are scoped to the same seat rather than
being drawn from all accounts in one mode.

Cycle records include `arena_seat_id`. Historical runs are backfilled only when
their linked fills consistently establish one non-null arena owner. Runs with
mixed ownership or no usable fill evidence keep their legacy attribution rather
than being assigned a seat based on a strategy name or an assumption.

The landing/status/report queries use the intended account scope, so a dashboard
for the main desk does not silently aggregate an arena experiment into its own
balances and activity. Shared product identifiers do not override account or
venue ownership.

Implementation: [arena runner](app/Desk/Arena/ArenaRunner.php),
[paper ledger](app/Desk/PaperBook.php), [desk context](app/Desk/DeskContext.php),
[bank service](app/Desk/BankService.php) and
[cycle model](app/Models/DeskRun.php).
Coverage includes [account scope](tests/Feature/DeskAccountScopeTest.php),
[paper accounting](tests/Feature/PaperSeatAccountingTest.php),
[bank history](tests/Feature/BankHistorySeatScopeTest.php),
[status](tests/Feature/StatusSeatScopeTest.php),
[reports](tests/Feature/EndOfDayReportSeatScopeTest.php) and
[run migration](tests/Feature/DeskRunSeatMigrationTest.php).
See the [account history guide](docs/ARENA_ACCOUNTS.md) for legacy limitations.

### Database, queue and housekeeping reliability

The unfiltered PHP suite now also runs on strict MariaDB, rather than depending
only on SQLite's more permissive schema behavior. The resulting fixes include
wider optimizer/product and sizing columns, bounded slippage writes, and an
index for the mode/status/close-time position query.

MySQL/MariaDB sessions use UTC so a timestamp string does not fall into a
daylight-saving transition gap in a database session configured to a local zone.
Database aggregate values that arrive as strings are handled where arithmetic
expects a numeric result.

Stale-backtest cleanup first scans candidate IDs without taking update locks,
then locks each primary row and rechecks its status and heartbeat. A backtest
that completed or renewed its lease after the scan is not reaped as abandoned.
Using the worker-compatible primary-row lock order avoids the former secondary-
index/primary-key inversion against completing simulations.

File heartbeats provide health evidence for long-running desk and queue roles.
A scheduled queue-heartbeat job establishes that a worker is consuming queued
work; merely having a queue process present is not the same check.

Default application logging uses the daily channel and its supported `days`
retention option. Housekeeping configuration exposes event, failed-job and batch
retention separately. These settings control operational records; they are not
permission to remove trading data or a substitute for backups.

Implementation: [database configuration](config/database.php),
[stale-backtest reaper](app/Support/StaleBacktestReaper.php),
[heartbeats](app/Desk/Heartbeat.php),
[queue heartbeat](app/Jobs/QueueHeartbeat.php),
[logging](config/logging.php) and [scheduled jobs](routes/console.php).
Coverage: [MariaDB widths](tests/Feature/MariaDbColumnWidthsTest.php),
[stale-backtest races](tests/Feature/StaleBacktestReaperTest.php) and
[retention/heartbeat behavior](tests/Feature/InfraRetentionAndHeartbeatTest.php).

### Browser and frontend reliability

Risk confirmations describe the current and proposed values explicitly, retain
keyboard focus within the dialog and restore focus on cancellation. The changed
dialog and password panel have readable desktop/mobile controls and viewport-
appropriate wrapping and scrolling.

Browser-storage failures no longer need to crash a view that can continue with
in-memory state. Chart quote/series lifecycle coverage and strategy-builder
warning-state coverage protect against stale state after updates, failed loads
and component transitions.

Production pages receive one application-managed Content Security Policy:
same-origin scripts, no embedded objects, a same-origin base URI and no framing.
The policy is omitted while the Vite hot server is active because development
uses different script-loading requirements.

Implementation: [risk confirmation](resources/js/components/ConfirmRisk.vue),
[risk descriptions](resources/js/riskConfirm.js),
[CSP middleware](app/Http/Middleware/ContentSecurityPolicy.php) and
[browser API](resources/js/api.js).
Coverage: [risk-dialog tests](tests/Unit/Frontend/confirmRisk.test.mjs),
[storage tests](tests/Unit/Frontend/storage.test.mjs) and
[CSP tests](tests/Feature/ContentSecurityPolicyTest.php).

### Certificate and hosted-image tooling

The hosted-image implementation includes a certificate lifecycle script, a
systemd service/timer, hash-pinned certificate-tool requirements and explicit
trusted/fallback activation status. Certificate layout conversion can recover
after an interruption instead of leaving later provisioning attempts trapped
behind a partially changed directory layout.

Firewall handling bounds temporary HTTP exposure to the certificate operation.
Private-key mode checks use an exact portable mode check; a valid private key
must not be rejected because a platform's `stat` command printed unrelated
filesystem information before failing a fallback branch.

The boot-acceptance script checks the HTTPS endpoint, trust/setup gates,
authenticated owner-password lifecycle and bootstrap invalidation. Disposable
credentials are cleaned up, and local negative controls check that broken
readiness/authentication/cleanup conditions fail the harness rather than produce
a misleading successful exit.

These are implementation and local regression changes. They do not establish
that a newly built AMI has obtained a public-CA certificate, renewed it on its
scheduled timer or remained trusted after a public-IP change. The live candidate
must still be tested with its own recorded provenance.

Implementation: [certificate lifecycle](ops/image/files/acme.sh),
[certificate requirements](ops/image/files/certbot-requirements.txt),
[first boot](ops/image/files/first-boot.sh) and
[boot acceptance](ops/image/test-boot.sh).
Local coverage: [ACME harness](ops/image/test-acme.sh),
[boot harness](ops/image/test-test-boot.py) and
[dependency-hash harness](ops/image/test-certbot-requirements.sh).
Operational detail: [hosted-image guide](docs/HOSTED_IMAGE.md).

### Dependencies and build/CI behavior

| Component | Source/build state at this entry |
|---|---|
| PHP container and hosted PHP tests | PHP 8.5; Docker base is digest-pinned. |
| Node build and feeder stages | Node 24; the relevant build stages use pinned base images. |
| Database service/test engine | MariaDB 13, with the complete production-engine test job. |
| Redis service/test dependency | Redis 8. |
| nginx container | nginx 1.31 Alpine. |
| Laravel lockfile | Framework v13.38.0 and Reverb v1.12.0. |
| PHP test runner | PHPUnit 13.4.3 in the recorded lockfile. |
| Frontend dependency changes | dotenv moves to the 18.x requirement and Three.js to the 0.186.x requirement. |
| Strategy SDK | Public HTTPS Git source pinned to the revision recorded above. |

These are repository/lockfile facts, not a claim that every dependency is the
newest available package. Installation uses the committed lockfiles; changing
dependency constraints is a separate update that still needs advisory checks.

Pull requests run application and nginx build verification without publishing
images. Stable version-tag and manual image workflows have separate test and
publication stages. Native architecture builds are used for the multi-architecture
release path; PR build success alone does not mean a new registry tag was pushed.

The test workflow includes PHP/SQLite, full MariaDB, frontend, feeder and local
image-validation jobs. The source update also refreshes the corresponding
checkout, Node and Docker workflow action versions.

Implementation: [Dockerfile](Dockerfile), [Composer lockfile](composer.lock),
[npm manifest](package.json), [test workflow](.github/workflows/test.yml) and
[image workflow](.github/workflows/image.yml).

## Database migrations and compatibility

The following migrations were added after v0.2.4. They are application schema
changes, not optional documentation steps. Back up before upgrading, preserve
the existing `.env` and `APP_KEY`, and apply migrations to the intended database.
The [upgrade guide](docs/UPGRADING.md) distinguishes application-container startup
from a manual installation and explains how to check migration status.

| Migration | Forward change | Compatibility and recovery notes |
|---|---|---|
| [`2026_10_01_000001_add_plugin_version_id_to_positions.php`](database/migrations/2026_10_01_000001_add_plugin_version_id_to_positions.php) | Adds nullable `positions.plugin_version_id`. | New JSON-plugin positions can retain an immutable opening version. Existing rows remain nullable; no historical version is guessed. |
| [`2026_10_01_000001_add_venue_to_positions.php`](database/migrations/2026_10_01_000001_add_venue_to_positions.php) | Adds nullable `positions.venue`; marks paper rows and backfills live venue only from sufficiently clear evidence. | The column check and null-only live backfill allow a retry after interrupted MariaDB DDL. Ambiguous live rows remain unset and retain warning/fallback behavior. |
| [`2026_10_01_000001_widen_mariadb_columns_and_index_positions.php`](database/migrations/2026_10_01_000001_widen_mariadb_columns_and_index_positions.php) | Widens optimizer product IDs and numeric columns; indexes position mode/status/close time. | SQLite and MariaDB handle the generated product column differently. The down migration clamps numeric values before narrowing and may retain a wider generated column when long values already exist. |
| [`2026_10_06_000001_drop_retired_desk_knob_overrides.php`](database/migrations/2026_10_06_000001_drop_retired_desk_knob_overrides.php) | Removes exactly the two retired, unused saved override keys. | Those settings no longer have a runtime consumer. The down migration does not recreate the removed rows. |
| [`2026_10_23_000001_add_arena_seat_id_to_desk_runs.php`](database/migrations/2026_10_23_000001_add_arena_seat_id_to_desk_runs.php) | Adds the nullable seat foreign key, supporting index and evidence-based historical backfill. | Column/foreign-key/index checks support interrupted-DDL retries. Mixed or unprovable historical ownership remains unchanged. |

Do not treat rollback as a lossless substitute for restoring a coordinated
backup. Removing ownership/version columns discards their new metadata, narrowing
numeric columns can alter values, and retired configuration rows are not restored
by a down migration. MariaDB can commit DDL independently of the surrounding
application transaction.

Preserving `APP_KEY` matters independently of database migrations because the
application uses it for existing encrypted data and keyed authentication state.
Do not generate a replacement key as a routine upgrade step.

## Validation snapshot

The following is a **dated acceptance record for October 24, 2026**, associated
with the application tree merged as public commit
[`8836fae`](https://github.com/shoemoney/shoemoneyx/commit/8836fae404c58cd08419357f5572cb6fcf9cf040).
It is not a continuously updated test badge or a claim that a later checkout has
rerun these checks. Relevant source/tests are linked in the sections above.

| Check | Recorded result | Scope of the evidence |
|---|---|---|
| Full PHP suite on SQLite | 1,387 cases: 1,386 passed and one MariaDB-specific skip; no failures/errors. | Disposable SQLite configuration. |
| Full PHP suite on MariaDB | 1,387 passed; no failures/errors/skips. | Dedicated disposable MariaDB database and real Redis dependency. |
| Frontend unit tests | 90 passed. | Final password-form/CSRF and existing frontend behavior. |
| Feeder unit tests | 15 passed. | Feeder module tests; no claim of live-feed acceptance. |
| Production frontend build | Passed. | The resulting application assets built successfully. |
| AWS-style password browser journey | 106 checks passed. | Local self-signed HTTPS, explicit trust-state fixtures and real browser/API/database paths. |
| Local password browser journey | 69 checks passed. | Disposable non-AWS setup, password boundaries, sessions, token revocation and CSRF. |
| Overlapping authentication requests | Four claim/rotation rounds on each database engine; each had one successful writer and one conflict. | Actual overlapping HTTP requests on separate disposable SQLite and MariaDB profiles. |
| Application smoke | Six checks passed. | Onboarding, paper mode, strategy save/version/backtest and actual SDK tool execution with synthetic provider/market fixtures. |
| Settings password browser checks | Nine recorded checks passed. | Wrong-current rejection, rotation, CSRF refresh/next write, revocation, replacement login, desktop/mobile readability and fixture cleanup. |
| ACME and boot harnesses | Passed, including negative controls and credential cleanup checks. | Local command stubs and fixtures; no cloud resources created. |
| Certificate dependency hashes | Passed the pin/hash harness. | Package verification, including rejection controls; not certificate issuance. |
| Anonymous SDK installation | Passed with empty credentials/configuration and fresh dependency inputs. | The pinned public SDK can be fetched and its required classes loaded without a local SDK repository. |
| Hosted PR and post-merge tests | Passed. | Public [PR #28](https://github.com/shoemoney/shoemoneyx/pull/28), both PR container targets and the merged public source's test workflow. |

The PHP suites were run after the order-intent repair; the final Settings change
modified frontend code without changing that PHP implementation. The final
frontend and Settings browser checks cover the resulting source tree.

Security audit results also have a precise boundary: npm reported no known
vulnerabilities in that snapshot; Composer reported no security advisories but
returned a nonzero audit status for the existing abandoned `pear/console_table`
dependency. That is not an entirely green Composer audit and no advisory gate
was bypassed to obtain a success label.

<details>
<summary>What the local acceptance record does not prove</summary>

- Self-signed local HTTPS accepted by a browser harness does not establish
  ordinary browser trust in a public-CA certificate.
- A synthetic certificate-status marker does not prove live certificate issuance.
- The provider fixtures do not establish a current live provider account, billing
  state, model response or rate-limit behavior.
- Fake venue responses and synthetic market tape do not establish live order
  execution, liquidity, capacity or a strategy's economic validity.
- A PR's build-only container checks do not publish an application image.
- A successful boot of an earlier AMI does not validate a candidate built from
  this source.
- The older provider-backed E2E scripts are documented capabilities; they are
  not being claimed as rerun in this acceptance record.

</details>

## Release and live-acceptance boundaries

| Deliverable | Status represented by this changelog |
|---|---|
| Reviewed application source | Merged into public `main`. |
| 0.3.0 release tag/GitHub release | Pending; this documentation does not create one. |
| Updated published app/nginx images | Separate release operation; not established by the source merge. |
| New candidate AMI provenance | Required before live candidate acceptance. Record its source and both container digests. |
| Public CA/browser trust and first-owner login | Pending acceptance on the exact candidate. |
| Scheduled renewal and changed-public-IP trust | Pending live verification. |
| First-boot identity/credential uniqueness and cleanup | Required candidate acceptance, not inferred from local fixtures. |
| Marketplace delivery | Separate publication and metadata acceptance. |

Consult the [hosted-image guide](docs/HOSTED_IMAGE.md) and the actual
[release ledger](ops/image/releases.json). The ledger is historical evidence,
not a promise that the most recent source fixes are in the last recorded image.

## Historical public versions

Historical sections preserve what changed at the public tag or GitHub release.
Their old installation advice is not a substitute for the current
[upgrade guide](docs/UPGRADING.md). In particular, later fixes to password
rotation and trusted first login must not be attributed to an older published
image simply because they now appear in `main`.

### v0.2.4: owner password on first login

**GitHub release:** [September 30, 2026](https://github.com/shoemoney/shoemoneyx/releases/tag/v0.2.4).
**Source tag:** [v0.2.4](https://github.com/shoemoney/shoemoneyx/tree/v0.2.4).
**Previous tag comparison:** [v0.2.3...v0.2.4](https://github.com/shoemoney/shoemoneyx/compare/v0.2.3...v0.2.4).

This release separated the first-login key from the password chosen by the desk
owner. On an AWS install, the instance ID is the initial key; a Docker install
uses the value generated by `docker/up.sh`. Bootstrap access is restricted to
the choose-password flow rather than opening the full desk.

- The owner chooses a confirmed password, with hashing instead of storing a new
  plaintext owner password. Existing plaintext values are converted on upgrade.
- The bootstrap key stops opening the desk after owner setup.
- Owner-password changes require the current password.
- Switching to live mode asks for the password again, even from an authenticated
  browser session.
- Password-less legacy desks are brought back to the required setup path.
- Keyless setup is restricted to appropriate direct local/private-network access,
  with proxy/container exposure treated separately.
- The AWS login page adds regional-console guidance for finding the instance ID.
- Browser-session configuration supports the longer intended login lifetime;
  existing `.env` values still need operator review during upgrade.
- Scalar-only top-level settings and the password byte-size cap prevent invalid
  configuration shapes from being treated as ordinary saved values.

**Compatibility note:** v0.2.4's endpoint support did not provide the missing
post-onboarding Settings password form. The usable Settings rotation panel and
the later verification/concurrency hardening are listed under pending 0.3.0.

### v0.2.3: security and live-order hardening

**GitHub release:** [September 30, 2026](https://github.com/shoemoney/shoemoneyx/releases/tag/v0.2.3).
**Source tag:** [v0.2.3](https://github.com/shoemoney/shoemoneyx/tree/v0.2.3).
**Previous tag comparison:** [v0.2.2...v0.2.3](https://github.com/shoemoney/shoemoneyx/compare/v0.2.2...v0.2.3).

The release added a durable intent before live order submission, stable client
order IDs and reconciliation of ambiguous outcomes. A timeout or failed readback
could remain unknown rather than being mistaken for a definitive rejection and
resent as a fresh order.

- Reconciliation and operator resolution use exchange evidence; an open-orders
  list alone is not sufficient proof that an order never filled.
- Client-order-ID formatting accounts for the relevant adapter/venue requirements.
- Stuck orders can halt additional entries, while appropriate spot exits remain
  possible and blocked perps exits are reported.
- Concurrent desk cycles are serialized by mode, and cycle time is bounded
  below its lease so a long cycle does not continue opening positions unchecked.
- JSON-plugin sizing and leverage remain subject to the desk's risk caps.
- Risk setting ranges, nested keys and collation aliases are checked rather than
  allowing alternate key spellings to evade validation.
- Login and token guesses are throttled. Keyless cross-site writes are refused,
  and required-password mode fails closed without a usable credential.
- Optimizer/backtest websocket events use an authenticated private channel.
- Community tool output is treated as untrusted, and agent-side hub actions keep
  their application confirmation requirements.
- Self-signed TLS keys are generated per installation instead of baked into a
  public image shared by every install.
- SSRF protection, formula/definition size bounds, log-token redaction, API-log
  retention, nginx headers, build-context exclusions and CI token permissions
  receive security-focused changes.

**Compatibility note:** per-install certificate generation can produce a new
self-signed browser warning after upgrade. It is distinct from the public-CA
activation and candidate-AMI acceptance work described for pending 0.3.0.

### v0.2.2: master password removed from browser-visible state

**GitHub release:** [September 30, 2026](https://github.com/shoemoney/shoemoneyx/releases/tag/v0.2.2).
**Source tag:** [v0.2.2](https://github.com/shoemoney/shoemoneyx/tree/v0.2.2).
**Previous tag comparison:** [v0.2.1...v0.2.2](https://github.com/shoemoney/shoemoneyx/compare/v0.2.1...v0.2.2).

The browser previously received the master password in page state and
`localStorage`, and generic API configuration could expose it. This release
moved browser API authentication to the login session cookie and required the
session's CSRF token on writes.

- The master password is no longer embedded into rendered page state.
- Old `desk_token` local-storage state is cleared on load.
- Logout ends that browser's API authentication.
- Changing the password invalidates other browser sessions.
- Query-string `?token=` authentication is removed; scripts use the
  `X-Desk-Token` header instead.
- The generic settings API cannot return, replace, delete or shadow the password
  through case aliases or nested keys. Legacy offending rows are purged.
- Strategy-builder intent lint and dependency/documentation updates since the
  preceding tag are included, with fail-soft warning handling.

**Upgrade action:** an installation that exposed an owner password under an
earlier version should rotate it after upgrading. Preventing new disclosure
does not revoke a value already copied into an old page, browser extension or
log. Current source provides the Settings form needed after onboarding.

### v0.2.1: walkthrough and public-install dependencies

**GitHub release:** [September 23, 2026, UTC](https://github.com/shoemoney/shoemoneyx/releases/tag/v0.2.1).
**Source tag:** [v0.2.1](https://github.com/shoemoney/shoemoneyx/tree/v0.2.1).
**Previous tag comparison:** [v0.2.0...v0.2.1](https://github.com/shoemoney/shoemoneyx/compare/v0.2.0...v0.2.1).

The published release added a full product walkthrough with downloadable video
and captions. Its tour covers installation, onboarding, data/charts, desk cycles,
backtests, optimization and strategy construction.

The tag also replaced Font Awesome Pro dependencies with the free icon set and
regenerated the npm lockfile to remove stale licensed-package file paths. A clean
public installation no longer needs the former private icon-registry setup or
`FA_PRO_KEY` to build the application.

### v0.2.0: initial public source tag

**Source tag:** [September 21, 2026](https://github.com/shoemoney/shoemoneyx/tree/v0.2.0).
The verified public history contains this source tag; the GitHub release records
used for this changelog begin with v0.2.1.

The public source baseline includes the Laravel/Vue paper-trading desk,
exchange abstraction, trade-tape/resampled candle support, backtesting, farm
workers, walk-forward optimization and strategy-builder workflows.

The tag's launch scope specifically includes schema-v2 strategies,
lightweight-charts integration, generic starter templates and E2E journeys.
These are product capabilities and examples, not evidence that any included
strategy has a profitable live trading edge.

Private pre-public version history is outside this changelog. For reproducible
comparisons, use the public tags and the linked public source rather than
assuming an older internal version name describes a released ShoeMoneyX artifact.
