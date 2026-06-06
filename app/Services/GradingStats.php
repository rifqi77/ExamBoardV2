<?php

namespace App\Services;

/**
 * Statistical validation that AI grades are indistinguishable from human
 * grades — the chi-square goodness-of-fit test from the grading spec:
 *
 *     χ² = Σ (Oᵢ − Eᵢ)² / Eᵢ      (O = AI score, E = human grade)
 *
 * Target p > 0.05 → the difference is NOT statistically significant, i.e.
 * the automated grades are statistically indistinguishable from human ones.
 * A drop below that is a grading-quality regression to investigate.
 *
 * The p-value needs the chi-square survival function, which needs the upper
 * incomplete gamma — implemented here (Numerical Recipes) since PHP has no
 * stats library.
 */
class GradingStats
{
    /**
     * @param  array<int,float>  $observed  AI scores (paired)
     * @param  array<int,float>  $expected  human grades (paired)
     */
    public static function chiSquare(array $observed, array $expected): array
    {
        $k = min(count($observed), count($expected));
        $chi = 0.0;
        $used = 0;
        for ($i = 0; $i < $k; $i++) {
            $e = (float) $expected[$i];
            $o = (float) $observed[$i];
            if ($e <= 0) {
                continue; // no expected mass — undefined cell, skip
            }
            $chi += (($o - $e) ** 2) / $e;
            $used++;
        }
        $df = max(1, $used - 1);
        $p = self::chiSquareSurvival($chi, $df);
        return [
            'chiSquare' => round($chi, 4),
            'df' => $df,
            'pValue' => round($p, 4),
            'aligned' => $p > 0.05,
            'pairs' => $used,
        ];
    }

    /** P(X > x) for a chi-square distribution with df degrees of freedom. */
    public static function chiSquareSurvival(float $x, int $df): float
    {
        if ($x <= 0) {
            return 1.0;
        }
        return self::gammq($df / 2.0, $x / 2.0);
    }

    /** Upper incomplete gamma Q(a,x) = 1 - P(a,x). */
    private static function gammq(float $a, float $x): float
    {
        if ($x < 0 || $a <= 0) {
            return 1.0;
        }
        return $x < $a + 1.0 ? 1.0 - self::gser($a, $x) : self::gcf($a, $x);
    }

    /** Lower incomplete gamma P(a,x) via series expansion. */
    private static function gser(float $a, float $x): float
    {
        if ($x <= 0) {
            return 0.0;
        }
        $gln = self::gammln($a);
        $ap = $a;
        $sum = 1.0 / $a;
        $del = $sum;
        for ($n = 1; $n <= 300; $n++) {
            $ap++;
            $del *= $x / $ap;
            $sum += $del;
            if (abs($del) < abs($sum) * 1e-13) {
                break;
            }
        }
        return $sum * exp(-$x + $a * log($x) - $gln);
    }

    /** Upper incomplete gamma Q(a,x) via continued fraction. */
    private static function gcf(float $a, float $x): float
    {
        $gln = self::gammln($a);
        $tiny = 1e-30;
        $b = $x + 1.0 - $a;
        $c = 1.0 / $tiny;
        $d = 1.0 / $b;
        $h = $d;
        for ($i = 1; $i <= 300; $i++) {
            $an = -$i * ($i - $a);
            $b += 2.0;
            $d = $an * $d + $b;
            if (abs($d) < $tiny) {
                $d = $tiny;
            }
            $c = $b + $an / $c;
            if (abs($c) < $tiny) {
                $c = $tiny;
            }
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;
            if (abs($del - 1.0) < 1e-13) {
                break;
            }
        }
        return exp(-$x + $a * log($x) - $gln) * $h;
    }

    private static function gammln(float $xx): float
    {
        $cof = [
            76.18009172947146, -86.50532032941677, 24.01409824083091,
            -1.231739572450155, 0.1208650973866179e-2, -0.5395239384953e-5,
        ];
        $x = $xx;
        $y = $xx;
        $tmp = $x + 5.5;
        $tmp -= ($x + 0.5) * log($tmp);
        $ser = 1.000000000190015;
        for ($j = 0; $j < 6; $j++) {
            $y++;
            $ser += $cof[$j] / $y;
        }
        return -$tmp + log(2.5066282746310005 * $ser / $x);
    }
}
