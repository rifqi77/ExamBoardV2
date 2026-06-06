<?php

namespace App\Services;

/**
 * Lexical grading engine (deterministic, pure PHP) — the free, repeatable
 * half of the dual-engine design. Compares a student answer against the
 * reference / model answer with ROUGE-1, ROUGE-L and BLEU. The semantic
 * half is provided by the LLM grade suggestion in AssistedGrading; this
 * class never calls out and always returns the same numbers for the same
 * input, so it's a defensible baseline + a review-flag signal.
 *
 * All scores are floats in [0,1].
 */
class GradingMetrics
{
    /** Lowercase + split on non-alphanumerics → token list. */
    public static function tokens(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $parts ?: [];
    }

    /** ROUGE-1 F1 — clipped unigram overlap (content completeness). */
    public static function rouge1(string $reference, string $candidate): float
    {
        $ref = self::tokens($reference);
        $cand = self::tokens($candidate);
        if (! $ref || ! $cand) {
            return 0.0;
        }
        $refCounts = array_count_values($ref);
        $candCounts = array_count_values($cand);
        $overlap = 0;
        foreach ($refCounts as $tok => $c) {
            if (isset($candCounts[$tok])) {
                $overlap += min($c, $candCounts[$tok]);
            }
        }
        $recall = $overlap / count($ref);
        $precision = $overlap / count($cand);
        return self::f1($precision, $recall);
    }

    /** ROUGE-L F1 — longest common subsequence (structural alignment). */
    public static function rougeL(string $reference, string $candidate): float
    {
        $ref = self::tokens($reference);
        $cand = self::tokens($candidate);
        if (! $ref || ! $cand) {
            return 0.0;
        }
        $lcs = self::lcsLength($ref, $cand);
        $recall = $lcs / count($ref);
        $precision = $lcs / count($cand);
        return self::f1($precision, $recall);
    }

    /**
     * BLEU (up to 4-grams) with brevity penalty + add-1 smoothing for the
     * higher-order n-grams so short answers don't collapse to zero.
     */
    public static function bleu(string $reference, string $candidate, int $maxN = 4): float
    {
        $ref = self::tokens($reference);
        $cand = self::tokens($candidate);
        if (! $ref || ! $cand) {
            return 0.0;
        }
        $logSum = 0.0;
        $n = min($maxN, count($cand));
        if ($n === 0) {
            return 0.0;
        }
        for ($k = 1; $k <= $n; $k++) {
            $candGrams = self::ngramCounts($cand, $k);
            $refGrams = self::ngramCounts($ref, $k);
            $match = 0;
            $total = 0;
            foreach ($candGrams as $g => $c) {
                $total += $c;
                $match += min($c, $refGrams[$g] ?? 0);
            }
            // Add-1 smoothing for k > 1 (Chen & Cherry method 1).
            if ($k > 1) {
                $match += 1;
                $total += 1;
            }
            $p = $total > 0 ? $match / $total : 0.0;
            if ($p <= 0) {
                return 0.0;
            }
            $logSum += log($p);
        }
        $geoMean = exp($logSum / $n);
        $bp = count($cand) >= count($ref) ? 1.0 : exp(1 - count($ref) / count($cand));
        return $bp * $geoMean;
    }

    /**
     * Combined lexical baseline (a weighted blend) + the parts. ROUGE
     * carries content/structure; BLEU is the strictest and noisiest, so it
     * is weighted lightest.
     */
    public static function lexical(string $reference, string $candidate): array
    {
        $r1 = self::rouge1($reference, $candidate);
        $rL = self::rougeL($reference, $candidate);
        $bleu = self::bleu($reference, $candidate);
        return [
            'rouge1' => round($r1, 4),
            'rougeL' => round($rL, 4),
            'bleu' => round($bleu, 4),
            'score' => round(0.4 * $r1 + 0.4 * $rL + 0.2 * $bleu, 4),
        ];
    }

    private static function f1(float $p, float $r): float
    {
        return ($p + $r) > 0 ? (2 * $p * $r) / ($p + $r) : 0.0;
    }

    private static function lcsLength(array $a, array $b): int
    {
        $n = count($a);
        $m = count($b);
        $prev = array_fill(0, $m + 1, 0);
        for ($i = 1; $i <= $n; $i++) {
            $cur = array_fill(0, $m + 1, 0);
            for ($j = 1; $j <= $m; $j++) {
                $cur[$j] = $a[$i - 1] === $b[$j - 1]
                    ? $prev[$j - 1] + 1
                    : max($prev[$j], $cur[$j - 1]);
            }
            $prev = $cur;
        }
        return $prev[$m];
    }

    private static function ngramCounts(array $tokens, int $k): array
    {
        $out = [];
        $limit = count($tokens) - $k;
        for ($i = 0; $i <= $limit; $i++) {
            $g = implode(' ', array_slice($tokens, $i, $k));
            $out[$g] = ($out[$g] ?? 0) + 1;
        }
        return $out;
    }
}
