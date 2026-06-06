<?php

namespace App\Http\Controllers;

use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Services\AiProviders;
use App\Services\Capabilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AiGenerateController extends Controller
{
    private const TYPES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];
    private const DIFFS = ['easy', 'medium', 'hard', 'hots', 'olympiad'];

    public function page(Request $r)
    {
        $u = $r->attributes->get('authUser');
        $isTeacher = $u->role === 'teacher';
        $exams = Exam::when($isTeacher, fn ($q) => $q->where('created_by', $u->id))
            ->orderByDesc('created_at')->get(['exam_code', 'name']);
        $s = AiProviders::getSettings();

        // Learning-objective context for the topic picker.
        $loScope = fn ($q) => $isTeacher ? $q->where('uploaded_by', $u->id) : $q;
        $loTopics = $loScope(DB::table('learning_objectives'))->whereNotNull('topic')->where('topic', '!=', '')
            ->distinct()->orderBy('topic')->pluck('topic')->all();
        $subjects = $loScope(DB::table('learning_objectives'))->whereNotNull('subject')->where('subject', '!=', '')
            ->distinct()->orderBy('subject')->pluck('subject')->all();

        return Inertia::render('Teacher/AiGenerate', [
            'exams' => $exams->map(fn ($e) => ['examId' => $e->exam_code, 'name' => $e->name]),
            'provider' => $s['textProvider'],
            'model' => $s['textModel'],
            'imageProvider' => $s['imageProvider'],
            'keyReady' => $s['textProvider'] === 'pollinations' ? true : (AiProviders::keyStatus()[$s['textProvider']] ?? false),
            'loTopics' => $loTopics,
            'subjects' => $subjects,
        ]);
    }

    // GET /api/teacher/ai-generate/status
    public function status()
    {
        $s = AiProviders::getSettings();
        $available = $s['textProvider'] === 'pollinations' || (AiProviders::keyStatus()[$s['textProvider']] ?? false);
        return response()->json([
            'available' => $available,
            'provider' => $s['textProvider'],
            'model' => $s['textModel'],
            'imageProvider' => $s['imageProvider'],
        ]);
    }

    // POST /api/teacher/ai-generate/run
    public function run(Request $r)
    {
        $u = $r->attributes->get('authUser');
        if (! Capabilities::has($u, 'ai.generate')) {
            return response()->json(['error' => 'Your account is not permitted to use AI generation.'], 403);
        }
        $target = $r->input('target') === 'bank' ? 'bank' : 'exam';

        $exam = null;
        if ($target === 'exam') {
            $exam = Exam::where('exam_code', $r->input('examId'))->orWhere('id', $r->input('examId'))->first();
            if (! $exam || ($u->role !== 'admin' && $exam->created_by !== $u->id)) {
                return response()->json(['error' => 'Exam not found or not yours.'], 403);
            }
        }

        $count = max(1, min(100, (int) $r->input('count', 5)));
        $type = in_array($r->input('type'), self::TYPES, true) ? $r->input('type') : 'any';
        $topic = trim((string) $r->input('topic', '')) ?: 'General';
        $subject = trim((string) $r->input('subject', ''));
        $language = trim((string) $r->input('language', 'English')) ?: 'English';
        $difficulty = in_array($r->input('difficulty'), self::DIFFS, true) ? $r->input('difficulty') : 'medium';
        $extra = trim((string) $r->input('extraInstructions', ''));
        $lo = trim((string) $r->input('learningObjective', ''));
        $olympiad = in_array($r->input('olympiadIntensity'), ['intro', 'moderate', 'extreme'], true) ? $r->input('olympiadIntensity') : 'off';

        $settings = AiProviders::getSettings();
        if ($settings['textProvider'] !== 'pollinations' && ! (AiProviders::keyStatus()[$settings['textProvider']] ?? false)) {
            return response()->json(['error' => "No API key for {$settings['textProvider']}. Set it in Admin → AI settings, or switch to Pollinations."], 503);
        }
        $wantImages = filter_var($r->input('generateImages', false), FILTER_VALIDATE_BOOLEAN) && $settings['imageProvider'] !== 'off';

        $prompt = $this->buildPrompt(compact('count', 'type', 'topic', 'subject', 'language', 'difficulty', 'extra', 'lo', 'olympiad', 'wantImages'));
        try {
            $text = AiProviders::generateText($prompt, true, $settings);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
        $parsed = $this->parseQuestions($text);
        if (count($parsed) === 0) {
            return response()->json(['error' => 'The AI returned no parseable questions. Try again.'], 502);
        }

        $created = [];
        $imageCount = 0;
        $pos = $exam ? (int) (ExamQuestion::where('exam_id', $exam->id)->max('position') ?? 0) : 0;

        foreach ($parsed as $q) {
            try {
                $shaped = $this->shape(is_array($q) ? $q : [], $topic, $subject, $language, $difficulty);
            } catch (\Throwable $e) {
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
                    'created_by' => $u->id, 'created_by_name' => $u->full_name,
                    'uploaded_by' => $u->id, 'uploaded_by_name' => $u->full_name,
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
        }

        return response()->json([
            'created' => count($created),
            'target' => $target,
            'imageCount' => $imageCount,
            'questions' => $created,
        ]);
    }

    private function buildPrompt(array $o): string
    {
        $typeRule = $o['type'] === 'any'
            ? 'Mix the question types appropriately for the topic (single_choice, multi_select, short_text, numeric, essay).'
            : "Every question MUST be of type \"{$o['type']}\".";
        $lines = [];
        $lines[] = "You are an expert exam author. Generate exactly {$o['count']} exam question(s).";
        $lines[] = "Subject: ".($o['subject'] ?: 'general').". Topic: \"{$o['topic']}\". Difficulty: {$o['difficulty']}.";
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
