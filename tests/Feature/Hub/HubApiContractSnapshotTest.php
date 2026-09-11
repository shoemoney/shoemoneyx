<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use Tests\TestCase;

/**
 * Drift guard: every hub route path documented in docs/HUB_API.md's Accounts/Archive/
 * Social/Contests route tables must appear, in some normalized form, inside HubClient's
 * source. This is meant to fail loudly if someone adds a route to the contract and
 * forgets the client (the reverse direction — a client call with no matching contract
 * row — isn't checked: HubClient is built from the contract, so that's not the failure
 * mode this guards against).
 *
 * Parses only the plain `| METHOD | `/path` | ... |` table rows between the
 * "## Accounts" and "## v2: hosted exchange (not built yet)" headings — i.e. the
 * Accounts, Archive, Social and Contests sections. The "v2: hosted exchange" section
 * documents its (deliberately unimplemented) reserved routes as prose inside backticks
 * (`POST /contests/{slug}/orders`, `/paper/{exchange}/*`, etc.), not a table row, so the
 * regex naturally yields nothing from it — there is no table there to parse, and those
 * routes correctly have no HubClient caller. The "Desk integration" section further down
 * uses a differently-shaped table (desk-side proxy paths like `/api/hub/register` mapped
 * to hub routes, not hub routes themselves) and is deliberately excluded by stopping the
 * scan before the v2 section.
 */
class HubApiContractSnapshotTest extends TestCase
{
    public function test_every_documented_hub_route_has_a_matching_hubclient_call(): void
    {
        $doc = file_get_contents(base_path('docs/HUB_API.md'));
        $this->assertIsString($doc);

        $start = strpos($doc, '## Accounts');
        $end = strpos($doc, '## v2: hosted exchange');
        $this->assertIsInt($start, 'expected docs/HUB_API.md to have an "## Accounts" heading');
        $this->assertIsInt($end, 'expected docs/HUB_API.md to have a "## v2: hosted exchange" heading');

        $section = substr($doc, $start, $end - $start);

        preg_match_all('/^\|\s*(GET|POST|PATCH|DELETE)\s*\|\s*`([^`]+)`/m', $section, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'expected to find at least one route table row in docs/HUB_API.md');

        $paths = [];
        foreach ($matches as $m) {
            $paths[trim($m[2])] = true;
        }

        // Sanity check on the parser itself: the Accounts/Archive/Social/Contests tables
        // document well over 20 routes today. If this drops, the regex broke, not the doc.
        $this->assertGreaterThanOrEqual(20, count($paths), 'expected a substantial number of routes parsed out of docs/HUB_API.md');

        $clientSource = file_get_contents(app_path('Hub/HubClient.php'));
        $this->assertIsString($clientSource);

        // Normalize doc-style `{placeholder}` path segments to `{}`. Deliberately narrow for the
        // CLIENT source: it's whole PHP source, not just path strings, so a blanket `\{[^}]+\}`
        // also eats class/method braces (e.g. matches from a class's opening `{` all the way to
        // the first closing `}` it finds, mangling most of the file). HubClient only ever
        // interpolates path placeholders as `{$var}` (checked: every route method uses that
        // form, never bare `$var` or `${var}`), so normalizing just that shape is safe.
        $normalizeDocPath = fn (string $p): string => preg_replace('/\{[^}]+\}/', '{}', $p);
        $normalizeClientSource = fn (string $s): string => preg_replace('/\{\$[a-zA-Z_][a-zA-Z0-9_]*\}/', '{}', $s);

        $normalizedClientSource = $normalizeClientSource($clientSource);

        $missing = [];
        foreach (array_keys($paths) as $path) {
            if (! str_contains($normalizedClientSource, $normalizeDocPath($path))) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, 'docs/HUB_API.md documents route(s) with no matching call in HubClient: '.implode(', ', $missing));
    }
}
