@props(['seo', 'structured'])
<title>{{ $seo['title'] }}</title>
<meta name="description" content="{{ $seo['description'] }}">
<meta name="robots" content="{{ $seo['robots'] }}">
<link rel="canonical" href="{{ $seo['url'] }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="ShoeMoneyX">
<meta property="og:locale" content="en_US">
<meta property="og:title" content="{{ $seo['title'] }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $seo['url'] }}">
<meta property="og:image" content="{{ $seo['image'] }}">
<meta property="og:image:secure_url" content="{{ $seo['image'] }}">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo['title'] }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
<meta name="twitter:image" content="{{ $seo['image'] }}">
<meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">
@if (config('site.demo'))
    <link rel="describedby" type="text/plain" href="{{ app(\App\Support\SiteMetadata::class)->url('/llms.txt') }}">
    @if ($seo['markdown'])
        <link rel="alternate" type="text/markdown" href="{{ $seo['markdown'] }}" title="Page guide in Markdown">
    @endif
@endif
<script type="application/ld+json" id="site-structured-data">{!! json_encode($structured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
