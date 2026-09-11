<?php

declare(strict_types=1);

namespace App\Support;

/** Deflated Sharpe ratio (Bailey & López de Prado 2014) and the normal-distribution helpers it needs. */
class Sharpe
{
    private const GAMMA = 0.5772156649;

    /** Standard normal CDF, P(Z <= x), via the Abramowitz-Stegun 7.1.26 erf approximation (~1e-7 accuracy). */
    public static function phi(float $x): float
    {
        return 0.5 * (1 - self::erf(-$x / M_SQRT2));
    }

    private static function erf(float $x): float
    {
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);
        $t = 1 / (1 + 0.3275911 * $x);
        $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /** Inverse standard normal CDF via Acklam's rational approximation (~1.15e-9 accuracy). 0 < $p < 1. */
    public static function z(float $p): float
    {
        if ($p <= 0.0) {
            return -INF;
        }
        if ($p >= 1.0) {
            return INF;
        }
        $pLow = 0.02425;
        if ($p < $pLow) {
            return self::tail(sqrt(-2 * log($p)));
        }
        if ($p > 1 - $pLow) {
            return -self::tail(sqrt(-2 * log(1 - $p)));
        }
        $q = $p - 0.5;
        $r = $q * $q;

        return ((((((-3.969683028665376e+01 * $r + 2.209460984245205e+02) * $r - 2.759285104469687e+02) * $r + 1.383577518672690e+02) * $r - 3.066479806614716e+01) * $r + 2.506628277459239e+00)) * $q
            / ((((( -5.447609879822406e+01 * $r + 1.615858368580409e+02) * $r - 1.556989798598866e+02) * $r + 6.680131188771972e+01) * $r - 1.328068155288572e+01) * $r + 1);
    }

    /** Shared rational approximation for both tails of z(); the caller negates it for the upper tail. */
    private static function tail(float $q): float
    {
        return ((((((-7.784894002430293e-03 * $q - 3.223964580411365e-01) * $q - 2.400758277161838e+00) * $q - 2.549732539343734e+00) * $q + 4.374664141464968e+00) * $q + 2.938163982698783e+00))
            / (((( 7.784695709041462e-03 * $q + 3.224671290700398e-01) * $q + 2.445134137142996e+00) * $q + 3.754408661907416e+00) * $q + 1);
    }

    /**
     * Deflated Sharpe ratio: the probability a non-annualized SR of $sr, drawn from $trials independent
     * trials of variance $trialVar, beats what noise alone would produce, adjusted for the return
     * distribution's skew/kurtosis via the SR estimator's standard error. Null under 3 observations.
     */
    public static function deflated(float $sr, int $trials, float $trialVar, int $obs, float $skew = 0.0, float $kurt = 3.0): ?float
    {
        if ($obs < 3) {
            return null;
        }
        $sr0 = 0.0;
        if ($trials >= 2) {
            $n = (float) $trials;
            $sr0 = sqrt(max($trialVar, 0.0)) * ((1 - self::GAMMA) * self::z(1 - 1 / $n) + self::GAMMA * self::z(1 - 1 / ($n * M_E)));
        }
        $denom = sqrt(max(1 - $skew * $sr + ($kurt - 1) / 4 * $sr ** 2, 1e-12));

        return self::phi(($sr - $sr0) * sqrt($obs - 1) / $denom);
    }
}
