@props(['current' => null])
<footer class="smx-footer" data-site-footer @if (! $current) hidden @endif>
    <div class="smx-footer-main">
        <div class="smx-footer-brand"><strong>SHOEMONEY<span class="smx-brand-x">X</span></strong><span>Trading intelligence · {{ now()->year }}</span></div>
        <dl class="smx-health" data-site-health @if (($current['path'] ?? '/') === '/') hidden @endif>
            <div><dt>Mode</dt><dd data-site-mode>Unknown</dd></div>
            <div><dt>Strategy</dt><dd data-site-strategy>Unknown</dd></div>
            <div><dt>Ready</dt><dd data-site-ready>Unknown</dd></div>
            <div><dt>Loop</dt><dd data-site-loop>Unknown</dd></div>
        </dl>
        <div class="smx-public-state" data-site-public @if (($current['path'] ?? '/') !== '/') hidden @endif><span data-site-public-source>Awaiting market data</span><p class="smx-public-note" data-site-public-note hidden></p></div>
        <div class="smx-footer-links"><a href="/dashboard" data-site-link>Dashboard <i class="fa-solid fa-arrow-up-right" aria-hidden="true"></i></a><a href="/settings" data-site-link>Settings</a></div>
    </div>
    <div class="smx-health-detail" data-site-health-detail hidden>
        <p class="smx-health-error" data-site-error hidden role="status"></p>
        <p class="smx-health-error" data-site-halted hidden role="status"></p>
        <div class="smx-checks" data-site-checks aria-label="Desk health checks"></div>
    </div>
</footer>
