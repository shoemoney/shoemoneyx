import test from "node:test";
import assert from "node:assert/strict";
import {
    deskChromeState,
    matchingNavigation,
} from "../../../resources/js/siteShell.js";

const links = [
    "/",
    "/dashboard",
    "/chart",
    "/desk",
    "/positions",
    "/backtests",
    "/optimizer",
    "/arena",
    "/settings",
].map((path) => ({ path }));
test("navigation keeps the parent tab active on detail routes without prefix collisions", () => {
    for (const [path, expected] of [
        ["/", "/"],
        ["/chart/BTC-USD", "/chart"],
        ["/desk/42", "/desk"],
        ["/backtests/19", "/backtests"],
        ["/settings", "/settings"],
    ])
        assert.equal(matchingNavigation(path, links)?.path, expected);
    for (const path of [
        "/dashboardish",
        "/landing/pulse",
        "/designs",
        "/singularity/nanites",
    ])
        assert.equal(matchingNavigation(path, links), null);
});
test("missing status never fabricates readiness, running, or successful checks", () => {
    const view = deskChromeState(null);
    assert.equal(view.ready, "Unknown");
    assert.equal(view.loop, "Unknown");
    assert.equal(view.mode, "Unknown");
    assert.equal(view.tone, "neutral");
    assert.deepEqual(view.checks, []);
});
test("health maps actual mode, readiness, loop, halt, and each check independently", () => {
    const view = deskChromeState({
        health: {
            mode: "paper",
            strategy: "mr",
            ready: false,
            running: true,
            halted: { reason: "risk ceiling" },
            checks: { api_key: true, live_confirm: false, unknown_check: null },
        },
    });
    assert.equal(view.strategy, "mr");
    assert.equal(view.summary, "Desk halted");
    assert.equal(view.ready, "No");
    assert.equal(view.loop, "Running");
    assert.equal(view.tone, "error");
    assert.equal(view.halted, "HALTED: risk ceiling");
    assert.deepEqual(
        view.checks.map((check) => [check.name, check.state]),
        [
            ["api key", "Pass"],
            ["live confirm", "Fail"],
            ["unknown check", "Unknown"],
        ],
    );
});
test("a failed status refresh clears old success from the chrome", () => {
    const view = deskChromeState(
        {
            health: {
                mode: "live",
                strategy: "mr",
                ready: true,
                running: true,
                checks: { feed: true },
            },
        },
        "Request failed",
    );
    assert.equal(view.summary, "Status unavailable");
    assert.equal(view.ready, "Unknown");
    assert.equal(view.loop, "Unknown");
    assert.deepEqual(view.checks, []);
    assert.equal(view.error, "Request failed");
});
test("partial health does not imply boolean truth or produce undefined labels", () => {
    const view = deskChromeState({ health: {} });
    assert.equal(view.summary, "Unknown · Readiness unknown");
    assert.equal(view.ready, "Unknown");
    assert.equal(view.loop, "Unknown");
    assert.equal(view.tone, "neutral");
});
