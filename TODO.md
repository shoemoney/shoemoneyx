# Public roadmap

Checked items describe features implemented in the source candidate. The current promotion
still requires its final reviews and passing hosted CI before merge. These checkboxes do not
establish acceptance of a newly built AMI, a Marketplace listing, or a production deployment.

## Source distribution

- [x] Public source, license, contribution guidelines, code of conduct and security policy.
- [x] Bring-your-own OpenRouter and exchange credentials; generate installation secrets locally.
- [x] Keep licensed TradingView charting assets out of the repository and provide installation
  instructions plus a clear chart notice when the library is absent.
- [x] Keep research datasets and proprietary strategy implementations out of the public source;
  configure optional farm hosts through environment variables.

## Exchange modularity

- [x] Market data, execution, account and credential contracts with exchange capability manifests.
- [x] Exchange registry and provider discovery; Coinbase implementation behind the contracts.
- [x] Generic CCXT REST adapter and exchange conformance tests using recorded fixtures without keys.
- [x] Normalize market-data shapes in adapters and consume those shapes consistently.
- [x] Exchange directory and documented conformance badge requirements.
- [ ] Decide which exchanges need native adapters after Coinbase.
- [ ] Revisit splitting the Coinbase adapter into a Composer package once contracts settle.

## Strategies as data

- [x] Validated JSON strategy schema covering setup, trigger, entry, management, exit and risk.
- [x] Semantic versions stored per strategy edit; backtests reference immutable strategy versions.
- [x] Enforce management and risk sections in the JSON runner.
- [x] Pin strategy versions in optimizer and farm sweep backtests.

## Strategy Builder agent

- [x] OpenRouter OAuth PKCE connection flow and application-side model transport, limits and auditing.
- [x] Free-model default, model selection and contextual backtest-review guidance.
- [x] Guided strategy-building phases with tools to save, backtest, review and compare versions.
- [x] LangChain/LangGraph runtime with a public pinned SDK, bounded tool calls and retained tool results.
- [x] Pluggable MCP server configuration; TradingView MCP requires a separately running compatible
  desktop setup and is not enabled by default.
- [x] Background backtest worker using the user's provider key with rate-limit backoff.

## Paper Arena

- [x] Independent paper accounts for multiple strategy versions on the same market snapshot.
- [x] Seat-owned positions, fills, cash, equity snapshots and desk runs, with main-desk history kept
  separate and legacy unattributed history documented.
- [x] Scoreboard with equity, PnL, drawdown, win rate and trades; champion promotion and seat retirement.
- [x] Agent tools to start a seat and inspect the scoreboard.
- [x] Tests for independent seat ledgers and champion promotion.

## Community Hub integration

The desk remains single-user. Community features use the separate Hub API; paper trading runs
locally and contest reports contain the desk's reported fills and equity.

- [x] Public strategy-repository sync and JSON plugin import without a Hub account.
- [x] Documented Hub contract for accounts, profiles, follows, archive versions, comments, search,
  activity feeds, contests and leaderboards.
- [x] Hub client and encrypted connection storage; registration credentials stay in the Hub panel.
- [x] Confirmed strategy publication with semantic version and changelog.
- [x] Archive search, preview and published-version import.
- [x] Contest entry with a frozen strategy version in an Arena seat, withdrawal and periodic reports.
- [x] Hub client tests with fake HTTP responses.
- [ ] Hosted exchange execution remains unimplemented; reserved routes are not supported client APIs.

## Self-hosting and image acceptance

- [x] Docker Compose distribution with application, nginx, desk, queue, scheduler, Reverb, feeder,
  MariaDB and Redis services; local secret generation and tagged multi-architecture image tooling.
- [x] Version-pinned per-customer image tooling with first-boot setup, firewall policies and updates
  through the published Docker images.
- [x] Release orchestration, boot-test harness and per-version image release ledger.
- [x] First-login guide, one-time bootstrap key, owner password confirmation and bootstrap invalidation.
- [x] ACME certificate issuance/renewal tooling, bounded challenge windows and local failure harnesses.
- [ ] Complete live acceptance for a newly built candidate with its source commit and container
  digests recorded; earlier release records do not validate later source changes.
- [ ] Verify public CA issuance and normal browser trust, first-owner login, actual scheduled
  certificate replacement and continued owner access after a changed public IP receives its own
  trusted certificate.
- [ ] Complete Marketplace delivery and public-listing acceptance for the accepted candidate.

See [E2E journeys](docs/E2E.md), [Arena account history](docs/ARENA_ACCOUNTS.md),
[Hub API](docs/HUB_API.md) and [hosted-image acceptance](docs/HOSTED_IMAGE.md#live-candidate-acceptance)
for test scope and acceptance requirements. Image releases remain recorded separately in
[the release ledger](ops/image/releases.json).
