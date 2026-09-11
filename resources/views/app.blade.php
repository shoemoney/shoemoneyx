@php
    $navigation = config('site.navigation');
    $path = '/'.trim(request()->path(), '/');
    $current = collect($navigation)->first(fn ($item) => $path === $item['path'] || ($item['path'] !== '/' && str_starts_with($path, $item['path'].'/')));
@endphp
<x-site.layout :navigation="$navigation" :current="$current">
    <div id="app"></div>
    <noscript><p class="smx-no-script">Enable JavaScript to explore {{ config('site.demo') ? 'the interactive demo charts and simulated activity' : 'market data and desk controls' }}. Navigation and page guides remain available.</p></noscript>
    @if (! config('site.demo') && session()->get('desk_authed', false) && app(\App\Desk\Settings::class)->masterPassword() !== '')
        <script>localStorage.setItem('desk_token', @json(app(\App\Desk\Settings::class)->masterPassword()));</script>
    @endif
</x-site.layout>
