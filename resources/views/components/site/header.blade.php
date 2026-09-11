@props(['navigation', 'current' => null])
<header class="smx-header" data-site-header @if (! $current) hidden @endif>
    <div class="smx-masthead">
        <a href="/" class="smx-brand" data-site-link aria-label="ShoeMoneyX home">
            <span class="smx-insignia" aria-hidden="true"><span></span><img src="/brand/shoemoney-blue.png" alt="" width="56" height="56"></span>
            <span>SHOEMONEY<span class="smx-brand-x">X</span><small>TRADING INTELLIGENCE</small></span>
        </a>
        <div class="smx-context" aria-hidden="true"><span data-site-eyebrow>{{ $current['eyebrow'] ?? 'Trading intelligence' }}</span><strong data-site-page>{{ $current['label'] ?? 'Live overview' }}</strong></div>
        <div class="smx-header-state"><span class="smx-state-light" data-site-indicator="neutral"></span><span data-site-summary>Awaiting data</span></div>
        <button type="button" class="smx-menu" aria-expanded="false" aria-controls="smx-navigation" data-site-menu hidden><i class="fa-solid fa-bars" aria-hidden="true"></i><span>Menu</span></button>
    </div>
    <nav id="smx-navigation" class="smx-navigation" aria-label="Main navigation">
        @foreach ($navigation as $item)
            <a href="{{ $item['path'] }}" data-site-link data-site-nav data-site-eyebrow-value="{{ $item['eyebrow'] }}" @if (($current['path'] ?? null) === $item['path']) aria-current="page" @endif>
                <span class="smx-nav-icon" aria-hidden="true"><i class="fa-solid fa-{{ $item['icon'] }}"></i></span><span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
    <span class="smx-circuit" aria-hidden="true"></span>
</header>
