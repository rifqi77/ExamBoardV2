<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;

/**
 * Cross-exam mastery by topic / learning objective for a teacher's cohort
 * (admins see all teachers). Aggregates the per-submission topic_breakdown the
 * gradebook already stores, so it is cheap and always agrees with grades.
 *
 * Surfaces which objectives the class is weakest on (across every exam), to
 * drive reteaching — turning scores into instructional decisions.
 */
class CohortMastery
{
    private const MASTERY_THRESHOLD = 50.0;

    public static function forScope(User $user): array
    {
        $exams = Exam::when($user->role !== 'admin', fn ($q) => $q->where('created_by', $user->id))
            ->orderBy('name')->get(['id', 'exam_code', 'name']);
        $examName = $exams->pluck('name', 'id');
        $examIds = $exams->pluck('id')->all();

        $topicAgg = [];     // topic => earned, possible, below, students
        $examTopic = [];    // examId => topic => earned, possible
        $subCount = 0;

        if ($examIds) {
            $subs = ExamSubmission::whereIn('exam_id', $examIds)->get(['exam_id', 'topic_breakdown']);
            $subCount = $subs->count();
            foreach ($subs as $s) {
                $tb = is_array($s->topic_breakdown) ? $s->topic_breakdown : [];
                foreach ($tb as $row) {
                    $t = (string) ($row['topic'] ?? '');
                    $e = (float) ($row['earned'] ?? 0);
                    $p = (float) ($row['possible'] ?? 0);
                    if ($t === '' || $p <= 0) {
                        continue;
                    }
                    $topicAgg[$t]['earned'] = ($topicAgg[$t]['earned'] ?? 0) + $e;
                    $topicAgg[$t]['possible'] = ($topicAgg[$t]['possible'] ?? 0) + $p;
                    $topicAgg[$t]['students'] = ($topicAgg[$t]['students'] ?? 0) + 1;
                    if (($e / $p) * 100 < self::MASTERY_THRESHOLD) {
                        $topicAgg[$t]['below'] = ($topicAgg[$t]['below'] ?? 0) + 1;
                    }
                    $examTopic[$s->exam_id][$t]['earned'] = ($examTopic[$s->exam_id][$t]['earned'] ?? 0) + $e;
                    $examTopic[$s->exam_id][$t]['possible'] = ($examTopic[$s->exam_id][$t]['possible'] ?? 0) + $p;
                }
            }
        }

        // Topic rows, weakest first.
        $topics = [];
        foreach ($topicAgg as $topic => $v) {
            $pct = $v['possible'] > 0 ? round($v['earned'] / $v['possible'] * 100, 1) : null;
            $topics[] = [
                'topic' => $topic,
                'masteryPercent' => $pct,
                'studentsBelow' => $v['below'] ?? 0,
                'responses' => $v['students'] ?? 0,
            ];
        }
        usort($topics, fn ($a, $b) => ($a['masteryPercent'] ?? 101) <=> ($b['masteryPercent'] ?? 101));

        // Heatmap: topic (rows) × exam (cols), mastery % per cell (null if untested).
        $topicNames = array_map(fn ($r) => $r['topic'], $topics);
        $heatExams = [];
        foreach ($exams as $e) {
            if (isset($examTopic[$e->id])) {
                $heatExams[] = ['examId' => $e->exam_code, 'name' => $e->name, 'id' => $e->id];
            }
        }
        $heatmap = [];
        foreach ($topicNames as $t) {
            $cells = [];
            foreach ($heatExams as $he) {
                $cell = $examTopic[$he['id']][$t] ?? null;
                $cells[] = ($cell && $cell['possible'] > 0) ? round($cell['earned'] / $cell['possible'] * 100, 1) : null;
            }
            $heatmap[] = ['topic' => $t, 'cells' => $cells];
        }

        return [
            'scope' => $user->role === 'admin' ? 'all teachers' : 'your exams',
            'summary' => ['exams' => $exams->count(), 'submissions' => $subCount, 'topics' => count($topics)],
            'topics' => $topics,
            'heatmapExams' => array_map(fn ($e) => ['examId' => $e['examId'], 'name' => $e['name']], $heatExams),
            'heatmap' => $heatmap,
        ];
    }
}
