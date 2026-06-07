<?php

namespace App\Services;

use App\Models\ExamQuestion;
use App\Models\ExamSubmission;

/**
 * Lightweight, offline originality signal for essay answers: how similar is a
 * student's answer to OTHER students' answers to the same question (word-level
 * Jaccard). A high score suggests copying/collusion and is surfaced to the
 * teacher during grading. This is an advisory flag, not proof — the teacher
 * decides. (Deliberately not an "AI-written" detector; those are unreliable.)
 */
class OriginalitySignal
{
    private const FLAG_THRESHOLD = 0.70;

    private const MIN_TOKENS = 4; // too short to judge

    /** @return array<string,array{similarity:int,matchName:?string,flag:bool}> keyed by questionId */
    public static function forSubmission(ExamSubmission $sub): array
    {
        $essayIds = ExamQuestion::where('exam_id', $sub->exam_id)->where('type', 'essay')->pluck('id')->all();
        if (! $essayIds) {
            return [];
        }
        $mySnap = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];

        $others = ExamSubmission::where('exam_id', $sub->exam_id)
            ->where('id', '!=', $sub->id)
            ->get(['full_name', 'answers_snapshot']);

        $out = [];
        foreach ($essayIds as $qid) {
            $mine = self::tokenSet((string) ($mySnap[$qid] ?? ''));
            if (count($mine) < self::MIN_TOKENS) {
                continue;
            }
            $best = 0.0;
            $bestName = null;
            foreach ($others as $o) {
                $snap = is_array($o->answers_snapshot) ? $o->answers_snapshot : [];
                $sim = self::jaccard($mine, self::tokenSet((string) ($snap[$qid] ?? '')));
                if ($sim > $best) {
                    $best = $sim;
                    $bestName = $o->full_name;
                }
            }
            $flag = $best >= self::FLAG_THRESHOLD;
            $out[$qid] = [
                'similarity' => (int) round($best * 100),
                'matchName' => $flag ? $bestName : null,
                'flag' => $flag,
            ];
        }

        return $out;
    }

    /** Unique lowercase word tokens. */
    private static function tokenSet(string $s): array
    {
        $s = mb_strtolower(trim($s));
        if ($s === '') {
            return [];
        }
        $toks = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($toks));
    }

    /** Jaccard similarity of two token sets (0..1). */
    public static function jaccard(array $a, array $b): float
    {
        if (! $a || ! $b) {
            return 0.0;
        }
        $set = array_flip($a);
        $inter = 0;
        foreach ($b as $t) {
            if (isset($set[$t])) {
                $inter++;
            }
        }
        $union = count($a) + count($b) - $inter;

        return $union > 0 ? $inter / $union : 0.0;
    }
}
