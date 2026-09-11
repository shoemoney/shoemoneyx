<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SiteMetadata;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['site.demo' => true, 'app.url' => 'https://shoemoneyx.com', 'session.driver' => 'array']);
        $this->withoutVite();
        Http::preventStrayRequests();
        DB::listen(fn () => throw new \RuntimeException('Discovery must not access the database'));
    }

    private function html(string $path): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($this->get($path)->assertOk()->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function test_every_public_page_has_complete_server_rendered_metadata_and_readable_content(): void
    {
        $titles = [];
        foreach ((new SiteMetadata)->pages() as $path => $page) {
            $html = $this->html($path.'?fbclid=tracking-should-not-be-canonical');
            $this->assertSame(1, $html->query('//head/title')->length);
            $this->assertSame($page['title'], $html->evaluate('string(//head/title)'));
            $titles[] = $page['title'];
            $this->assertSame($page['description'], $html->evaluate('string(//meta[@name="description"]/@content)'));
            $this->assertSame('https://shoemoneyx.com'.$path, $html->evaluate('string(//link[@rel="canonical"]/@href)'));
            foreach (['title', 'description', 'type', 'url', 'site_name', 'locale', 'image', 'image:secure_url', 'image:type', 'image:width', 'image:height', 'image:alt'] as $field) {
                $this->assertSame(1, $html->query('//meta[@property="og:'.$field.'"]')->length);
                $this->assertNotSame('', $html->evaluate('string(//meta[@property="og:'.$field.'"]/@content)'));
            }
            $this->assertSame('1200', $html->evaluate('string(//meta[@property="og:image:width"]/@content)'));
            $this->assertSame('630', $html->evaluate('string(//meta[@property="og:image:height"]/@content)'));
            $this->assertSame('summary_large_image', $html->evaluate('string(//meta[@name="twitter:card"]/@content)'));
            $this->assertStringStartsWith('index,', $html->evaluate('string(//meta[@name="robots"]/@content)'));
            $this->assertSame($page['summary'], $html->evaluate('string(//*[@data-seo-summary])'));
            $this->assertSame(1, $html->query('//section[@data-site-context and not(@hidden)]')->length);
            $schema = json_decode($html->evaluate('string(//script[@type="application/ld+json"])'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['Organization', 'WebSite', 'WebPage'], array_column($schema['@graph'], '@type'));
            $this->assertSame($page['description'], $schema['@graph'][2]['description']);
        }
        $this->assertCount(9, array_unique($titles));
    }

    public function test_discovery_documents_link_to_real_guides_and_only_canonical_pages(): void
    {
        $index = $this->get('/llms.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
        $this->assertStringStartsWith("# ShoeMoneyX\n", $index);
        $this->assertStringContainsString('All public data is simulated', $index);
        preg_match_all('/\]\((https:\/\/shoemoneyx.com[^)]+)\)/', $index, $links);
        $this->assertCount(12, $links[1]);
        foreach ($links[1] as $url) {
            $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertDontSee('<div id="app">', false);
        }
        $map = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $xml = simplexml_load_string($map);
        $this->assertNotFalse($xml);
        $this->assertCount(9, $xml->url);
        $this->assertStringNotContainsString('lastmod', $map);
        $this->assertStringNotContainsString('/api/', $map);
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: https://shoemoneyx.com/sitemap.xml', false);
        $ai = $this->get('/ai.txt')->assertOk()->getContent();
        $this->assertSame($ai, $this->get('/.well-known/ai.txt')->assertOk()->getContent());
        $this->assertStringNotContainsString('example.com', $ai);
        $this->get('/index.md')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
        $this->get('/unknown.md')->assertNotFound();
    }

    public function test_private_deployments_and_experimental_pages_are_not_indexed(): void
    {
        foreach (['/landing/pulse', '/designs', '/chart/BTC-USD'] as $path) {
            $this->assertSame('noindex, follow', $this->html($path)->evaluate('string(//meta[@name="robots"]/@content)'));
        }
        $this->assertSame('https://shoemoneyx.com/chart', $this->html('/chart/BTC-USD')->evaluate('string(//link[@rel="canonical"]/@href)'));
        config(['site.demo' => false]);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /', false);
        foreach (['/llms.txt', '/llms-full.txt', '/sitemap.xml', '/ai.txt', '/.well-known/ai.txt', '/index.md'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $private = $this->html('/dashboard');
        $this->assertSame('noindex, follow', $private->evaluate('string(//meta[@name="robots"]/@content)'));
        $this->assertSame(0, $private->query('//*[@data-site-context]')->length);
    }

    public function test_social_preview_is_a_real_jpeg_at_the_declared_size(): void
    {
        $size = getimagesize(public_path(SiteMetadata::IMAGE));
        $this->assertSame(1200, $size[0]);
        $this->assertSame(630, $size[1]);
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertLessThan(2_000_000, filesize(public_path(SiteMetadata::IMAGE)));
    }
}
