<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;

/**
 * Builds the per-question post-exam review a student sees on their result page,
 * but ONLY when the teacher has enabled it for that exam. Correctness comes
 * from Scoring::score so it can never drift from the grade the student got.
 *
 * Returns null when review is not allowed; an array (possibly empty) otherwise.
 */
class AnswerReview
{
    public static function forSubmission(ExamSubmission $sub): ?array
    {
        $exam = Exam::find($sub->exam_id);
        if (! $exam || ! $exam->allow_answer_review) {
            return null;
        }

        $questions = ExamQuestion::where('exam_id', $sub->exam_id)->orderBy('position')->get();
        if ($questions->isEmpty()) {
            return [];
        }

        $snapshot = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];
        $manual = is_array($sub->manual_scores) ? $sub->manual_scores : [];

        $scored = Scoring::score(
            $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all(),
            $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
            $snapshot,
            $manual
        );
        $byId = collect($scored['itemResults'])->keyBy('questionId');

        return $questions->values()->map(function ($q, $i) use ($snapshot, $byId) {
            $it = $byId->get($q->id);

            return [
                'position' => $i + 1,
                'type' => $q->type,
                'topic' => $q->topic,
                'prompt' => $q->prompt,
                'options' => $q->options,
                'studentAnswer' => $snapshot[$q->id] ?? null,
                'correctAnswer' => $q->type === 'essay' ? null : $q->correct_answer,
                'explanation' => $q->explanation_text,
                'awarded' => $it['awarded'] ?? 0,
                'points' => $q->points,
                'isCorrect' => $it['isCorrect'] ?? false,
                'requiresGrading' => $it['requiresGrading'] ?? false,
            ];
        })->all();
    }
}
