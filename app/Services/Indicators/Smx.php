<?php

declare(strict_types=1);

namespace App\Services\Indicators;

/**
 * SMX — a bar-for-bar port of "POOR MANS MAC'd with wvma and some shoemoney sugar"
 * (SMX B lineage) from Pine v4 to PHP. Series are oldest -> newest and every
 * output array is aligned to the input bars (null where Pine would show na).
 *
 * Signals (same names as the Pine alerts):
 *   smallGreenDot  wtCross && wtCrossUp                         "Buy (Small green dot)"
 *   buy            wtCross && wtCrossUp && wt2 <= osLevel        "Buy (Big green circle)"
 *   buyDiv         WT regular bullish divergence (main or 2nd)   "Buy (Big green circle + Div)"
 *   goldBuy        the gold circle                               "GOLD Buy"
 *   smallRedDot    wtCross && wtCrossDown                        "Sell (Small red dot)"
 *   sell           wtCross && wtCrossDown && wt2 >= obLevel      "Sell (Big red circle)"
 *   sellDiv        WT regular bearish divergence (main or 2nd)   "Sell (Big red circle + Div)"
 *
 * Sommi flags/diamonds and MACD colouring are display-only in the script and off by
 * default; they are not ported.
 */
final class Smx
{
    public const DEFAULTS = [
        'wt_channel_len' => 9,
        'wt_average_len' => 12,
        'wt_ma_len' => 3,
        'ob_level' => 53,
        'ob_level2' => 60,
        'ob_level3' => 100,
        'os_level' => -53,
        'os_level2' => -60,
        'os_level3' => -75,
        'wt_div_ob' => 45,
        'wt_div_os' => -65,
        'wt_div_ob_add' => 15,
        'wt_div_os_add' => -40,
        'mfi_period' => 60,
        'mfi_multiplier' => 150,
        'mfi_pos_y' => 2.5,
        'rsi_len' => 14,
        'stoch_len' => 14,
        'stoch_rsi_len' => 14,
        'stoch_k' => 3,
        'stoch_d' => 3,
        'stoch_log' => true,
        'stc_length' => 10,
        'stc_fast' => 23,
        'stc_slow' => 50,
        'stc_factor' => 0.5,
    ];

    /**
     * @param  array<int, array{open:float,high:float,low:float,close:float}>  $bars
     * @return array<string, array<int, float|bool|null>> keyed series + 'signals'
     */
    public static function compute(array $bars, array $p = []): array
    {
        $p = $p + self::DEFAULTS;
        $n = count($bars);
        $open = array_map(fn ($b) => (float) $b['open'], $bars);
        $high = array_map(fn ($b) => (float) $b['high'], $bars);
        $low = array_map(fn ($b) => (float) $b['low'], $bars);
        $close = array_map(fn ($b) => (float) $b['close'], $bars);
        $hlc3 = [];
        for ($i = 0; $i < $n; $i++) {
            $hlc3[] = ($high[$i] + $low[$i] + $close[$i]) / 3;
        }

        // ---- WaveTrend -------------------------------------------------
        $esa = self::ema($hlc3, (int) $p['wt_channel_len']);
        $absDiff = [];
        for ($i = 0; $i < $n; $i++) {
            $absDiff[] = $esa[$i] === null ? null : abs($hlc3[$i] - $esa[$i]);
        }
        $de = self::ema($absDiff, (int) $p['wt_channel_len']);
        $ci = [];
        for ($i = 0; $i < $n; $i++) {
            $ci[] = ($esa[$i] === null || $de[$i] === null || $de[$i] == 0.0) ? null : ($hlc3[$i] - $esa[$i]) / (0.015 * $de[$i]);
        }
        $wt1 = self::ema($ci, (int) $p['wt_average_len']);
        $wt2 = self::sma($wt1, (int) $p['wt_ma_len']);
        $wtVwap = [];
        $wtCross = [];
        $wtCrossUp = [];
        $wtCrossDown = [];
        for ($i = 0; $i < $n; $i++) {
            $wtVwap[] = ($wt1[$i] === null || $wt2[$i] === null) ? null : $wt1[$i] - $wt2[$i];
            $cross = $i > 0 && $wt1[$i] !== null && $wt2[$i] !== null && $wt1[$i - 1] !== null && $wt2[$i - 1] !== null
                && (($wt1[$i - 1] - $wt2[$i - 1]) * ($wt1[$i] - $wt2[$i]) < 0 || ($wt1[$i - 1] === $wt2[$i - 1] && $wt1[$i] !== $wt2[$i]));
            $wtCross[] = $cross;
            $wtCrossUp[] = $wt1[$i] !== null && $wt2[$i] !== null && ($wt2[$i] - $wt1[$i]) <= 0;
            $wtCrossDown[] = $wt1[$i] !== null && $wt2[$i] !== null && ($wt2[$i] - $wt1[$i]) >= 0;
        }

        // ---- RSI + MFI area --------------------------------------------
        $mfiRaw = [];
        for ($i = 0; $i < $n; $i++) {
            $range = $high[$i] - $low[$i];
            $mfiRaw[] = $range == 0.0 ? 0.0 : (($close[$i] - $open[$i]) / $range) * (float) $p['mfi_multiplier'];
        }
        $mfiSma = self::sma($mfiRaw, (int) $p['mfi_period']);
        $rsiMfi = array_map(fn ($v) => $v === null ? null : $v - (float) $p['mfi_pos_y'], $mfiSma);

        // ---- RSI --------------------------------------------------------
        $rsi = self::rsiSeries($close, (int) $p['rsi_len']);

        // ---- Stochastic RSI ----------------------------------------------
        $src = $p['stoch_log'] ? array_map(fn ($c) => $c > 0 ? log($c) : null, $close) : $close;
        $rsiS = self::rsiSeries($src, (int) $p['stoch_rsi_len']);
        $stoch = self::stoch($rsiS, (int) $p['stoch_len']);
        $stochK = self::sma($stoch, (int) $p['stoch_k']);
        $stochD = self::sma($stochK, (int) $p['stoch_d']);

        // ---- Schaff Trend Cycle ------------------------------------------
        $stc = self::schaff($close, (int) $p['stc_length'], (int) $p['stc_fast'], (int) $p['stc_slow'], (float) $p['stc_factor']);

        // ---- Divergences on wt2 ------------------------------------------
        $divMain = self::divergences($wt2, $high, $low, (float) $p['wt_div_ob'], (float) $p['wt_div_os'], true);
        $divAdd = self::divergences($wt2, $high, $low, (float) $p['wt_div_ob_add'], (float) $p['wt_div_os_add'], true);

        // ---- Signals ------------------------------------------------------
        $ob = (float) $p['ob_level'];
        $os = (float) $p['os_level'];
        $os3 = (float) $p['os_level3'];
        $sig = [
            'small_green_dot' => [], 'buy' => [], 'buy_div' => [], 'gold_buy' => [],
            'small_red_dot' => [], 'sell' => [], 'sell_div' => [],
        ];
        // lastRsi = valuewhen(wtFractalBot, rsi[2], 0)[2]  -> RSI at the previous fractal bottom
        $lastRsi = null;
        $prevBotRsi = null;
        for ($i = 0; $i < $n; $i++) {
            $overs = $wt2[$i] !== null && $wt2[$i] <= $os;
            $overb = $wt2[$i] !== null && $wt2[$i] >= $ob;
            $sig['small_green_dot'][] = $wtCross[$i] && $wtCrossUp[$i];
            $sig['small_red_dot'][] = $wtCross[$i] && $wtCrossDown[$i];
            $sig['buy'][] = $wtCross[$i] && $wtCrossUp[$i] && $overs;
            $sig['sell'][] = $wtCross[$i] && $wtCrossDown[$i] && $overb;
            $sig['buy_div'][] = $divMain['bull'][$i] || $divAdd['bull'][$i];
            $sig['sell_div'][] = $divMain['bear'][$i] || $divAdd['bear'][$i];

            // gold: bullish div, previous WT low was below -75, wt2 now above it, at least 5 higher, RSI at that low < 30
            $lowPrev = $divMain['low_prev'][$i];
            $gold = $divMain['bull'][$i] && $lowPrev !== null && $lastRsi !== null
                && $lowPrev <= $os3 && $wt2[$i] !== null && $wt2[$i] > $os3
                && ($lowPrev - $wt2[$i]) <= -5 && $lastRsi < 30;
            $sig['gold_buy'][] = $gold;

            // maintain lastRsi (RSI at the previous fractal bottom, as seen two bars later)
            if ($divMain['fractal_bot'][$i]) {
                $lastRsi = $prevBotRsi;
                $prevBotRsi = $rsi[$i - 2] ?? null;
            }
        }

        return [
            'wt1' => $wt1, 'wt2' => $wt2, 'wt_vwap' => $wtVwap,
            'wt_cross' => $wtCross, 'wt_cross_up' => $wtCrossUp, 'wt_cross_down' => $wtCrossDown,
            'rsi_mfi' => $rsiMfi, 'rsi' => $rsi, 'stoch_k' => $stochK, 'stoch_d' => $stochD, 'stc' => $stc,
            'div_bull' => $divMain['bull'], 'div_bear' => $divMain['bear'],
            'div_bull_add' => $divAdd['bull'], 'div_bear_add' => $divAdd['bear'],
            'fractal_top' => $divMain['fractal_top'], 'fractal_bot' => $divMain['fractal_bot'],
            'signals' => $sig,
        ];
    }

    /** Snapshot of the last (closed) bar — what a strategy looks at. */
    public static function latest(array $c, int $offset = 0): array
    {
        $i = count($c['wt1']) - 1 - $offset;
        if ($i < 0) {
            return [];
        }
        $out = [];
        foreach (['wt1', 'wt2', 'wt_vwap', 'rsi_mfi', 'rsi', 'stoch_k', 'stoch_d', 'stc'] as $k) {
            $out[$k] = $c[$k][$i] !== null ? round((float) $c[$k][$i], 4) : null;
        }
        foreach ($c['signals'] as $k => $s) {
            $out[$k] = (bool) $s[$i];
        }
        $out['wt_cross'] = (bool) $c['wt_cross'][$i];
        $out['wt_cross_up'] = (bool) $c['wt_cross_up'][$i];

        return $out;
    }

    // =====================================================================
    // building blocks (all null-aware, aligned)
    // =====================================================================

    /** @param array<int, float|null> $v */
    public static function ema(array $v, int $n): array
    {
        $out = [];
        $k = 2 / ($n + 1);
        $ema = null;
        $seed = [];
        foreach ($v as $x) {
            if ($x === null) {
                $out[] = $ema;   // Pine carries the last value through na inputs

                continue;
            }
            if ($ema === null) {
                $seed[] = $x;
                if (count($seed) < $n) {
                    $out[] = null;

                    continue;
                }
                $ema = array_sum($seed) / $n;
            } else {
                $ema = $x * $k + $ema * (1 - $k);
            }
            $out[] = $ema;
        }

        return $out;
    }

    /** Volume-weighted moving average: sum(v*vol)/sum(vol) over n bars. */
    public static function vwma(array $v, array $vol, int $n): array
    {
        $out = [];
        $wv = [];
        $ww = [];
        foreach ($v as $i => $x) {
            if ($x === null) {
                $out[] = null;

                continue;
            }
            $w = (float) ($vol[$i] ?? 0);
            $wv[] = $x * $w;
            $ww[] = $w;
            if (count($wv) > $n) {
                array_shift($wv);
                array_shift($ww);
            }
            $den = array_sum($ww);
            $out[] = count($wv) < $n ? null : ($den > 0 ? array_sum($wv) / $den : $x);
        }

        return $out;
    }

    /** ma('ema'|'sma'|'vwma', …) */
    public static function ma(string $type, array $v, int $n, ?array $vol = null): array
    {
        return match (strtolower($type)) {
            'sma' => self::sma($v, $n),
            'vwma' => self::vwma($v, $vol ?? array_fill(0, count($v), 1.0), $n),
            default => self::ema($v, $n),
        };
    }

    public static function sma(array $v, int $n): array
    {
        $out = [];
        $win = [];
        foreach ($v as $x) {
            if ($x === null) {
                $out[] = null;

                continue;
            }
            $win[] = $x;
            if (count($win) > $n) {
                array_shift($win);
            }
            $out[] = count($win) < $n ? null : array_sum($win) / $n;
        }

        return $out;
    }

    /** Wilder RSI as a series (Pine rsi()). */
    public static function rsiSeries(array $v, int $n): array
    {
        $out = [];
        $ag = $al = null;
        $prev = null;
        $gains = [];
        $losses = [];
        foreach ($v as $x) {
            if ($x === null || $prev === null) {
                $out[] = null;
                $prev = $x ?? $prev;

                continue;
            }
            $d = $x - $prev;
            $prev = $x;
            if ($ag === null) {
                $gains[] = max($d, 0);
                $losses[] = max(-$d, 0);
                if (count($gains) < $n) {
                    $out[] = null;

                    continue;
                }
                $ag = array_sum($gains) / $n;
                $al = array_sum($losses) / $n;
            } else {
                $ag = ($ag * ($n - 1) + max($d, 0)) / $n;
                $al = ($al * ($n - 1) + max(-$d, 0)) / $n;
            }
            $out[] = $al == 0.0 ? 100.0 : 100 - 100 / (1 + $ag / $al);
        }

        return $out;
    }

    /** stoch(src, src, src, n) = (src - lowest(n)) / (highest(n) - lowest(n)) * 100 */
    public static function stoch(array $v, int $n): array
    {
        $out = [];
        $win = [];
        foreach ($v as $x) {
            if ($x === null) {
                $out[] = null;

                continue;
            }
            $win[] = $x;
            if (count($win) > $n) {
                array_shift($win);
            }
            if (count($win) < $n) {
                $out[] = null;

                continue;
            }
            $lo = min($win);
            $hi = max($win);
            $out[] = $hi - $lo == 0.0 ? 0.0 : ($x - $lo) / ($hi - $lo) * 100;
        }

        return $out;
    }

    public static function schaff(array $close, int $length, int $fast, int $slow, float $factor): array
    {
        $e1 = self::ema($close, $fast);
        $e2 = self::ema($close, $slow);
        $n = count($close);
        $macd = [];
        for ($i = 0; $i < $n; $i++) {
            $macd[] = ($e1[$i] === null || $e2[$i] === null) ? null : $e1[$i] - $e2[$i];
        }
        $out = [];
        $gammaPrev = 0.0;
        $delta = null;
        $etaPrev = 0.0;
        $stc = null;
        $macdWin = [];
        $deltaWin = [];
        for ($i = 0; $i < $n; $i++) {
            if ($macd[$i] === null) {
                $out[] = null;

                continue;
            }
            $macdWin[] = $macd[$i];
            if (count($macdWin) > $length) {
                array_shift($macdWin);
            }
            $alpha = min($macdWin);
            $beta = max($macdWin) - $alpha;
            $gamma = $beta > 0 ? ($macd[$i] - $alpha) / $beta * 100 : $gammaPrev;
            $gammaPrev = $gamma;
            $delta = $delta === null ? $gamma : $delta + $factor * ($gamma - $delta);
            $deltaWin[] = $delta;
            if (count($deltaWin) > $length) {
                array_shift($deltaWin);
            }
            $eps = min($deltaWin);
            $zeta = max($deltaWin) - $eps;
            $eta = $zeta > 0 ? ($delta - $eps) / $zeta * 100 : $etaPrev;
            $etaPrev = $eta;
            $stc = $stc === null ? $eta : $stc + $factor * ($eta - $stc);
            $out[] = $stc;
        }

        return $out;
    }

    /**
     * f_findDivs: fractal pivots on $src confirmed two bars later, compared with the
     * previous pivot's oscillator value and price.
     *
     * @return array{fractal_top:array,fractal_bot:array,bull:array,bear:array,bull_hidden:array,bear_hidden:array,low_prev:array}
     */
    public static function divergences(array $src, array $high, array $low, float $topLimit, float $botLimit, bool $useLimits): array
    {
        $n = count($src);
        $ft = array_fill(0, $n, false);
        $fb = array_fill(0, $n, false);
        $bull = array_fill(0, $n, false);
        $bear = array_fill(0, $n, false);
        $bullH = array_fill(0, $n, false);
        $bearH = array_fill(0, $n, false);
        $lowPrevOut = array_fill(0, $n, null);

        $prevTop = null;     // [src value, high price] of the previous confirmed top
        $prevBot = null;
        for ($i = 4; $i < $n; $i++) {
            $s0 = $src[$i];
            $s1 = $src[$i - 1];
            $s2 = $src[$i - 2];
            $s3 = $src[$i - 3];
            $s4 = $src[$i - 4];
            if ($s0 === null || $s1 === null || $s2 === null || $s3 === null || $s4 === null) {
                continue;
            }
            $isTop = $s4 < $s2 && $s3 < $s2 && $s2 > $s1 && $s2 > $s0;
            $isBot = $s4 > $s2 && $s3 > $s2 && $s2 < $s1 && $s2 < $s0;
            $top = $isTop && (! $useLimits || $s2 >= $topLimit);
            $bot = $isBot && (! $useLimits || $s2 <= $botLimit);
            $ft[$i] = $top;
            $fb[$i] = $bot;
            $lowPrevOut[$i] = $prevBot[0] ?? null;

            if ($top) {
                if ($prevTop !== null) {
                    $bear[$i] = $high[$i - 2] > $prevTop[1] && $s2 < $prevTop[0];
                    $bearH[$i] = $high[$i - 2] < $prevTop[1] && $s2 > $prevTop[0];
                }
                $prevTop = [$s2, $high[$i - 2]];
            }
            if ($bot) {
                if ($prevBot !== null) {
                    $bull[$i] = $low[$i - 2] < $prevBot[1] && $s2 > $prevBot[0];
                    $bullH[$i] = $low[$i - 2] > $prevBot[1] && $s2 < $prevBot[0];
                }
                $prevBot = [$s2, $low[$i - 2]];
            }
        }

        return ['fractal_top' => $ft, 'fractal_bot' => $fb, 'bull' => $bull, 'bear' => $bear, 'bull_hidden' => $bullH, 'bear_hidden' => $bearH, 'low_prev' => $lowPrevOut];
    }
}
