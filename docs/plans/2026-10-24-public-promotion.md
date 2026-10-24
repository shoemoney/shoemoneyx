# Public source promotion: October 24, 2026

Promote the reviewed application source snapshot onto the public repository at base
`47d6c1bc01dfd351e93d0bf57e22176a8d2af012`. Use a branch based on public `main` and
curated tracked files, without importing development repository history. The promotion is code
publication only. It does not create release tags, publish containers, build or register an AMI,
submit a Marketplace delivery, or authorize live provider or trading charges.

## Plan

1. Import the selected application, infrastructure, test and product-documentation files from
   the tested source snapshot. Keep private agent instructions, internal plans, operational
   inventories, local environment files and credentials out of the public change. Record the
   source snapshot and imported-file manifest in the promotion's external evidence record.
2. Preserve public dependency upgrades and reconcile manifests with their lockfiles. The source
   lockfiles already contain the current required pins; do not roll public dependency versions
   back. Keep the public image release ledger unchanged.
3. Verify that a clean checkout installs the pinned `shoemoney/langchain-php` SDK through public
   HTTPS without authentication, a local SDK checkout or Composer path overrides. Confirm the
   pinned commit is anonymously fetchable and that installation reproduces the locked source.
4. Run the full PHP suite against isolated SQLite and dedicated disposable MariaDB databases,
   frontend and feeder unit tests, and the production frontend build. Run the ACME shell harness,
   boot-test Python harness, and Python 3.12 certbot dependency-pin harness. Capture actual
   commands and outcomes; resolve failures before publication.
5. Run the disposable local and AWS-style authentication browser journeys, the password
   concurrency request journey, and application smoke with synthetic market data. Exercise the
   first-owner password and confirmation path, bootstrap invalidation, password rotation and
   concurrency, persisted authentication, strategy save and backtest outcomes. Follow
   [the E2E setup](../E2E.md) and its separate disposable profiles. Local TLS fixtures do not
   establish public CA or AMI acceptance. Provider-backed journeys require separate authorization
   and are outside this promotion's required acceptance.
6. Check the complete public diff for secrets, private links, local paths, missing files and
   unintended regressions. Obtain two fresh independent reviews of the final committed range,
   address findings, and repeat affected checks. Reviews must cover the exact final commits.
7. Publish the reviewed branch and public pull request only within the authorized publication
   scope. Wait for the authoritative hosted CI checks on that exact head, fix failures, and merge
   only when CI and both final reviews pass. Record the resulting public commit and validation
   evidence without claiming an image release.

## Acceptance boundaries

The Studio configuration uses the normal public clone's `origin` remote and `main` branch.
Its CI commands are the project's actual local checks; the certbot harness requires Python 3.12
and network access to the public package index. Full MariaDB and browser acceptance use
separate disposable infrastructure and are recorded alongside those commands.

A successful code promotion confirms only the reviewed public source and its recorded tests.
Candidate image provenance, booting a newly built AMI, public CA browser trust, real certificate
renewal, trusted TLS after an IP change, and Marketplace delivery remain separate acceptance
work described in [the hosted-image guide](../HOSTED_IMAGE.md#live-candidate-acceptance).
The historical [image release ledger](../../ops/image/releases.json) is not updated by this plan.
