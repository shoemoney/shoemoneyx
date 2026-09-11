// Laravel owns the chrome. Vue only enhances links and publishes its existing data.
export function matchingNavigation(path, links) {
    return (
        links.find(
            (link) =>
                path === link.path ||
                (link.path !== "/" && path.startsWith(link.path + "/")),
        ) || null
    );
}

export function deskChromeState(status, error = "") {
    const health = error ? null : status?.health;
    const mode = health?.mode || "Unknown";
    return {
        mode,
        strategy: health?.strategy || "Unknown",
        ready:
            health?.ready === true
                ? "Yes"
                : health?.ready === false
                  ? "No"
                  : "Unknown",
        loop:
            health?.running === true
                ? "Running"
                : health?.running === false
                  ? "Stopped"
                  : "Unknown",
        summary: !health
            ? error
                ? "Status unavailable"
                : "Awaiting desk data"
            : health.halted
              ? "Desk halted"
              : health.ready === true
                ? mode + " · Ready"
                : health.ready === false
                  ? mode + " · Not ready"
                  : mode + " · Readiness unknown",
        tone: !health
            ? "neutral"
            : health.halted || health.ready === false
              ? "error"
              : health.ready === true
                ? "ok"
                : "neutral",
        halted: health?.halted?.reason
            ? "HALTED: " + health.halted.reason
            : health?.halted
              ? "HALTED"
              : "",
        checks: Object.entries(health?.checks || {}).map(([name, ok]) => ({
            name: name.replaceAll("_", " "),
            tone: ok === true ? "ok" : ok === false ? "error" : "neutral",
            state: ok === true ? "Pass" : ok === false ? "Fail" : "Unknown",
        })),
        error,
    };
}
const find = (key) => document.querySelector("[data-site-" + key + "]");
function text(key, value, tone) {
    const el = find(key);
    if (!el) return;
    el.textContent = value;
    if (tone) el.dataset.tone = tone;
}
function show(key, visible) {
    const el = find(key);
    if (el) el.hidden = !visible;
}

export function publishDeskStatus(status, error = "") {
    if (document.body.dataset.shellMode !== "desk") return;
    const view = deskChromeState(status, error);
    text("summary", view.summary);
    if (find("indicator")) find("indicator").dataset.siteIndicator = view.tone;
    for (const key of ["mode", "strategy", "ready", "loop"])
        text(
            key,
            view[key],
            key === "ready"
                ? view.tone
                : key === "mode" && view.mode === "live"
                  ? "warning"
                  : "neutral",
        );
    text("error", view.error);
    show("error", !!view.error);
    text("halted", view.halted);
    show("halted", !!view.halted);
    const checks = find("checks");
    checks?.replaceChildren(
        ...view.checks.map((check) => {
            const chip = document.createElement("span");
            chip.dataset.tone = check.tone;
            chip.textContent = check.name + " · " + check.state;
            return chip;
        }),
    );
    show("health-detail", !!(view.error || view.halted || view.checks.length));
}

export function publishPublicStatus({ mode, source, live, note }) {
    if (document.body.dataset.shellMode !== "home") return;
    text("summary", source || "Awaiting market data");
    text("public-note", note || "");
    show("public-note", !!note);
    text(
        "public-source",
        [mode, source].filter(Boolean).join(" · ") || "Awaiting market data",
    );
    if (find("indicator"))
        find("indicator").dataset.siteIndicator = live ? "ok" : "neutral";
}

export function installSiteChrome(router) {
    const header = find("header");
    if (!header) return () => {};
    const menu = find("menu");
    const links = [...header.querySelectorAll("[data-site-nav]")].map((el) => ({
        el,
        path: el.getAttribute("href"),
    }));
    const closeMenu = (restoreFocus = false) => {
        const wasOpen = menu.getAttribute("aria-expanded") === "true";
        menu.setAttribute("aria-expanded", "false");
        header.classList.remove("smx-menu-open");
        if (restoreFocus && wasOpen) menu.focus();
    };
    const toggleMenu = () => {
        const open = menu.getAttribute("aria-expanded") !== "true";
        menu.setAttribute("aria-expanded", String(open));
        header.classList.toggle("smx-menu-open", open);
    };
    const navigate = (event) => {
        const anchor = event.target.closest?.("a[data-site-link]");
        if (
            !anchor ||
            event.defaultPrevented ||
            event.button !== 0 ||
            event.ctrlKey ||
            event.metaKey ||
            event.altKey ||
            event.shiftKey ||
            anchor.hasAttribute("download") ||
            (anchor.target && anchor.target !== "_self")
        )
            return;
        const url = new URL(anchor.href);
        if (url.origin !== location.origin) return;
        event.preventDefault();
        const wasMobileMenu = menu.getAttribute("aria-expanded") === "true";
        closeMenu();
        router.push(url.pathname + url.search + url.hash).then(() => {
            if (wasMobileMenu)
                document
                    .getElementById("site-main")
                    ?.focus({ preventScroll: true });
        });
    };
    const keyboard = (event) => {
        if (event.key === "Escape") closeMenu(true);
    };
    const visibility = () =>
        document.body.classList.toggle("smx-effects-paused", document.hidden);
    const update = (route, from) => {
        const active = matchingNavigation(route.path, links);
        const mode = !active
            ? "gallery"
            : active.path === "/"
              ? "home"
              : "desk";
        const previousMode = document.body.dataset.shellMode;
        const main = document.getElementById("site-main");
        if (mode === "desk") main?.setAttribute("role", "main");
        else main?.removeAttribute("role");
        document.body.dataset.shellMode = mode;
        document.body.classList.toggle("smx-app", !!active);
        show("header", !!active);
        show("footer", !!active);
        show("health", mode === "desk");
        show("public", mode === "home");
        if (mode !== "desk") show("health-detail", false);
        links.forEach(({ el }) => {
            if (el === active?.el) el.setAttribute("aria-current", "page");
            else el.removeAttribute("aria-current");
        });
        if (active) {
            text("page", active.el.textContent.trim());
            text("eyebrow", active.el.dataset.siteEyebrowValue);
            document.title = "ShoeMoneyX — " + active.el.textContent.trim();
        }
        if (mode !== previousMode || !from) {
            if (mode === "desk") publishDeskStatus(null);
            if (mode === "home") publishPublicStatus({});
        }
        closeMenu();
    };
    header.classList.add("smx-enhanced");
    menu.hidden = false;
    menu.addEventListener("click", toggleMenu);
    document.addEventListener("click", navigate);
    document.addEventListener("keydown", keyboard);
    document.addEventListener("visibilitychange", visibility);
    visibility();
    const removeRouteHook = router.afterEach((to, from, failure) => {
        if (!failure) update(to, from);
    });
    update(router.currentRoute.value);
    return () => {
        removeRouteHook();
        menu.removeEventListener("click", toggleMenu);
        document.removeEventListener("click", navigate);
        document.removeEventListener("keydown", keyboard);
        document.removeEventListener("visibilitychange", visibility);
    };
}
