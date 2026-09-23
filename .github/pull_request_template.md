## What changed

<!-- One sentence. What does this PR make the software do differently? -->

## Why

<!-- The problem this solves. Link the issue if there is one: Fixes #123 -->

## What you expected vs what happened

<!-- If this is a bug fix, state the broken behaviour and the fixed behaviour.
     If it is a new feature, delete this section. -->

- **Before:**
- **After:**

## How you tested it

<!-- Be specific. "It works" is not testable by a reviewer. -->

- [ ] `php artisan test` is green (never `--parallel`, Collision 8.x rejects it)
- [ ] `npm run test:frontend` is green
- [ ] Added or updated a test that fails without this change
- [ ] Ran it against a paper desk and watched the behaviour change

**Exchange and timeframe tested:**

## Risk

<!-- Delete the lines that do not apply. -->

- [ ] Touches order placement, sizing, or the risk check
- [ ] Changes a database migration (check string column widths — tests run SQLite, production runs MariaDB in strict mode)
- [ ] Changes the strategy schema or formula grammar
- [ ] Changes an exchange adapter contract shared by other venues
- [ ] None of the above, this is cosmetic or docs only

## Checklist

- [ ] No API keys, secrets, or account ids anywhere in the diff
- [ ] No proprietary strategy included
- [ ] Conventional commit title (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`)
- [ ] Adapter PRs only: fixtures recorded and a conformance test added, so it badges `passed` and not `unverified`
