<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class SiteShellTest extends TestCase
{
    private function page(string $path): DOMXPath
    {
        $this->withoutVite();
        $response = $this->get($path)->assertOk();
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function test_laravel_renders_shared_chrome_and_working_navigation_before_javascript(): void
    {
        foreach (config('site.navigation') as $item) {
            $page = $this->page($item['path']);
            $this->assertSame(1, $page->query('//header[@data-site-header and not(@hidden)]')->length);
            $this->assertSame(1, $page->query('//footer[@data-site-footer and not(@hidden)]')->length);
            $this->assertSame(12, $page->query('//nav[@aria-label="Main navigation"]/a[@href]')->length);
            $this->assertSame(1, $page->query('//nav/a[@aria-current="page"]')->length);
            $this->assertSame($item['path'], $page->query('//nav/a[@aria-current="page"]')->item(0)->getAttribute('href'));
            $this->assertSame(0, $page->query('//*[@id="app"]//header | //*[@id="app"]//footer')->length);
            $this->assertSame(0, $page->query('//*[@data-site-header]//*[@data-site-menu and not(@hidden)]')->length);
        }
    }

    public function test_direct_detail_links_select_their_parent_navigation(): void
    {
        foreach (['/chart/BTC-USD' => '/chart', '/desk/42' => '/desk', '/backtests/19' => '/backtests'] as $path => $parent) {
            $page = $this->page($path);
            $this->assertSame($parent, $page->query('//nav/a[@aria-current="page"]')->item(0)->getAttribute('href'));
            $this->assertSame(1, $page->query('//*[@id="site-main" and @role="main"]')->length);
        }
    }

    public function test_experimental_galleries_keep_chrome_hidden_and_have_no_active_working_tab(): void
    {
        foreach (['/designs', '/landing/pulse', '/singularity/nanites', '/dashboardish'] as $path) {
            $page = $this->page($path);
            $this->assertSame(1, $page->query('//header[@data-site-header and @hidden]')->length);
            $this->assertSame(1, $page->query('//footer[@data-site-footer and @hidden]')->length);
            $this->assertSame(0, $page->query('//nav/a[@aria-current="page"]')->length);
            $this->assertSame(0, $page->query('//*[@id="site-main" and @role="main"]')->length);
        }
    }

    public function test_public_header_does_not_invent_desk_health_or_require_a_token(): void
    {
        config(['desk.api_token' => 'shell-test-token']);
        $home = $this->page('/');
        $this->assertSame(1, $home->query('//*[@data-site-health and @hidden]')->length);
        $this->assertSame(1, $home->query('//*[@data-site-public and not(@hidden)]')->length);
        $this->assertSame('Awaiting data', $home->query('//*[@data-site-summary]')->item(0)->textContent);
        $this->assertSame('Unknown', $home->query('//*[@data-site-ready]')->item(0)->textContent);
        $desk = $this->page('/dashboard');
        $this->assertSame('Unknown', $desk->query('//*[@data-site-mode]')->item(0)->textContent);
        $this->assertSame('Unknown', $desk->query('//*[@data-site-loop]')->item(0)->textContent);
        $this->assertSame(0, $desk->query('//input[@type="password"]')->length);
    }
}
