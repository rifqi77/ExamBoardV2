<?php

namespace App\Services;

/**
 * Faithful PHP port of the Next.js app's src/lib/scoring.ts `scoreExam`.
 * Essay -> pending (manual grading); numeric + multi_select -> tolerance/
 * partial-credit; everything else -> normalized exact match.
 */
class Scoring
{
    private const EPS = 1e-6;

    private static function normalize($value)
    {
        if (is_array($value)) {
            $arr = array_map(fn ($v) => trim((string) $v), $value);
            sort($arr, SORT_STRING);
            return $arr;
        }
        if (is_string($value)) {
            return strtolower(trim($value));
        }
        return $value;
    }

    private static function numericCreditRatio($user, $correct): float
    {
        $u = is_numeric($user) ? (float) $user : NAN;
        $c = is_numeric($correct) ? (float) $correct : NAN;
        if (! is_finite($u) || ! is_finite($c)) {
            return 0.0;
        }
        $diff = abs($u - $c);
        if ($diff <= self::EPS) {
            return 1.0;
        }
        if ($c == 0.0) {
            if ($diff <= 0.001) return 0.8;
            if ($diff <= 0.01) return 0.5;
            if ($diff <= 0.1) return 0.2;
            return 0.0;
        }
        $rel = $diff / abs($c);
        if ($rel <= 0.01) return 0.8;
        if ($rel <= 0.05) return 0.5;
        if ($rel <= 0.1) return 0.2;
        return 0.0;
    }

    private static function multiSelectCreditRatio($user, $correct): float
    {
        $norm = function ($v) {
            if (! is_array($v)) {
                if (is_string($v) && trim($v) !== '') {
                    return [strtoupper(trim($v)) => true];
                }
                return [];
            }
            $out = [];
            foreach ($v as $x) {
                $s = strtoupper(trim((string) $x));
                if ($s !== '') {
                    $out[$s] = true;
                }
            }
            return $out;
        };
        $correctSet = $norm($correct);
        $userSet = $norm($user);
        if (count($correctSet) === 0) {
            return count($userSet) === 0 ? 1.0 : 0.0;
        }
        $cp = 0;
        $wp = 0;
        foreach (array_keys($userSet) as $u) {
            if (isset($correctSet[$u])) $cp++;
            else $wp++;
        }
        $ratio = ($cp - $wp) / count($correctSet);
        return max(0.0, min(1.0, $ratio));
    }

    private static function sameAnswer($user, $correct, ?string $type): bool
    {
        if ($user === null) {
            return false;
        }
        if ($type === 'numeric') {
            $u = is_numeric($user) ? (float) $user : NAN;
            $c = is_numeric($correct) ? (float) $correct : NAN;
            if (! is_finite($u) || ! is_finite($c)) {
                return false;
            }
            return abs($u - $c) <= self::EPS;
        }
        return json_encode(self::normalize($user)) === json_encode(self::normalize($correct));
    }

    /**
     * @param  array  $questions  list of ['id','topic','points','type']
     * @param  array  $keys       map questionId => correctAnswer
     * @param  array  $answers    map questionId => value
     * @param  array  $manual     map questionId => number (graded essays)
     */
    public static function score(array $questions, array $keys, array $answers, array $manual = []): array
    {
        $earned = 0.0;
        $possible = 0.0;
        $pending = 0;
        $topics = [];
        $items = [];

        foreach ($questions as $q) {
            $points = (float) $q['points'];
            $correct = $keys[$q['id']] ?? null;
            $user = $answers[$q['id']] ?? null;
            $type = $q['type'] ?? null;
            $awarded = 0.0;
            $isCorrect = false;
            $requiresGrading = false;

            if ($type === 'essay') {
                $m = $manual[$q['id']] ?? null;
                if (is_numeric($m)) {
                    $awarded = max(0.0, min($points, (float) $m));
                    $isCorrect = $points > 0 && $awarded >= $points;
                } else {
                    $pending++;
                    $requiresGrading = true;
                }
            } elseif ($type === 'numeric') {
                $awarded = round($points * self::numericCreditRatio($user, $correct), 2);
                $isCorrect = self::numericCreditRatio($user, $correct) >= 1;
            } elseif ($type === 'multi_select') {
                $ratio = self::multiSelectCreditRatio($user, $correct);
                $awarded = round($points * $ratio, 2);
                $isCorrect = $ratio >= 1;
            } else {
                $isCorrect = self::sameAnswer($user, $correct, $type);
                $awarded = $isCorrect ? $points : 0.0;
            }

            $earned += $awarded;
            $possible += $points;

            $t = $q['topic'];
            if (! isset($topics[$t])) {
                $topics[$t] = ['earned' => 0.0, 'possible' => 0.0, 'correct' => 0, 'total' => 0];
            }
            $topics[$t]['earned'] += $awarded;
            $topics[$t]['possible'] += $points;
            $topics[$t]['correct'] += $isCorrect ? 1 : 0;
            $topics[$t]['total'] += 1;

            $items[] = [
                'questionId' => $q['id'],
                'type' => $type,
                'awarded' => $awarded,
                'possible' => $points,
                'isCorrect' => $isCorrect,
                'requiresGrading' => $requiresGrading,
            ];
        }

        $percent = $possible == 0.0 ? 0.0 : round(($earned / $possible) * 100, 2);
        $breakdown = [];
        foreach ($topics as $topic => $v) {
            $breakdown[] = [
                'topic' => $topic,
                'earned' => round($v['earned'], 2),
                'possible' => round($v['possible'], 2),
                'percent' => $v['possible'] == 0.0 ? 0 : round(($v['earned'] / $v['possible']) * 100, 2),
                'correct' => $v['correct'],
                'total' => $v['total'],
            ];
        }

        return [
            'finalScore' => round($earned, 2),
            'possibleScore' => round($possible, 2),
            'percentScore' => $percent,
            'pendingEssayCount' => $pending,
            'topicBreakdown' => $breakdown,
            'itemResults' => $items,
        ];
    }
}
