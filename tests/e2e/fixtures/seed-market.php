<?php

declare(strict_types=1);

// Synthetic tape for the disposable browser desk; never accepts a production DB.
use App\Models\Candle;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli' || getenv('E2E_FIXTURE_MODE') !== '1') {
    throw new RuntimeException('Explicit CLI fixture mode is required.');
}

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = realpath((string) config('database.connections.sqlite.database'));
$allowed = realpath($root.'/output/playwright/runtime');
if (config('database.default') !== 'sqlite' || $database === false || $allowed === false
    || ! str_starts_with($database, $allowed.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Market fixture requires a disposable SQLite file under output/playwright/runtime.');
}

Product::updateOrCreate(['product_id' => 'BTC-USD'], [
    'base_currency' => 'BTC', 'quote_currency' => 'USD', 'status' => 'online',
    'price' => 60000, 'volume_24h' => 50000, 'volume_24h_usd' => 3000000000,
    'price_change_24h_pct' => 2, 'base_increment' => 0.00000001,
    'quote_increment' => 0.01, 'quote_min_size' => 1, 'base_min_size' => 0.00000001,
    'is_tracked' => true, 'trading_disabled' => false,
    'listed_at' => now()->subYears(5), 'synced_at' => now(),
    'meta' => ['fixture' => 'password-e2e-synthetic-market'],
]);

$end = now()->startOfHour();
$start = $end->copy()->subDays(16);
$updated = now()->toDateTimeString();
$inserted = 0;
foreach (['1H' => 3600, '1m' => 60] as $timeframe => $seconds) {
    $rows = [];
    $count = (int) ($start->diffInSeconds($end) / $seconds);
    for ($i = 0; $i <= $count; $i++) {
        $hour = $i * $seconds / 3600;
        $previousHour = max(0, ($i - 1) * $seconds / 3600);
        $price = static fn (float $h): float => 60000 * (1 + 0.06 * sin($h / 8) + 0.0002 * $h);
        $open = $price($previousHour);
        $close = $price($hour);
        $rows[] = [
            'product_id' => 'BTC-USD', 'timeframe' => $timeframe,
            'candle_start' => $start->copy()->addSeconds($i * $seconds)->toDateTimeString(),
            'open' => $open, 'close' => $close,
            'high' => max($open, $close) * 1.001, 'low' => min($open, $close) * 0.999,
            'volume' => $timeframe === '1H' ? 3000 : 50,
            'created_at' => $updated, 'updated_at' => $updated,
        ];
        if (count($rows) === 300 || $i === $count) {
            Candle::upsert($rows, ['product_id', 'timeframe', 'candle_start'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
            $inserted += count($rows);
            $rows = [];
        }
    }
}
echo json_encode(['fixture' => 'synthetic', 'rows' => $inserted, 'product' => 'BTC-USD'], JSON_THROW_ON_ERROR).PHP_EOL;
