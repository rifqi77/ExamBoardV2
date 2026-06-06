<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Per-student question draw helper. Picks a stable random subset of an exam's
 * questions for one session (seeded by session id, so it survives refresh and
 * is independent per attempt), and filters a question collection to a drawn
 * set. A null/absent draw set means "use the whole exam" — so non-draw exams
 * are entirely unaffected.
 */
class ExamDraw
{
    /**
     * @param  array<int,string>  $allIds  every question id in the pool
     * @return array<int,string>|null  the drawn subset, or null = use all
     */
    public static function pick(array $allIds, int $count, string $seed): ?array
    {
        if ($count <= 0 || $count >= count($allIds)) {
            return null; // no restriction
        }
        return array_slice(Shuffle::shuffleWithSeed($allIds, $seed.'::draw'), 0, $count);
    }

    /**
     * Filter a questions collection to a drawn set (preserving order). Returns
     * the collection unchanged when there is no draw.
     *
     * @param  array<int,string>|null  $drawnIds
     */
    public static function filter(Collection $questions, ?array $drawnIds): Collection
    {
        if (! $drawnIds) {
            return $questions;
        }
        $set = array_flip($drawnIds);
        return $questions->filter(fn ($q) => isset($set[$q->id]))->values();
    }
}
