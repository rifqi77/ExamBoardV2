<?php

namespace App\Services;

use Closure;

/**
 * Seeded deterministic shuffle — byte-for-byte port of the Next app's
 * src/lib/shuffle.ts (Mulberry32-style LCG). Same seed → same order, so
 * the student sees a stable question/option order across refreshes but a
 * fresh order per attempt (seed = session id). Scoring is by id, not
 * position, so the reorder is purely cosmetic.
 *
 * JS uses 32-bit int semantics (`|0`, `>>> 0`); PHP ints are 64-bit, so
 * we mask to 32 bits explicitly to reproduce the exact sequence.
 */
class Shuffle
{
    /** @return Closure(): float  values in [0,1) */
    public static function seededRandom(string $seed): Closure
    {
        $hash = 0;
        $len = strlen($seed);
        for ($i = 0; $i < $len; $i++) {
            // hash = (hash << 5) - hash + charCodeAt(i); hash |= 0
            $hash = self::toInt32(($hash << 5) - $hash + ord($seed[$i]));
        }
        $state = $hash & 0xFFFFFFFF;   // (hash >>> 0)
        if ($state === 0) {
            $state = 1;
        }
        return function () use (&$state): float {
            // state = (state * 1664525 + 1013904223) >>> 0
            $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;
            return $state / 0xFFFFFFFF;
        };
    }

    /**
     * @template T
     * @param  array<int,T>  $array
     * @return array<int,T>
     */
    public static function shuffleWithSeed(array $array, string $seed): array
    {
        $rng = self::seededRandom($seed);
        $result = array_values($array);
        for ($i = count($result) - 1; $i > 0; $i--) {
            $j = (int) floor($rng() * ($i + 1));
            [$result[$i], $result[$j]] = [$result[$j], $result[$i]];
        }
        return $result;
    }

    /** Wrap to signed 32-bit, matching JS `n | 0`. */
    private static function toInt32(int $n): int
    {
        $n &= 0xFFFFFFFF;
        if ($n >= 0x80000000) {
            $n -= 0x100000000;
        }
        return $n;
    }
}
