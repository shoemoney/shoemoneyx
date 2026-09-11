import test from "node:test";
import assert from "node:assert/strict";
import { pageMetadata } from "../../../resources/js/siteMetadata.js";

test("SPA navigation gives each public page its own metadata and guide", () => {
    const titles = new Set();
    for (const path of ["/", "/dashboard", "/chart", "/desk", "/positions", "/backtests", "/optimizer", "/arena", "/settings"]) {
        const page = pageMetadata(path + "?tracking=discarded", "https://shoemoneyx.com/", true);
        assert.equal(page.url, "https://shoemoneyx.com" + path);
        assert.match(page.robots, /^index,/);
        assert.ok(page.markdown.endsWith(".md"));
        assert.ok(page.showContext);
        titles.add(page.title);
    }
    assert.equal(titles.size, 9);
});

test("detail pages consolidate to their parent; previews and private mode stay unindexed", () => {
    const detail = pageMetadata("/chart/BTC-USD", "https://shoemoneyx.com", true);
    assert.equal(detail.url, "https://shoemoneyx.com/chart");
    assert.equal(detail.robots, "noindex, follow");
    assert.equal(detail.markdown, "https://shoemoneyx.com/chart.md");
    for (const path of ["/designs", "/landing/pulse", "/dashboardish"]) {
        const page = pageMetadata(path, "https://shoemoneyx.com", true);
        assert.equal(page.robots, "noindex, follow");
        assert.equal(page.showContext, false);
    }
    const privatePage = pageMetadata("/dashboard", "http://localhost", false);
    assert.equal(privatePage.robots, "noindex, follow");
    assert.equal(privatePage.markdown, null);
    assert.doesNotMatch(privatePage.description, /demo|simulated/i);
});
