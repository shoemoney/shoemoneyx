<?php

return [
    // Public showcase: synthetic API responses and no mutations.
    'demo' => (bool) env('SITE_DEMO', false),
    'navigation' => [
        ['path' => '/', 'label' => 'Live overview', 'icon' => 'bolt', 'eyebrow' => 'Trading intelligence'],
        ['path' => '/dashboard', 'label' => 'Dashboard', 'icon' => 'chart-line', 'eyebrow' => 'Command overview'],
        ['path' => '/chart', 'label' => 'Chart', 'icon' => 'chart-candlestick', 'eyebrow' => 'Market intelligence'],
        ['path' => '/desk', 'label' => 'Desk', 'icon' => 'microchip-ai', 'eyebrow' => 'Execution pipeline'],
        ['path' => '/positions', 'label' => 'Positions', 'icon' => 'layer-group', 'eyebrow' => 'Exposure & fills'],
        ['path' => '/backtests', 'label' => 'Backtests', 'icon' => 'clock-rotate-left', 'eyebrow' => 'Strategy research'],
        ['path' => '/optimizer', 'label' => 'Optimizer', 'icon' => 'chart-scatter', 'eyebrow' => 'Continuous research'],
        ['path' => '/arena', 'label' => 'Arena', 'icon' => 'swords', 'eyebrow' => 'Strategy arena'],
        ['path' => '/builder', 'label' => 'Builder', 'icon' => 'plus', 'eyebrow' => 'Strategy builder'],
        ['path' => '/archive', 'label' => 'Archive', 'icon' => 'book-open', 'eyebrow' => 'Community archive'],
        ['path' => '/exchanges', 'label' => 'Exchanges', 'icon' => 'plug', 'eyebrow' => 'Venue directory'],
        ['path' => '/settings', 'label' => 'Settings', 'icon' => 'sliders', 'eyebrow' => 'Desk configuration'],
    ],
];
