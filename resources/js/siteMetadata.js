import pages from "../content/site-pages.json" with { type: "json" };

export function pageMetadata(path, baseUrl, demo = false) {
    path = "/" + path.split(/[?#]/, 1)[0].replace(/^\/+|\/+$/g, "");
    const parent = Object.keys(pages).find(
        (key) => path === key || (key !== "/" && path.startsWith(key + "/")),
    );
    const page = pages[parent || "/"];
    const root = baseUrl.replace(/\/+$/, "");
    return {
        ...page,
        title: demo ? page.title : "ShoeMoneyX | Trading Intelligence",
        description: demo
            ? page.description
            : "ShoeMoneyX trading intelligence workspace for market charts, account activity and strategy research.",
        url: root + (parent || path),
        robots: demo && pages[path] ? "index, follow, max-image-preview:large" : "noindex, follow",
        markdown: demo && parent ? root + (parent === "/" ? "/index.md" : parent + ".md") : null,
        showContext: demo && !!parent,
    };
}

export function updateSiteMetadata(path) {
    const structured = document.getElementById("site-structured-data");
    if (!structured) return;
    const schema = JSON.parse(structured.textContent);
    const website = schema["@graph"].find((node) => node["@type"] === "WebSite");
    const page = pageMetadata(path, website.url, document.documentElement.dataset.demo === "true");
    document.title = page.title;
    for (const [selector, value] of [
        ['meta[name="description"]', page.description],
        ['meta[name="robots"]', page.robots],
        ['meta[property="og:title"]', page.title],
        ['meta[property="og:description"]', page.description],
        ['meta[property="og:url"]', page.url],
        ['meta[name="twitter:title"]', page.title],
        ['meta[name="twitter:description"]', page.description],
    ]) document.querySelector(selector)?.setAttribute("content", value);
    document.querySelector('link[rel="canonical"]')?.setAttribute("href", page.url);
    let markdown = document.querySelector('link[rel="alternate"][type="text/markdown"]');
    if (page.markdown) {
        if (!markdown) {
            markdown = document.createElement("link");
            markdown.rel = "alternate";
            markdown.type = "text/markdown";
            markdown.title = "Page guide in Markdown";
            document.head.append(markdown);
        }
        markdown.href = page.markdown;
    } else markdown?.remove();
    const webpage = schema["@graph"].find((node) => node["@type"] === "WebPage");
    Object.assign(webpage, { "@id": page.url + "#webpage", url: page.url, name: page.title, description: page.description });
    structured.textContent = JSON.stringify(schema);
    const context = document.querySelector("[data-site-context]");
    if (context) {
        context.hidden = !page.showContext;
        context.querySelector("[data-seo-heading]").textContent = page.heading;
        context.querySelector("[data-seo-summary]").textContent = page.summary;
        context.querySelector("[data-seo-guide]").href = page.markdown || rootGuide(website.url);
    }
}

function rootGuide(url) {
    return url.replace(/\/+$/, "") + "/llms.txt";
}
