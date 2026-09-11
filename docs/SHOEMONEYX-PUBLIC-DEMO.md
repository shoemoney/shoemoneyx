# ShoeMoneyX public demo

The entire Laravel/Vue site is public at https://shoemoneyx.com. This deployment uses synthetic data only, as requested on September 8, 2026. It does not run the trading desk, feeder, backtest workers, optimizer, Reverb, or Laravel scheduler.

## Behavior

- `SITE_DEMO=true` enables `PublicDemo` before route bindings and controller construction. Known read endpoints use `DemoData`, with no database or outbound requests. Unknown API reads return 404. Every non-GET/HEAD request returns 403 before any trading or setting mutation.
- Every page displays an interactive demo banner. Trading, position-close, configuration-save and new-backtest controls are disabled. Filters, detail views, chart intervals, effects controls and saved sample experiments work.
- The front page uses its existing explicitly labeled 18-market presentation simulation. The inner pages use a separate synthetic account, candidate, position, quote, candle and research dataset. These are illustrative views, not an actual shared trading account or evidence of returns.
- The optimizer and arena use browser-local paired score events. No websocket server, exchange credentials, database, background job, or schedule is required. Events pause in hidden tabs and their timers stop on navigation.
- Charts expose 15s, 1m and 5m intervals; each synthetic history response is bounded to 600 candles. Both `COINBASE:` and `DEMO:` symbol prefixes are normalized.
- `SITE_DEMO` defaults to false for the private LAN deployment. Public mode is a deployment setting, never a query parameter that enables real operations.

## Hosting

- Existing EC2 server: `shoemoney.com` / `100.49.4.12`, instance `i-06a6e6157f9d5980b` in us-east-1. SSH uses the pre-existing `shoemoney.com` host configuration. No credentials are stored here.
- Application: `/var/www/shoemoneyx.com/current` → `releases/20260908-demo`.
- Private runtime settings: `/var/www/shoemoneyx.com/shared/.env`; writable files in `shared/storage`. New application key, file sessions/cache, production mode, debug off, no live environment copied.
- Separate PHP 8.4 FPM pool, operating system user `shoemoneyx`, socket `/run/php/shoemoneyx-fpm.sock`. On-demand processes, maximum four. The pool pins `SITE_DEMO=true`, confines filesystem access to this application and disables shell execution.
- Nginx configuration: `/etc/nginx/sites-available/shoemoneyx.com`, linked from sites-enabled; app snippet `/etc/nginx/snippets/shoemoneyx-app.conf`.
- HTTPS certificate covers apex and www; www and HTTP redirect to https://shoemoneyx.com. Existing Certbot renewal handles certificate maintenance. The app itself has no scheduled work.
- Only apex and www A records in Route53 zone `Z1X3YWGPBSFGXD` changed from `44.197.10.5` to `100.49.4.12`, TTL 60. MX, TXT, NS and every other subdomain are unchanged.
- Existing ShoeMoney.com and other sites retain their original virtual hosts and content.

## Updating the website

Use an isolated checkout of the reviewed `master` commit. Install PHP dependencies independently; do not symlink another checkout's optimized Composer autoloader because its App/Tests classmap can execute the wrong source. Install the existing frontend dependencies with the locally available licensed icon packages, then build assets. No dependency upgrades are required; this release only synchronizes the lockfile's already-declared PHP ^8.4 platform metadata.

Create a new release directory with application source, routes, config, Blade resources, Composer manifests, public assets and the production Vite build. Do not transfer `.env`, database files, logs, credentials, research outputs, or the Git directory. Install production Composer dependencies from the lock with scripts initially disabled. Link the existing demo environment and storage; discover packages and cache configuration/routes/views as `shoemoneyx`. Check that the cached demo setting is true before activation. Retain prior hashed assets for browsers already open. Atomically switch `current` only after validation.

Templates for the dedicated PHP pool and Nginx HTTP/HTTPS setup are in `deploy/shoemoneyx/`. The app snippet intentionally omits a `$uri/` directory fallback: the public `arena` asset directory must not intercept the Vue `/arena` route. Validate Nginx/FPM before graceful reload. Never restart unrelated trading or website processes.

## Validation

Local validation passed 34 frontend tests, 8 Laravel feature tests with 11,056 assertions, PHP formatting and the production Vite build. Coverage includes all nine main pages, synthetic read endpoints without database/desk resolution, mutation blocking, unknown endpoints, bounded/repeatable valid candles, symbol aliases, account totals and simulation timer cleanup. The build retains the existing large Three.js/ECharts chunk advisory.

Before DNS change, all nine server pages returned 200 with the demo banner; trading/settings/position/backtest writes returned 403. The public HTTPS deployment passed probes for all nine pages, 31 read endpoints, six blocked mutations, apex/www redirects and private-file denial. A real browser verified the formed robot, typing hands, eye scan and pulsing coins; populated dashboard; 15-second chart; changing research stream; navigation across all main pages; saved experiment and read-only controls. No browser console errors were observed. The existing ShoeMoney homepage and nohumans.net redirect/content remained healthy.

The old GitHub workflow failed because root `npm ci` referenced untracked licensed icon archives. CI now uses a separate locked public test dependency set and runs both feeder and frontend suites; an isolated check without icon archives or the main node_modules passed all 49 JavaScript tests. Production build dependencies are unchanged.

## Rollback and cleanup

Future application rollback: restore the previous `current` symlink, retain `shared/.env` with demo enabled, and refresh only this app's caches/pool as needed. Never point this public host at the LAN live configuration.

Initial DNS rollback: restore only the two A records to `44.197.10.5` from the recorded backup. That old address was not verified as a usable current website; reverting DNS is not a promise of application recovery. Leave existing other sites unchanged.

Completed website worktrees and old preview processes were cleaned up after merge, with private runtime backups under `~/.claude/archives/`. Unmerged design prototypes and active research branches were preserved. The unrelated strategy checkout was not modified.

## Search, social previews and AI discovery

The nine main public pages have unique server-rendered titles, descriptions, canonical URLs, Open Graph and X/Twitter cards, plus Organization, WebSite and WebPage JSON-LD. `resources/content/site-pages.json` is the shared copy source for Laravel, Vue navigation, the sitemap and Markdown guides. Vue updates the head and the visible page summary after navigation completes. Canonical URLs use `APP_URL`, exclude tracking parameters and consolidate detail views to their parent page. Keep production `APP_URL=https://shoemoneyx.com`.

The public discovery routes are `/robots.txt`, `/sitemap.xml`, `/llms.txt`, `/llms-full.txt`, `/ai.txt`, `/.well-known/ai.txt`, `/index.md` and one `.md` guide for each main inner page. They are generated by Laravel, so remove any old static `public/robots.txt` when deploying. The sitemap lists only the nine main pages and intentionally omits `lastmod` because simulation ticks do not represent editorial changes. Detail views and experimental galleries have `noindex`; the private deployment disallows crawlers and does not serve the public demo guides. `ai.txt` is informational, contains no invented contact or license, and grants no additional training or copyright rights. The deprecated `ai-plugin.json` format is intentionally not advertised.

`public/brand/shoemoneyx-home-og.jpg` is a 1200 × 630 JPEG (~93 KB). It uses an actual browser screenshot of the public homepage's formed robot and market cards, formatted with a ShoeMoneyX wordmark, blue X, public-demo label and domain. The screenshot was captured through computer use, framed in a temporary HTML card, and recaptured at the final size; the robot and market screenshot were not regenerated. The image type, dimensions, HTTPS URL and alt text are declared in the head. No Facebook app ID or account token is needed to serve the link-preview metadata. Existing Facebook shares can retain their cached preview until Meta refreshes the URL.

Source references: https://airanks.net/llm-web-indexing-files, https://llmstxt.org/, https://ogp.me/.
