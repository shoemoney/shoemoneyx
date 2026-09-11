@php
    $navigation = config('site.navigation');
    $path = '/'.trim(request()->path(), '/');
    $current = collect($navigation)->first(fn ($item) => $path === $item['path'] || ($item['path'] !== '/' && str_starts_with($path, $item['path'].'/')));
@endphp
<x-site.layout :navigation="$navigation" :current="$current">
    <div id="app"></div>
    <noscript><p class="smx-no-script">Enable JavaScript to explore {{ config('site.demo') ? 'the interactive demo charts and simulated activity' : 'market data and desk controls' }}. Navigation and page guides remain available.</p></noscript>
    @if (session()->get('desk_authed', false) && (string) config('desk.master_password') !== '')
        <script>localStorage.setItem('desk_token', @json((string) config('desk.master_password')));</script>
    @endif
</x-site.layout>
