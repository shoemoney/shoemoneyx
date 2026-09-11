<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exchange\ExchangeRegistry;
use Illuminate\Console\Command;

/**
 * Records one exchange's public market data into the conformance test kit's fixture format, so
 * `ExchangeConformanceTestCase` (and any adapter-specific subclass of it) can replay a real venue
 * response in CI with zero network calls and zero credentials. Run once per adapter, commit the
 * output under tests/Exchange/Fixtures/<exchange-id>/.
 *
 * Writes exactly what the adapter's MarketData returns -- no normalization here. Every adapter's
 * MarketData already speaks the one canonical shape documented on App\Exchange\Contracts\MarketData
 * (base_currency/quote_currency, lowercase buy/sell sides, ...); this command only trims list
 * sizes so fixtures stay small and de-duplicates trades against overlapping pages.
 */
class ExchangeRecordCommand extends Command
{
    protected $signature = 'exchange:record {id : Registered exchange id, e.g. coinbase} {product : Sample product id, e.g. BTC-USD} {--dir= : Fixture output directory, default tests/Exchange/Fixtures/<id>}';

    protected $description = 'Record one exchange\'s public MarketData responses as conformance-kit fixtures';

    public function handle(ExchangeRegistry $registry): int
    {
        $id = (string) $this->argument('id');
        $productId = (string) $this->argument('product');
        $dir = (string) ($this->option('dir') ?: base_path("tests/Exchange/Fixtures/{$id}"));

        $exchange = $registry->make($id);
        $market = $exchange->marketData();

        $this->info("Recording {$exchange->name()} ({$id}) / {$productId} -> {$dir}");

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}");

            return self::FAILURE;
        }

        $products = $market->products();
        // Keep the fixture small: the sample product plus up to 19 others.
        $sample = array_values(array_filter($products, fn ($r) => ($r['product_id'] ?? null) === $productId));
        $rest = array_values(array_filter($products, fn ($r) => ($r['product_id'] ?? null) !== $productId));
        $products = array_slice(array_merge($sample, $rest), 0, 20);

        $product = $market->product($productId);

        $to = time();
        $candles = $market->candles($productId, '1m', $to - 60 * 120, $to);
        $candles = array_slice($candles, -50); // most recent 50, already ascending by start

        $ticker = $market->ticker($productId, 50);
        // Live-feed venues ignore the trade-limit hint (they return a larger tape regardless);
        // trim here so the fixture stays small no matter what the venue actually returned.
        $ticker['trades'] = array_slice($ticker['trades'] ?? [], 0, 50);

        // A single call's own window; de-dupe only guards against a venue re-sending the same
        // print at the window edge, not against re-ordering -- the adapter already guarantees
        // ascending order, so trimming here never re-sorts.
        $trades = $market->trades($productId, $to - 3600, $to, 200);
        $seen = [];
        $trades = array_values(array_filter($trades, function ($t) use (&$seen) {
            $id = (string) $t['trade_id'];
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;

            return true;
        }));
        $trades = array_slice($trades, -50); // most recent 50, ascending by time_ms

        $book = $market->book($productId, 10);

        $this->write($dir.'/products.json', $products);
        $this->write($dir.'/product.json', $product);
        $this->write($dir.'/candles.json', $candles);
        $this->write($dir.'/ticker.json', $ticker);
        $this->write($dir.'/trades.json', $trades);
        $this->write($dir.'/book.json', $book);

        $this->info('Recorded: products.json, product.json, candles.json, ticker.json, trades.json, book.json');

        return self::SUCCESS;
    }

    private function write(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
