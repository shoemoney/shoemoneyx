@props(['navigation', 'current' => null])
@php
    $metadata = app(\App\Support\SiteMetadata::class);
    $seo = $metadata->forPath(request()->path());
@endphp
<!DOCTYPE html>
<html lang="en" prefix="og: https://ogp.me/ns#" class="dark" data-demo="{{ config('site.demo') ? 'true' : 'false' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#000000">
    <x-site.metadata :seo="$seo" :structured="$metadata->structuredData($seo)" />
    <link rel="icon" type="image/png" href="/brand/shoemoney-blue.png">
    @if ($current)
        <link rel="preload" as="image" type="image/webp" fetchpriority="high" href="{{ $current['path'] === '/' ? '/brand/shoegpt-robot-typing-wide.webp' : '/brand/shoegpt-robot-armor.webp' }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased {{ $current ? 'smx-app' : '' }}" data-shell-mode="{{ ! $current ? 'gallery' : ($current['path'] === '/' ? 'home' : 'desk') }}">
    <a class="smx-skip" href="#site-main">Skip to content</a>
    @if (config('site.demo'))
        <div class="smx-demo-banner"><strong>INTERACTIVE DEMO</strong><span>Simulated data. Explore every page. No real trades or saved changes.</span></div>
    @endif
    <x-site.header :navigation="$navigation" :current="$current" />
    <div id="site-main" class="smx-main" tabindex="-1" @if ($current && $current['path'] !== '/') role="main" @endif>{{ $slot }}</div>
    @if (config('site.demo'))
        <section class="smx-page-context" data-site-context @if (! $seo['show_context']) hidden @endif aria-labelledby="site-context-heading">
            <span class="smx-context-label">EXPLORE SHOEMONEYX</span>
            <h2 id="site-context-heading" data-seo-heading>{{ $seo['heading'] }}</h2>
            <p data-seo-summary>{{ $seo['summary'] }}</p>
            <p class="smx-context-note">Interactive demo · Simulated data · No real trades</p>
            <a data-seo-guide href="{{ $seo['markdown'] ?? $metadata->url('/llms.txt') }}">Read the page guide</a>
        </section>
    @endif
    <x-site.footer :current="$current" />
</body>
</html>
