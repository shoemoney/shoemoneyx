<?php

declare(strict_types=1);

namespace App\Support;

class SiteMetadata
{
    public const IMAGE = '/brand/shoemoneyx-home-og.jpg';

    public function pages(): array
    {
        return json_decode(file_get_contents(resource_path('content/site-pages.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function url(string $path = '/'): string
    {
        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    public function forPath(string $path): array
    {
        $path = '/'.trim($path, '/');
        $pages = $this->pages();
        $parent = array_find(array_keys($pages), fn ($key) => $path === $key || ($key !== '/' && str_starts_with($path, $key.'/')));
        $page = $pages[$parent ?? '/'];
        $demo = (bool) config('site.demo');
        $indexed = $demo && isset($pages[$path]);
        if (! $demo) {
            $page['title'] = 'ShoeMoneyX | Trading Intelligence';
            $page['description'] = 'ShoeMoneyX trading intelligence workspace for market charts, account activity and strategy research.';
        }

        return [
            ...$page,
            'url' => $this->url($parent ?? $path),
            'image' => $this->url(self::IMAGE),
            'image_alt' => 'ShoeMoneyX public demo homepage with the blue ShoeGPT robot, crypto market cards and simulated trading statistics.',
            'robots' => $indexed ? 'index, follow, max-image-preview:large' : 'noindex, follow',
            'markdown' => $parent !== null && $demo ? $this->url($parent === '/' ? '/index.md' : $parent.'.md') : null,
            'show_context' => $parent !== null && $demo,
        ];
    }

    public function structuredData(array $page): array
    {
        $root = $this->url();

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', '@id' => $root.'#organization', 'name' => 'ShoeMoneyX', 'url' => $root, 'logo' => $this->url('/brand/shoemoney-blue.png')],
                ['@type' => 'WebSite', '@id' => $root.'#website', 'name' => 'ShoeMoneyX', 'url' => $root, 'inLanguage' => 'en-US', 'publisher' => ['@id' => $root.'#organization']],
                ['@type' => 'WebPage', '@id' => $page['url'].'#webpage', 'url' => $page['url'], 'name' => $page['title'], 'description' => $page['description'], 'inLanguage' => 'en-US', 'isPartOf' => ['@id' => $root.'#website'], 'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => $page['image'], 'width' => 1200, 'height' => 630, 'caption' => $page['image_alt']]],
            ],
        ];
    }
}
