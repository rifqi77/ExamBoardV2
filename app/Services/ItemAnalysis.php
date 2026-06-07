<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;

/**
 * Classical test theory item analysis for one exam, computed from the
 * stored submissions (answers_snapshot + manual_scores), re-scored through
 * the same Scoring engine the gradebook uses so numbers always agree.
 *
 * Per item:
 *   - difficulty index (p-value)  = mean(awarded / possible)        0..1
 *   - discrimination               = CORRECTED item–total point-biserial
 *                                    (corr of item score vs total-minus-item)
 *   - distractor analysis for choice items (selection counts)
 *   - quality flags (too easy / too hard / weak / negative discrimination)
 * Exam level:
 *   - n, mean%, median%, std-dev, pass rate, score distribution
 *   - Cronbach's alpha (internal consistency) over the auto-scored items
 * Plus per-topic mastery (for learning-objective reporting).
 *
 * Essays are reported separately (manual, often partly ungraded) and are
 * excluded from discrimination / alpha, per standard practice.
 */
class ItemAnalysis
{
    private const MASTERY_THRESHOLD = 50.0; // % below which a student "hasn't mastered" a topic

    public static function forExam(Exam $exam): array
    {
        $questions = ExamQuestion::where('exam_id', $exam->id)->orderBy('position')->get();
        $qById = $questions->keyBy('id');
        $scoreInput = $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all();
        $keys = $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all();

        $subs = ExamSubmission::where('exam_id', $exam->id)->get(['session_id', 'answers_snapshot', 'manual_scores']);
        $n = $subs->count();

        // Per-student question draw: each submission is scored over the subset
        // its session drew (null = whole exam), so item p-values count only the
        // students who actually saw that item.
        $drawnBySession = ExamSession::where('exam_id', $exam->id)->whereNotNull('drawn_question_ids')
            ->pluck('drawn_question_ids', 'id');

        $awardedByItem = [];     // qid => [awarded per submission]
        $possibleByItem = [];    // qid => possible points
        $totalAuto = [];         // per submission: summed auto awarded
        $optionCounts = [];      // qid => [optionId => count]
        $essayAgg = [];          // qid => earned/possible/graded/pending
        $studentPercents = [];   // final % per submission
        $topicAgg = [];          // topic => [earned, possible]
        $topicStudent = [];      // topic => [below => n, total => n]

        foreach ($subs as $idx => $sub) {
            $snap = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];
            $manual = is_array($sub->manual_scores) ? $sub->manual_scores : [];
            $drawn = $sub->session_id ? ($drawnBySession[$sub->session_id] ?? null) : null;
            if ($drawn) {
                $sQ = ExamDraw::filter($questions, $drawn);
                $sInput = $sQ->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all();
                $sKeys = $sQ->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all();
            } else {
                $sInput = $scoreInput;
                $sKeys = $keys;
            }
            $res = Scoring::score($sInput, $sKeys, $snap, $manual);
            $studentPercents[] = (float) $res['percentScore'];

            $autoSum = 0.0;
            $studentTopic = []; // topic => [earned, possible] for this student
            foreach ($res['itemResults'] as $it) {
                $qid = $it['questionId'];
                $type = $it['type'];
                $aw = (float) $it['awarded'];
                $pos = (float) $it['possible'];
                $pending = (bool) $it['requiresGrading'];
                $topic = $qById[$qid]->topic ?? '(untopiced)';

                if ($type === 'essay') {
                    $essayAgg[$qid] ??= ['earned' => 0.0, 'possible' => 0.0, 'graded' => 0, 'pending' => 0];
                    if ($pending) {
                        $essayAgg[$qid]['pending']++;
                    } else {
                        $essayAgg[$qid]['earned'] += $aw;
                        $essayAgg[$qid]['possible'] += $pos;
                        $essayAgg[$qid]['graded']++;
                    }
                } else {
                    $awardedByItem[$qid][] = $aw;
                    $possibleByItem[$qid] = $pos;
                    $autoSum += $aw;
                }

                if (! ($type === 'essay' && $pending)) {
                    $studentTopic[$topic][0] = ($studentTopic[$topic][0] ?? 0) + $aw;
                    $studentTopic[$topic][1] = ($studentTopic[$topic][1] ?? 0) + $pos;
                }
            }
            $totalAuto[$idx] = $autoSum;

            foreach ($studentTopic as $topic => [$e, $p]) {
                $topicAgg[$topic][0] = ($topicAgg[$topic][0] ?? 0) + $e;
                $topicAgg[$topic][1] = ($topicAgg[$topic][1] ?? 0) + $p;
                $topicStudent[$topic]['total'] = ($topicStudent[$topic]['total'] ?? 0) + 1;
                if ($p > 0 && ($e / $p) * 100 < self::MASTERY_THRESHOLD) {
                    $topicStudent[$topic]['below'] = ($topicStudent[$topic]['below'] ?? 0) + 1;
                }
            }
        }

        // Distractor selection counts (choice items), straight from snapshots.
        foreach ($subs as $sub) {
            $snap = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];
            foreach ($questions as $q) {
                if ($q->type !== 'single_choice' && $q->type !== 'multi_select') {
                    continue;
                }
                $val = $snap[$q->id] ?? null;
                $picks = is_array($val) ? $val : ($val !== null && $val !== '' ? [$val] : []);
                foreach ($picks as $opt) {
                    $optionCounts[$q->id][(string) $opt] = ($optionCounts[$q->id][(string) $opt] ?? 0) + 1;
                }
            }
        }

        $totalAutoVals = array_values($totalAuto);

        // ---- per-item stats ----
        $items = [];
        $itemVariances = [];
        foreach ($questions as $q) {
            $row = [
                'position' => $q->position,
                'type' => $q->type,
                'topic' => $q->topic,
                'points' => (float) $q->points,
                'prompt' => mb_substr((string) $q->prompt, 0, 140),
            ];
            if ($q->type === 'essay') {
                $agg = $essayAgg[$q->id] ?? ['earned' => 0, 'possible' => 0, 'graded' => 0, 'pending' => 0];
                $row['responses'] = $agg['graded'] + $agg['pending'];
                $row['pending'] = $agg['pending'];
                $row['pValue'] = $agg['possible'] > 0 ? round($agg['earned'] / $agg['possible'], 3) : null;
                $row['discrimination'] = null;
                $row['flags'] = $agg['pending'] > 0 ? ['ungraded_essays'] : [];
                $row['verdict'] = self::verdict('essay', $row['pValue'], null, $row['flags']);
                $row['options'] = [];
                $items[] = $row;
                continue;
            }

            $x = $awardedByItem[$q->id] ?? [];
            $possible = $possibleByItem[$q->id] ?? (float) $q->points;
            $row['responses'] = count($x);
            $row['pending'] = 0;
            $mean = self::mean($x);
            $row['pValue'] = $possible > 0 && count($x) ? round($mean / $possible, 3) : null;

            // Corrected point-biserial: item vs (total - item).
            $rest = [];
            foreach ($x as $i => $xi) {
                $rest[] = ($totalAutoVals[$i] ?? 0) - $xi;
            }
            $disc = self::pearson($x, $rest);
            $row['discrimination'] = $disc === null ? null : round($disc, 3);
            $itemVariances[] = self::popVar($x);

            // Flags.
            $flags = [];
            if ($row['pValue'] !== null && $row['pValue'] > 0.90) {
                $flags[] = 'too_easy';
            }
            if ($row['pValue'] !== null && $row['pValue'] < 0.20) {
                $flags[] = 'too_hard';
            }
            if ($disc !== null && $disc < 0) {
                $flags[] = 'negative_discrimination';
            } elseif ($disc !== null && $disc < 0.15) {
                $flags[] = 'weak_discrimination';
            }
            $row['flags'] = $flags;
            $row['verdict'] = self::verdict($q->type, $row['pValue'], $disc, $flags);

            // Distractor analysis.
            $opts = [];
            if (is_array($q->options)) {
                $correct = $q->correct_answer;
                $correctSet = is_array($correct) ? array_map('strval', $correct) : [(string) $correct];
                foreach ($q->options as $o) {
                    $id = (string) ($o['id'] ?? '');
                    $opts[] = [
                        'id' => $id,
                        'text' => mb_substr((string) ($o['text'] ?? ''), 0, 60),
                        'chosen' => $optionCounts[$q->id][$id] ?? 0,
                        'isCorrect' => in_array($id, $correctSet, true),
                    ];
                }
            }
            $row['options'] = $opts;
            $items[] = $row;
        }

        // ---- exam-level ----
        $k = count(array_filter($itemVariances, fn ($v) => true)); // number of auto items contributing
        $varTotal = self::popVar($totalAutoVals);
        $alpha = null;
        if ($k >= 2 && $varTotal > 0) {
            $alpha = ($k / ($k - 1)) * (1 - array_sum($itemVariances) / $varTotal);
            $alpha = round($alpha, 3);
        }

        $dist = array_fill(0, 10, 0); // deciles 0-9,10-19,...90-100
        foreach ($studentPercents as $p) {
            $b = min(9, (int) floor($p / 10));
            $dist[$b]++;
        }
        $passing = (float) $exam->passing_grade;
        $passed = count(array_filter($studentPercents, fn ($p) => $p >= $passing));

        $topics = [];
        foreach ($topicAgg as $topic => [$e, $p]) {
            $topics[] = [
                'topic' => $topic,
                'masteryPercent' => $p > 0 ? round($e / $p * 100, 1) : null,
                'studentsBelow' => $topicStudent[$topic]['below'] ?? 0,
                'studentsTotal' => $topicStudent[$topic]['total'] ?? 0,
            ];
        }
        usort($topics, fn ($a, $b) => ($a['masteryPercent'] ?? 101) <=> ($b['masteryPercent'] ?? 101));

        return [
            'exam' => ['examId' => $exam->exam_code, 'name' => $exam->name, 'passingGrade' => $passing],
            'summary' => [
                'n' => $n,
                'meanPercent' => $n ? round(self::mean($studentPercents), 1) : null,
                'medianPercent' => $n ? round(self::median($studentPercents), 1) : null,
                'stdDev' => $n ? round(sqrt(self::popVar($studentPercents)), 1) : null,
                'passRate' => $n ? round($passed / $n * 100, 1) : null,
                'cronbachAlpha' => $alpha,
                'autoItemCount' => $k,
                'distribution' => $dist,
            ],
            'items' => $items,
            'topics' => $topics,
        ];
    }

    /**
     * Actionable recommendation for an item, synthesized from its quality flags.
     * level: keep | review | retire | info. Public + pure so it is unit-testable.
     */
    public static function verdict(string $type, ?float $pValue, ?float $discrimination, array $flags): array
    {
        if ($type === 'essay') {
            return in_array('ungraded_essays', $flags, true)
                ? ['level' => 'info', 'label' => 'Grade pending', 'reason' => 'Some responses are not yet graded.']
                : ['level' => 'keep', 'label' => 'Keep', 'reason' => 'Manually graded — no item statistics.'];
        }
        if (in_array('negative_discrimination', $flags, true)) {
            return ['level' => 'retire', 'label' => 'Revise or retire', 'reason' => 'Negative discrimination: stronger students did worse — check the answer key and wording.'];
        }
        $issues = [];
        if (in_array('too_hard', $flags, true)) {
            $issues[] = 'almost everyone got it wrong';
        }
        if (in_array('too_easy', $flags, true)) {
            $issues[] = 'almost everyone got it right';
        }
        if (in_array('weak_discrimination', $flags, true)) {
            $issues[] = 'weak discrimination';
        }
        if ($issues) {
            return ['level' => 'review', 'label' => 'Review', 'reason' => ucfirst(implode('; ', $issues)).'.'];
        }

        return ['level' => 'keep', 'label' => 'Keep', 'reason' => 'Good difficulty and discrimination.'];
    }

    private static function mean(array $a): float
    {
        $n = count($a);
        return $n ? array_sum($a) / $n : 0.0;
    }

    private static function median(array $a): float
    {
        if (! $a) {
            return 0.0;
        }
        sort($a);
        $n = count($a);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $a[$mid] : ($a[$mid - 1] + $a[$mid]) / 2;
    }

    private static function popVar(array $a): float
    {
        $n = count($a);
        if ($n === 0) {
            return 0.0;
        }
        $m = self::mean($a);
        $s = 0.0;
        foreach ($a as $x) {
            $s += ($x - $m) ** 2;
        }
        return $s / $n;
    }

    /** Pearson correlation; null if either series has zero variance. */
    private static function pearson(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n < 2 || $n !== count($y)) {
            return null;
        }
        $mx = self::mean($x);
        $my = self::mean($y);
        $sxy = $sxx = $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $mx;
            $dy = $y[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        if ($sxx <= 0 || $syy <= 0) {
            return null;
        }
        return $sxy / sqrt($sxx * $syy);
    }
}
