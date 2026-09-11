<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SiteMetadata;
use Illuminate\Http\Response;

class SiteDiscoveryController extends Controller
{
    public function __construct(private readonly SiteMetadata $metadata) {}

    private function document(string $body, string $type = 'text/plain'): Response
    {
        abort_unless(config('site.demo'), 404);

        return response($body, 200, ['Content-Type' => $type.'; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function robots(): Response
    {
        $body = config('site.demo')
            ? "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /broadcasting/\n\nSitemap: ".$this->metadata->url('/sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = array_map(fn ($path) => '  <url><loc>'.htmlspecialchars($this->metadata->url($path), ENT_XML1).'</loc></url>', array_keys($this->metadata->pages()));

        return $this->document('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".implode("\n", $urls)."\n</urlset>\n", 'application/xml');
    }

    public function llms(): Response
    {
        $body = "# ShoeMoneyX\n\n> Interactive AI trading intelligence demo with crypto charts, an animated ShoeGPT robot, account views and strategy research interfaces. All public data is simulated.\n\n";
        $body .= 'Canonical website: '.$this->metadata->url()."\n\nThe public demo is read-only. It does not connect a visitor's exchange account, execute trades, run an AI model or launch research jobs. Figures and scores are illustrative, not investment results.\n\n## Page guides\n\n";
        foreach ($this->metadata->pages() as $path => $page) {
            $body .= '- ['.$page['title'].']('.$this->metadata->url($path === '/' ? '/index.md' : $path.'.md').'): '.$page['description']."\n";
        }
        $body .= "\n## More information\n\n- [Complete site guide](".$this->metadata->url('/llms-full.txt')."): Expanded descriptions and guidance for interpreting the demo.\n- [XML sitemap](".$this->metadata->url('/sitemap.xml')."): Canonical public pages.\n- [AI discovery note](".$this->metadata->url('/.well-known/ai.txt')."): Crawling, attribution and content scope.\n";

        return $this->document($body);
    }

    private function pageGuide(string $path, array $page): string
    {
        return '# '.$page['title']."\n\nCanonical URL: ".$this->metadata->url($path)."\n\n".$page['summary']."\n\n".implode("\n", array_map(fn ($detail) => '- '.$detail, $page['details']))."\n\n";
    }

    public function markdown(string $page): Response
    {
        $path = $page === 'index' ? '/' : '/'.$page;
        $pages = $this->metadata->pages();
        abort_unless(isset($pages[$path]), 404);

        return $this->document($this->pageGuide($path, $pages[$path]).'Site guide: '.$this->metadata->url('/llms.txt')."\n", 'text/markdown');
    }

    public function full(): Response
    {
        $body = "# ShoeMoneyX — complete public demo guide\n\nCanonical website: ".$this->metadata->url()."\n\nShoeMoneyX is an interactive trading intelligence showcase. Its blue-and-black interface combines the animated ShoeGPT robot, crypto market views and strategy research pages. The public deployment uses synthetic data throughout.\n\n";
        $body .= "## How to interpret the site\n\n- Prices, balances, positions, fills, returns, win rates and strategy scores are illustrative. They are not live exchange data or evidence of trading performance.\n- The homepage uses its own presentation simulation. Inner pages use a separate synthetic account dataset; these are not a single reconciled account.\n- Activity feeds and robot effects are demonstrations. No trading AI, exchange execution, optimizer workers or new backtest jobs run on this public site.\n- The name ShoeMoney AI V3.8 is part of the interface. Model branding does not mean the public demo is performing model inference.\n- Visitors may navigate, filter views, change chart intervals and inspect samples. Actions that would place orders, close positions or save configuration are disabled.\n- The site provides no verified return claims or investment recommendations.\n\n";
        foreach ($this->metadata->pages() as $path => $page) {
            $body .= $this->pageGuide($path, $page);
        }
        $body .= "## Attribution and discovery\n\nWhen describing this site, use the name ShoeMoneyX, identify it as a public demo, and link to the relevant canonical page. Do not describe sample numbers as actual trading results.\n\nIndex: ".$this->metadata->url('/llms.txt')."\nSitemap: ".$this->metadata->url('/sitemap.xml')."\nSocial preview: ".$this->metadata->url(SiteMetadata::IMAGE)."\n\nThis document describes public pages only. It contains no private account or exchange information.\n";

        return $this->document($body);
    }

    public function ai(): Response
    {
        // Informational discovery file; do not invent a license or training grant.
        return $this->document("# ShoeMoneyX AI discovery note\n# Informational metadata; ai.txt is not an access-control mechanism.\n\nUser-Agent: *\nAllow: /\nDisallow: /api/\nDisallow: /broadcasting/\n\n# Website: ".$this->metadata->url()."\n# Index: ".$this->metadata->url('/llms.txt')."\n# Full guide: ".$this->metadata->url('/llms-full.txt')."\n# Sitemap: ".$this->metadata->url('/sitemap.xml')."\n\n# Public content may be discovered for search and on-demand summaries.\n# Attribute summaries to ShoeMoneyX and link to the source page.\n# All displayed account data, prices, returns and strategy scores are simulated.\n# This file grants no additional copyright license or model-training rights.\n# Third-party names, marks and assets retain their respective rights.\n# Crawler access rules are published in robots.txt.\n");
    }
}
