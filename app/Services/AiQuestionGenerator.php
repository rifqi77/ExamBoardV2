<?php

namespace App\Services;

use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The heavy lifting behind AI question generation: prompt → LLM → parse →
 * shape → persist (+ optional figure generation). Extracted from the
 * controller so it can run inside a queued job without blocking a web worker.
 */
class AiQuestionGenerator
{
    public const TYPES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

    public const DIFFS = ['easy', 'medium', 'hard', 'hots', 'olympiad'];

    /**
     * @param  array  $p  validated params from AiGenerateController::run
     * @param  callable|null  $onProgress  fn(int $done, int $total)
     * @return array{created:int,target:string,imageCount:int,questions:array}
     */
    public function generate(array $p, User $user, ?callable $onProgress = null): array
    {
        $settings = AiProviders::getSettings();
        $target = ($p['target'] ?? 'exam') === 'bank' ? 'bank' : 'exam';
        $exam = ($target === 'exam' && ! empty($p['examInternalId'])) ? Exam::find($p['examInternalId']) : null;
        if ($target === 'exam' && ! $exam) {
            throw new \RuntimeException('Exam not found.');
        }

        $wantImages = (bool) ($p['wantImages'] ?? false) && $settings['imageProvider'] !== 'off';
        $topic = (string) ($p['topic'] ?? 'General');
        $subject = (string) ($p['subject'] ?? '');
        $language = (string) ($p['language'] ?? 'English');
        $difficulty = (string) ($p['difficulty'] ?? 'medium');

        $prompt = $this->buildPrompt([
            'count' => (int) ($p['count'] ?? 5), 'type' => $p['type'] ?? 'any', 'topic' => $topic,
            'subject' => $subject, 'language' => $language, 'difficulty' => $difficulty,
            'extra' => (string) ($p['extra'] ?? ''), 'lo' => (string) ($p['lo'] ?? ''),
            'olympiad' => (string) ($p['olympiad'] ?? 'off'), 'wantImages' => $wantImages,
        ]);

        $text = AiProviders::generateText($prompt, true, $settings); // may throw
        $parsed = $this->parseQuestions($text);
        if (count($parsed) === 0) {
            throw new \RuntimeException('The AI returned no parseable questions. Try again.');
        }

        $total = count($parsed);
        $onProgress && $onProgress(0, $total);

        $created = [];
        $imageCount = 0;
        $pos = $exam ? (int) (ExamQuestion::where('exam_id', $exam->id)->max('position') ?? 0) : 0;
        $done = 0;

        foreach ($parsed as $q) {
            $done++;
            try {
                $shaped = $this->shape(is_array($q) ? $q : [], $topic, $subject, $language, $difficulty);
            } catch (\Throwable $e) {
                $onProgress && $onProgress($done, $total);

                continue;
            }
            $imageUrl = null;
            if ($wantImages && $shaped['imagePrompt']) {
                $imageUrl = AiProviders::imageUrl($shaped['imagePrompt']);
                $imageCount++;
            }

            if ($target === 'bank') {
                BankQuestion::create([
                    'id' => (string) Str::uuid(),
                    'type' => $shaped['type'], 'language' => $language, 'subject' => $subject ?: null,
                    'topic' => $shaped['topic'], 'subtopic' => $shaped['subtopic'], 'difficulty' => $shaped['difficulty'],
                    'prompt' => $shaped['prompt'], 'options' => $shaped['options'], 'points' => $shaped['points'],
                    'correct_answer' => $shaped['correct'], 'explanation_text' => $shaped['expl'],
                    'media_url' => $imageUrl, 'media_type' => $imageUrl ? 'image' : null,
                    'created_by' => $user->id, 'created_by_name' => $user->full_name,
                    'uploaded_by' => $user->id, 'uploaded_by_name' => $user->full_name,
                    'source_file_name' => 'ai-generate', 'created_at' => now(),
                ]);
            } else {
                $pos++;
                $qid = (string) Str::uuid();
                ExamQuestion::create([
                    'id' => $qid, 'exam_id' => $exam->id, 'position' => $pos,
                    'type' => $shaped['type'], 'topic' => $shaped['topic'], 'prompt' => $shaped['prompt'],
                    'options' => $shaped['options'], 'points' => $shaped['points'], 'difficulty' => $shaped['difficulty'],
                    'language' => $language, 'correct_answer' => $shaped['correct'], 'explanation_text' => $shaped['expl'],
                ]);
                if ($imageUrl) {
                    DB::table('exam_media')->insert([
                        'id' => (string) Str::uuid(), 'question_id' => $qid, 'type' => 'image',
                        'url' => $imageUrl, 'sort_order' => 0, 'created_at' => now(),
                    ]);
                }
            }
            $created[] = ['type' => $shaped['type'], 'prompt' => $shaped['prompt'], 'hasImage' => $imageUrl !== null];
            $onProgress && $onProgress($done, $total);
        }

        return ['created' => count($created), 'target' => $target, 'imageCount' => $imageCount, 'questions' => $created];
    }

    private function buildPrompt(array $o): string
    {
        $typeRule = $o['type'] === 'any'
            ? 'Mix the question types appropriately for the topic (single_choice, multi_select, short_text, numeric, essay).'
            : "Every question MUST be of type \"{$o['type']}\".";
        $lines = [];
        $lines[] = "You are an expert exam author. Generate exactly {$o['count']} exam question(s).";
        $lines[] = 'Subject: '.($o['subject'] ?: 'general').". Topic: \"{$o['topic']}\". Difficulty: {$o['difficulty']}.";
        $lines[] = "Write all content in {$o['language']}.";
        $lines[] = $typeRule;
        if ($o['lo'] !== '') {
            $lines[] = "Constrain the questions to this learning objective: \"{$o['lo']}\".";
        }
        if ($o['olympiad'] !== 'off') {
            $lines[] = "Apply olympiad intensity \"{$o['olympiad']}\": make the reasoning multi-step and non-routine.";
        }
        if ($o['extra'] !== '') {
            $lines[] = "Extra instructions: {$o['extra']}";
        }
        $lines[] = 'Novelty: do NOT copy textbook wording; author fresh items.';
        if ($o['wantImages']) {
            $lines[] = 'For questions that genuinely need a figure/diagram, add an "imagePrompt" string describing the image to render. Omit it otherwise.';
        }
        $lines[] = 'Return ONLY a JSON object with this exact shape:';
        $lines[] = '{"questions":[{"type":"single_choice","topic":"...","subtopic":"...","difficulty":"medium","prompt":"...","options":[{"id":"A","text":"..."},{"id":"B","text":"..."}],"correctAnswer":"A","points":1,"explanationText":"..."'.($o['wantImages'] ? ',"imagePrompt":"..."' : '').'}]}';
        $lines[] = 'Rules: single_choice correctAnswer = one option id; multi_select correctAnswer = array of ids; numeric = a number; short_text = expected text; essay = omit options/correctAnswer. Always include topic, prompt, points, explanationText. Output JSON only — no markdown, no commentary.';

        return implode("\n", $lines);
    }

    private function parseQuestions(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/```(?:json)?/i', '', $text);
        $text = trim(str_replace('```', '', $text));
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            $s = strpos($text, '{');
            $e = strrpos($text, '}');
            if ($s !== false && $e !== false && $e > $s) {
                $decoded = json_decode(substr($text, $s, $e - $s + 1), true);
            }
        }
        if (! is_array($decoded)) {
            return [];
        }
        if (isset($decoded['questions']) && is_array($decoded['questions'])) {
            return $decoded['questions'];
        }

        return array_is_list($decoded) ? $decoded : [];
    }

    private function shape(array $q, string $defTopic, string $defSubject, string $defLang, string $defDiff): array
    {
        $type = $q['type'] ?? 'single_choice';
        if (! in_array($type, self::TYPES, true)) {
            $type = 'single_choice';
        }
        $prompt = trim((string) ($q['prompt'] ?? ''));
        if (strlen($prompt) < 2) {
            throw new \InvalidArgumentException('no prompt');
        }
        $topic = trim((string) ($q['topic'] ?? $defTopic)) ?: $defTopic;
        $subtopic = isset($q['subtopic']) ? (trim((string) $q['subtopic']) ?: null) : null;
        $difficulty = in_array($q['difficulty'] ?? null, self::DIFFS, true) ? $q['difficulty'] : $defDiff;
        $points = is_numeric($q['points'] ?? null) ? (float) $q['points'] : 1.0;
        if ($points <= 0 || $points > 100) {
            $points = 1.0;
        }
        $expl = trim((string) ($q['explanationText'] ?? '')) ?: 'AI-generated.';
        $imagePrompt = isset($q['imagePrompt']) && is_string($q['imagePrompt']) ? trim($q['imagePrompt']) : null;
        if ($imagePrompt === '') {
            $imagePrompt = null;
        }
        $options = null;
        $correct = '';

        if ($type === 'single_choice' || $type === 'multi_select') {
            $opts = $q['options'] ?? [];
            if (! is_array($opts)) {
                throw new \InvalidArgumentException('opts');
            }
            $options = [];
            foreach ($opts as $o) {
                $id = strtoupper(trim((string) ($o['id'] ?? '')));
                $t = trim((string) ($o['text'] ?? ''));
                if ($id !== '' && $t !== '') {
                    $options[] = ['id' => $id, 'text' => $t];
                }
            }
            if (count($options) < 2) {
                throw new \InvalidArgumentException('need 2 options');
            }
            if ($type === 'single_choice') {
                $c = strtoupper(trim((string) ($q['correctAnswer'] ?? '')));
                $correct = collect($options)->contains('id', $c) ? $c : $options[0]['id'];
            } else {
                $ca = $q['correctAnswer'] ?? [];
                if (! is_array($ca)) {
                    $ca = [$ca];
                }
                $valid = collect($options)->pluck('id')->flip();
                $correct = [];
                foreach ($ca as $id) {
                    $up = strtoupper(trim((string) $id));
                    if (isset($valid[$up])) {
                        $correct[] = $up;
                    }
                }
                if (count($correct) === 0) {
                    $correct = [$options[0]['id']];
                }
            }
        } elseif ($type === 'numeric') {
            $correct = is_numeric($q['correctAnswer'] ?? null) ? (float) $q['correctAnswer'] : 0;
        } elseif ($type === 'short_text') {
            $correct = trim((string) ($q['correctAnswer'] ?? '')) ?: 'answer';
        }

        return compact('type', 'topic', 'subtopic', 'difficulty', 'prompt', 'points', 'options', 'correct', 'expl', 'imagePrompt');
    }
}
