<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Strategies\StrategySchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StarterTemplatesValidateTest extends TestCase
{
    private const FILES = [
        'smx-rsi-mean-reversion-v2.json',
        'smx-breakout-volume-v2.json',
        'smx-ema-cross-trend-v2.json',
        'smx-bollinger-reversion-v2.json',
        'smx-vwap-reversion-v2.json',
        'smx-macd-momentum-v2.json',
        'smx-volume-spike-v2.json',
        'smx-trend-trailing-stop-v2.json',
        'smx-dca-grid-v2.json',
        'smx-reentry-101-v2.json',
    ];

    #[Test]
    public function every_starter_template_validates_with_zero_errors(): void
    {
        foreach (self::FILES as $file) {
            $path = base_path("resources/strategies/examples/{$file}");
            $this->assertFileExists($path, $file);

            $definition = json_decode(file_get_contents($path), true);
            $result = StrategySchemaValidator::validate($definition);

            $this->assertTrue($result['valid'], "{$file}: ".json_encode($result['errors']));
            $this->assertSame([], $result['errors'], $file);
        }
    }
}
