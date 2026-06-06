<?php

namespace App\Services;

use App\Models\ExamQuestion;
use App\Models\ExamSubmission;

/**
 * AI-assisted grading for essay / short-text answers. The AI only DRAFTS —
 * the teacher always decides (see the HITL rubric loop). Per essay it
 * produces:
 *   - lexical metrics (ROUGE/BLEU vs the model answer) — deterministic
 *   - an AI suggested score over N runs (mean ± SD) — the semantic engine
 *   - a review flag when the two engines disagree or the AI is unstable
 *
 * Suggestions are persisted to exam_submissions.grading_suggestions so the
 * chi-square check can later compare them to the teacher's final grades.
 */
class AssistedGrading
{
    /** @return array<string,array> keyed by questionId */
    public static function forSubmission(ExamSubmission $sub, int $runs = 3): array
    {
        $runs = max(1, min(10, $runs));
        $questions = ExamQuestion::where('exam_id', $sub->exam_id)
            ->where('type', 'essay')->orderBy('position')->get();
        $snapshot = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];

        $settings = AiProviders::getSettings();
        $aiReady = $settings['textProvider'] === 'pollinations'
            || (AiProviders::keyStatus()[$settings['textProvider']] ?? false);

        $out = [];
        foreach ($questions as $q) {
            $answer = $snapshot[$q->id] ?? null;
            $answer = is_array($answer) ? implode("\n", $answer) : (string) ($answer ?? '');
            if (trim($answer) === '') {
                continue; // nothing to grade
            }
            $max = (float) $q->points;
            $model = (string) ($q->explanation_text ?? '');
            $rubric = is_array($q->rubric) ? $q->rubric : [];

            $lexical = $model !== '' ? GradingMetrics::lexical($model, $answer) : null;
            $ai = $aiReady ? self::aiSuggest($q->prompt, $model, $rubric, $max, $answer, $settings, $runs) : null;

            $suggested = $ai
                ? round($ai['mean'], 2)
                : ($lexical ? round($lexical['score'] * $max, 2) : null);

            $out[$q->id] = [
                'position' => $q->position,
                'maxPoints' => $max,
                'lexical' => $lexical,
                'ai' => $ai,
                'suggested' => $suggested,
                'flag' => self::flag($lexical, $ai, $max),
            ];
        }

        $sub->forceFill(['grading_suggestions' => $out])->save();
        return $out;
    }

    /** Run the LLM grader N times; return mean/sd + last feedback/criteria. */
    private static function aiSuggest(string $prompt, string $model, array $rubric, float $max, string $answer, array $settings, int $runs): ?array
    {
        $text = self::buildPrompt($prompt, $model, $rubric, $max, $answer);
        $scores = [];
        $feedback = '';
        $criteria = [];
        for ($i = 0; $i < $runs; $i++) {
            try {
                $raw = AiProviders::generateText($text, true, $settings);
            } catch (\Throwable $e) {
                continue;
            }
            $parsed = self::parse($raw);
            if ($parsed === null) {
                continue;
            }
            $scores[] = max(0.0, min($max, (float) $parsed['score']));
            $feedback = $parsed['feedback'] ?: $feedback;
            $criteria = $parsed['criteria'] ?: $criteria;
        }
        if (! $scores) {
            return null;
        }
        $mean = array_sum($scores) / count($scores);
        $sd = 0.0;
        if (count($scores) > 1) {
            $var = 0.0;
            foreach ($scores as $s) {
                $var += ($s - $mean) ** 2;
            }
            $sd = sqrt($var / count($scores));
        }
        return [
            'mean' => round($mean, 2),
            'sd' => round($sd, 2),
            'runs' => count($scores),
            'feedback' => mb_substr($feedback, 0, 600),
            'criteria' => array_slice($criteria, 0, 12),
        ];
    }

    private static function buildPrompt(string $prompt, string $model, array $rubric, float $max, string $answer): string
    {
        $lines = [];
        $lines[] = "You are an exam grader. Score the student's answer out of {$max} points. Be fair and consistent.";
        $lines[] = 'QUESTION: '.$prompt;
        if ($model !== '') {
            $lines[] = 'MODEL ANSWER / MARK SCHEME: '.$model;
        }
        if ($rubric) {
            $lines[] = 'RUBRIC (award per criterion):';
            foreach ($rubric as $c) {
                $lines[] = '- '.($c['criterion'] ?? '').' ('.($c['points'] ?? '?').' pts)';
            }
        }
        $lines[] = 'STUDENT ANSWER: '.$answer;
        $lines[] = 'Credit correct understanding even if phrased differently from the model answer.';
        $lines[] = 'Return ONLY JSON: {"score": <number 0-'.$max.'>, "feedback": "one or two sentences", "criteria": [{"name":"...","score":<n>,"max":<m>}]}';
        return implode("\n", $lines);
    }

    private static function parse(string $raw): ?array
    {
        $t = trim(preg_replace('/```(?:json)?/i', '', $raw));
        $t = trim(str_replace('```', '', $t));
        $d = json_decode($t, true);
        if (! is_array($d)) {
            $s = strpos($t, '{');
            $e = strrpos($t, '}');
            if ($s !== false && $e !== false && $e > $s) {
                $d = json_decode(substr($t, $s, $e - $s + 1), true);
            }
        }
        if (! is_array($d) || ! isset($d['score']) || ! is_numeric($d['score'])) {
            return null;
        }
        return [
            'score' => (float) $d['score'],
            'feedback' => is_string($d['feedback'] ?? null) ? $d['feedback'] : '',
            'criteria' => is_array($d['criteria'] ?? null) ? $d['criteria'] : [],
        ];
    }

    /**
     * Dual-engine review flag. High lexical + low AI (or vice-versa) means
     * surface words and meaning disagree → review. High AI variance →
     * review. No model answer → can't cross-check lexically → review.
     */
    private static function flag(?array $lexical, ?array $ai, float $max): ?string
    {
        if ($ai && $max > 0 && ($ai['sd'] / $max) > 0.15) {
            return 'unstable'; // graders disagree across runs
        }
        if ($lexical && $ai && $max > 0) {
            $lex = $lexical['score'];
            $sem = $ai['mean'] / $max;
            $high = 0.6;
            $low = 0.35;
            if (($lex >= $high && $sem < $low) || ($lex < $low && $sem >= $high)) {
                return 'disagree'; // wording vs meaning mismatch
            }
        }
        if (! $lexical) {
            return 'no_model_answer'; // lexical check unavailable
        }
        return null; // confident draft
    }
}
