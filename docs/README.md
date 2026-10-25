# Documentation

Start with the task you need to complete. The [project README](../README.md)
explains installation choices; the [changelog](../CHANGELOG.md) is the detailed
release record.

> Pending 0.3.0 behavior is available in source. A merged commit is not a new
> registry image, AMI or Marketplace delivery. Read
> [Upgrading](UPGRADING.md) before changing an existing desk.

## Install, operate and recover

| Task | Guide |
|---|---|
| Upgrade while retaining keys, credentials and data | [Upgrading](UPGRADING.md) |
| Build or validate a per-customer AWS image | [Hosted image](HOSTED_IMAGE.md) |
| Understand risk escalation and scoped recovery | [Risk](RISK.md) |
| Add backtest workers and manage farm capacity | [Farm](FARM.md) |
| Select an adapter and understand its capability evidence | [Exchanges](EXCHANGES.md) |
| Inspect ownership across the main desk and arena | [Arena accounts](ARENA_ACCOUNTS.md) |

## Build and share strategies

| Task | Guide |
|---|---|
| Start from a generic teaching example | [Starter templates](STARTER_TEMPLATES.md) |
| Work with versioned JSON strategy plugins | [Strategy plugins](STRATEGY_PLUGIN.md) |
| Author the current structured strategy format | [Schema v2](STRATEGY_SCHEMA_V2.md) |
| Read the original schema reference | [Strategy schema](STRATEGY_SCHEMA.md) |
| Understand guided agent phases, tools and runtime | [Strategy agent](AGENT.md) |
| Import and update community definitions | [Strategy sync](STRATEGY_SYNC.md) |
| Understand community-hub routes and confirmation boundaries | [Hub API](HUB_API.md) |

Examples teach the schema and software behavior. They are not claims of a trading
edge. A saved strategy version, a completed backtest and live venue acceptance are
different outcomes.

## Develop and validate

| Task | Guide |
|---|---|
| Run isolated browser, security and application journeys | [E2E](E2E.md) |
| Check the retained validation evidence and its limits | [Changelog validation snapshot](../CHANGELOG.md#validation-snapshot) |
| Add an adapter with recorded fixtures | [Contributing](../CONTRIBUTING.md) |
| Change a native Coinbase API call | [Coinbase documentation map](COINBASE_DOCS.md) |
| Understand dashboard refresh behavior | [Dashboard reactivity](DASHBOARD-REACTIVITY.md) |
| Work on the public read-only showcase | [Public demo](SHOEMONEYX-PUBLIC-DEMO.md) |
| Report a security issue | [Security policy](../SECURITY.md) |

The current installation and acceptance instructions are the operational source
of truth. Historical design documents describe decisions and visual work; they
should not replace current command signatures or validation requirements.

<details>
<summary>Design and interface references</summary>

| Area | References |
|---|---|
| Site shell and navigation | [Site chrome design](SITE-CHROME-DESIGN.md) · [Implementation](SITE-CHROME-IMPLEMENTATION.md) |
| Site experience and launch | [Workflow](SITE-EXPERIENCE-WORKFLOW.md) · [Front-page launch](FRONT-PAGE-LAUNCH.md) · [Landing](LANDING.md) |
| Brand and visual editions | [Brand rollout](BRAND-ROLLOUT.md) · [Blue editions](BLUE-EDITIONS.md) |
| Motion and effects | [Singularity effects](SINGULARITY-EFFECTS.md) · [Picaso](PICASO.md) |
| Robot and centerpiece studies | [Robot armor](ROBOT-ARMOR.md) · [Coin scan](ROBOT-COIN-SCAN.md) · [Guardian centerpiece](GUARDIAN-CENTERPIECE.md) |
| Arena interface study | [Arena improvement](design/arena-improve.md) |

</details>

## Reading the release record

The [changelog](../CHANGELOG.md) separates pending source changes from historical
public tags and releases. Its migration and upgrade sections describe what an
operator must account for. The validation snapshot identifies the source and
test conditions; it does not turn synthetic results into live provider, venue or
public-certificate acceptance.
