# Password and first-login browser acceptance

Run `password-security.mjs` against **fresh disposable databases only**. It sets and rotates
synthetic owner passwords, completes onboarding and starts the paper desk. It must never run
against a shared or production install. The script refuses non-loopback URLs.

The two profiles use separate databases, sessions and caches:

- `E2E_PROFILE=local`: no `MASTER_PASSWORD`, no AWS guide, direct local network client.
- `E2E_PROFILE=aws`: set `MASTER_PASSWORD` to the synthetic `BOOTSTRAP` constant in
  [`password-security.mjs`](password-security.mjs),
  `DESK_FIRST_LOGIN_GUIDE=aws`, `DESK_TLS_STATUS_PATH` matching `E2E_TLS_STATUS_PATH`.

For a separate fresh profile with the same synthetic bootstrap key but no AWS guide,
`password-concurrency.mjs` submits overlapping first-owner and rotation requests to
at least two FPM workers. It requires exactly one winner, rejects the losing and
previous credentials, and fails on server errors. Use its own database and cache;
do not run it against a profile already used by the browser journey.

After the local browser journey completes, the broader application smoke can seed
a synthetic tape and exercise UI strategy validation/save/version/backtest:

```sh
E2E_FIXTURE_MODE=1 DB_DATABASE=/absolute/output/playwright/runtime/local.sqlite \
  php tests/e2e/fixtures/seed-market.php
E2E_BASE=https://127.0.0.1:18444 E2E_PASSWORD=e2e-rotated-owner-password-2026 \
  PLAYWRIGHT_PATH=/opt/homebrew/lib/node_modules/playwright E2E_BROWSER_CHANNEL=chrome \
  node tests/e2e/application-smoke.mjs
```

The seed helper refuses databases outside the disposable runtime directory. These
synthetic trades verify execution paths and do not establish a strategy's performance.

Serve the production frontend through nginx and PHP-FPM. Configure HTTPS and HTTP listeners;
nginx must pass its actual `$https` value as FastCGI `HTTPS` rather than treating client
`X-Forwarded-Proto` as authoritative. Point nginx's PHP front controller `SCRIPT_FILENAME`
at `tests/e2e/fixtures/http.php` and set FastCGI `E2E_FIXTURE_MODE` to `1`. Keep the document
root at `public/` so test fixtures cannot be fetched as public files.

The fixture entry point requires both explicit opt-in and loopback `REMOTE_ADDR`. It boots the
real application in a non-`testing` environment, preserving CSRF, sessions, validation, settings,
provider adapter, usage auditing and the LangGraph tool runtime. Only the outbound Laravel HTTP
transport has deterministic OpenRouter/catalog responses; unconfigured outbound requests throw.
It does not bypass app API endpoints. A fixture-backed provider turn proves local integration,
not external provider availability or real model behavior.

Generate a new `APP_KEY` and use a fresh SQLite file or dedicated MariaDB database. Set
`DB_URL=`, `OPENROUTER_API_KEY=`, blank exchange/AWS/hub credentials, `DESK_MODE=paper`,
`DESK_LIVE_CONFIRM=no`, and dedicated file session/cache paths or a dedicated Redis process.
Use an environment allowlist for FPM and artisan; do not copy the operator's `.env`. Avoid
`APP_ENV=testing`, which disables normal web CSRF checks.

Example invocation once both disposable runtime profiles are ready:

```sh
E2E_PROFILE=local E2E_BASE=https://127.0.0.1:18444 \
  E2E_HTTP_BASE=http://127.0.0.1:18132 \
  PLAYWRIGHT_PATH=/opt/homebrew/lib/node_modules/playwright \
  node tests/e2e/password-security.mjs

E2E_PROFILE=aws E2E_BASE=https://127.0.0.1:18443 \
  E2E_HTTP_BASE=http://127.0.0.1:18131 \
  E2E_TLS_STATUS_PATH=/absolute/disposable/tls-status \
  PLAYWRIGHT_PATH=/opt/homebrew/lib/node_modules/playwright \
  node tests/e2e/password-security.mjs
```

`ignoreHTTPSErrors` accepts the disposable local certificate; synthetic marker changes test the
application trust policy. This does **not** prove public CA issuance, browser CA validation on
an AMI, real renewal, or Marketplace publication. Those remain separate acceptance steps.
If Playwright's downloaded Chromium is unavailable, set `E2E_BROWSER_CHANNEL=chrome` to use an
installed Google Chrome. The selected browser still runs in a fresh temporary profile.

Screenshots, rendered typography/overflow measurements and machine-readable checks are written
under ignored `output/playwright/password-security/<profile>/`, or `E2E_OUT`. The journey fails
on any assertion, JavaScript page error, unexpected console error, unreadable auth/onboarding text
below 18 CSS px, overflow or unbound rendered values. It authenticates cookie-only requests and
explicit header clients separately; a default `X-Desk-Token` cannot hide a broken browser session.
