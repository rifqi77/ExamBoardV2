<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Table of specifications for an exam: a Topic × cognitive-level matrix
 * (difficulty bands stand in for Bloom levels), plus distribution by type and
 * difficulty, and a curriculum-coverage check against the teacher's learning
 * objectives. Helps build balanced, valid assessments at authoring time.
 */
class ExamBlueprint
{
    /** difficulty band => rough Bloom level. */
    private const BLOOM = [
        'easy' => 'Remember / Understand',
        'medium' => 'Apply',
        'hard' => 'Analyze',
        'hots' => 'Evaluate',
        'olympiad' => 'Create',
    ];

    private const LABEL = [
        'easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard', 'hots' => 'HOTS', 'olympiad' => 'Olympiad',
    ];

    public static function forExam(Exam $exam): array
    {
        $diffs = array_keys(self::BLOOM);
        $questions = ExamQuestion::where('exam_id', $exam->id)->get(['type', 'topic', 'difficulty', 'points']);

        $matrix = [];      // topic => diff => ['count','points']
        $byDiff = array_fill_keys($diffs, ['count' => 0, 'points' => 0.0]);
        $byType = [];
        $totalPoints = 0.0;
        $totalCount = 0;

        foreach ($questions as $q) {
            $t = trim((string) $q->topic) !== '' ? $q->topic : '(untopiced)';
            $d = in_array($q->difficulty, $diffs, true) ? $q->difficulty : 'medium';
            $p = (float) $q->points;

            $matrix[$t] ??= array_fill_keys($diffs, ['count' => 0, 'points' => 0.0]);
            $matrix[$t][$d]['count']++;
            $matrix[$t][$d]['points'] += $p;

            $byDiff[$d]['count']++;
            $byDiff[$d]['points'] += $p;

            $byType[$q->type] ??= ['count' => 0, 'points' => 0.0];
            $byType[$q->type]['count']++;
            $byType[$q->type]['points'] += $p;

            $totalPoints += $p;
            $totalCount++;
        }

        ksort($matrix);
        $matrixRows = [];
        foreach ($matrix as $topic => $cells) {
            $rowCount = array_sum(array_column($cells, 'count'));
            $rowPoints = array_sum(array_column($cells, 'points'));
            $matrixRows[] = ['topic' => $topic, 'cells' => $cells, 'count' => $rowCount, 'points' => round($rowPoints, 2)];
        }

        $pct = fn ($pts) => $totalPoints > 0 ? round($pts / $totalPoints * 100, 1) : 0.0;

        $byDifficulty = [];
        foreach ($diffs as $d) {
            $byDifficulty[] = [
                'key' => $d, 'label' => self::LABEL[$d], 'bloom' => self::BLOOM[$d],
                'count' => $byDiff[$d]['count'], 'points' => round($byDiff[$d]['points'], 2), 'pct' => $pct($byDiff[$d]['points']),
            ];
        }

        $byTypeRows = [];
        foreach ($byType as $type => $v) {
            $byTypeRows[] = ['type' => $type, 'count' => $v['count'], 'points' => round($v['points'], 2), 'pct' => $pct($v['points'])];
        }
        usort($byTypeRows, fn ($a, $b) => $b['points'] <=> $a['points']);

        return [
            'exam' => ['examId' => $exam->exam_code, 'name' => $exam->name, 'subject' => $exam->subject],
            'difficulties' => array_map(fn ($d) => ['key' => $d, 'label' => self::LABEL[$d], 'bloom' => self::BLOOM[$d]], $diffs),
            'matrix' => $matrixRows,
            'byDifficulty' => $byDifficulty,
            'byType' => $byTypeRows,
            'totals' => ['count' => $totalCount, 'points' => round($totalPoints, 2), 'topics' => count($matrixRows)],
            'uncoveredTopics' => self::uncoveredTopics($exam, $questions),
        ];
    }

    /** Learning-objective topics (for this subject/teacher) with no question yet. */
    private static function uncoveredTopics(Exam $exam, $questions): array
    {
        $covered = $questions->pluck('topic')->filter()
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))->unique()->all();

        return DB::table('learning_objectives')
            ->when($exam->subject, fn ($q) => $q->where('subject', $exam->subject))
            ->where('uploaded_by', $exam->created_by)
            ->whereNotNull('topic')->where('topic', '!=', '')
            ->distinct()->orderBy('topic')->pluck('topic')
            ->filter(fn ($t) => ! in_array(mb_strtolower(trim((string) $t)), $covered, true))
            ->values()->all();
    }
}
