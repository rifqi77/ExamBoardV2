<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExamAuthorController extends Controller
{
    private const TYPES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

    private function owns($user, ?Exam $exam): bool
    {
        return $exam && ($user->role === 'admin' || $exam->created_by === $user->id);
    }

    // POST /api/teacher/exams  — create an empty exam
    public function createExam(Request $r)
    {
        $u = $r->attributes->get('authUser');

        $code = strtoupper(trim((string) $r->input('examCode', '')));
        if (! preg_match('/^[A-Z0-9-]{3,40}$/', $code)) {
            return response()->json(['error' => 'Exam code must be 3–40 chars: uppercase letters, digits, dashes.'], 400);
        }
        $name = trim((string) $r->input('name', ''));
        if (strlen($name) < 2 || strlen($name) > 120) {
            return response()->json(['error' => 'Exam name must be 2–120 characters.'], 400);
        }
        $duration = (int) $r->input('durationMinutes', 0);
        if ($duration < 1 || $duration > 480) {
            return response()->json(['error' => 'Duration must be 1–480 minutes.'], 400);
        }
        $passing = (int) $r->input('passingGrade', -1);
        if ($passing < 0 || $passing > 100) {
            return response()->json(['error' => 'Passing grade must be 0–100.'], 400);
        }
        $instr = trim((string) $r->input('generalInstructions', ''));
        if (strlen($instr) < 5) {
            return response()->json(['error' => 'Instructions are required (at least 5 characters).'], 400);
        }
        $mode = $r->input('examMode', 'strict');
        if (! in_array($mode, ['strict', 'try_out'], true)) {
            $mode = 'strict';
        }
        $subject = trim((string) $r->input('subject', ''));

        if (Exam::where('exam_code', $code)->exists()) {
            return response()->json(['error' => "Exam code \"{$code}\" is already in use."], 409);
        }

        $exam = Exam::create([
            'id' => (string) Str::uuid(),
            'exam_code' => $code,
            'name' => $name,
            'duration_minutes' => $duration,
            'passing_grade' => $passing,
            'general_instructions' => $instr,
            'exam_mode' => $mode,
            'language' => 'English',
            'subject' => $subject !== '' ? $subject : null,
            'active' => true,
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'created_by' => $u->id,
            'created_by_name' => $u->full_name,
        ]);

        return response()->json(['examId' => $code, 'examDatabaseId' => $exam->id]);
    }

    // POST /api/teacher/exams/{examId}/questions  — append a question
    public function addQuestion(Request $r, string $examId)
    {
        $u = $r->attributes->get('authUser');
        $exam = Exam::where('id', $examId)->orWhere('exam_code', $examId)->first();
        if (! $this->owns($u, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }

        try {
            $shaped = $this->shapeQuestion($r->all());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $nextPos = (int) (ExamQuestion::where('exam_id', $exam->id)->max('position') ?? 0) + 1;

        ExamQuestion::create([
            'id' => (string) Str::uuid(),
            'exam_id' => $exam->id,
            'position' => $nextPos,
            'type' => $shaped['type'],
            'topic' => $shaped['topic'],
            'prompt' => $shaped['prompt'],
            'options' => $shaped['options'],
            'points' => $shaped['points'],
            'correct_answer' => $shaped['correct'],
            'explanation_text' => $shaped['expl'],
        ]);

        return response()->json(['ok' => true, 'position' => $nextPos]);
    }

    // POST /api/teacher/questions/{questionId}  — full update (edit in place)
    public function updateQuestion(Request $r, string $questionId)
    {
        $u = $r->attributes->get('authUser');
        $q = ExamQuestion::find($questionId);
        if (! $q) {
            return response()->json(['error' => 'Question not found.'], 404);
        }
        $exam = Exam::find($q->exam_id);
        if (! $this->owns($u, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        try {
            $shaped = $this->shapeQuestion($r->all());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
        $q->forceFill([
            'type' => $shaped['type'],
            'topic' => $shaped['topic'],
            'prompt' => $shaped['prompt'],
            'options' => $shaped['options'],
            'points' => $shaped['points'],
            'correct_answer' => $shaped['correct'],
            'explanation_text' => $shaped['expl'],
        ])->save();
        return response()->json(['ok' => true]);
    }

    // POST /api/teacher/exams/{examId}/settings  — sparse settings update
    public function updateExam(Request $r, string $examId)
    {
        $u = $r->attributes->get('authUser');
        $exam = Exam::where('id', $examId)->orWhere('exam_code', $examId)->first();
        if (! $this->owns($u, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }

        $patch = [];
        if ($r->has('name')) {
            $name = trim((string) $r->input('name'));
            if (strlen($name) < 2 || strlen($name) > 120) {
                return response()->json(['error' => 'Name must be 2–120 characters.'], 400);
            }
            $patch['name'] = $name;
        }
        if ($r->has('durationMinutes')) {
            $d = (int) $r->input('durationMinutes');
            if ($d < 1 || $d > 480) {
                return response()->json(['error' => 'Duration must be 1–480 minutes.'], 400);
            }
            $patch['duration_minutes'] = $d;
        }
        if ($r->has('passingGrade')) {
            $p = (int) $r->input('passingGrade');
            if ($p < 0 || $p > 100) {
                return response()->json(['error' => 'Passing grade must be 0–100.'], 400);
            }
            $patch['passing_grade'] = $p;
        }
        if ($r->has('generalInstructions')) {
            $i = trim((string) $r->input('generalInstructions'));
            if (strlen($i) < 5) {
                return response()->json(['error' => 'Instructions must be at least 5 characters.'], 400);
            }
            $patch['general_instructions'] = $i;
        }
        if ($r->has('examMode')) {
            $m = $r->input('examMode');
            if (! in_array($m, ['strict', 'try_out'], true)) {
                return response()->json(['error' => 'Invalid exam mode.'], 400);
            }
            $patch['exam_mode'] = $m;
        }
        if ($r->has('active')) {
            $patch['active'] = filter_var($r->input('active'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($r->has('shuffleQuestions')) {
            $patch['shuffle_questions'] = filter_var($r->input('shuffleQuestions'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($r->has('shuffleOptions')) {
            $patch['shuffle_options'] = filter_var($r->input('shuffleOptions'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($r->has('drawCount')) {
            $d = (int) $r->input('drawCount');
            $patch['draw_count'] = $d > 0 ? $d : null; // 0/blank → serve all questions
        }
        if ($r->has('subject')) {
            $s = trim((string) $r->input('subject'));
            $patch['subject'] = $s !== '' ? $s : null;
        }
        if ($r->has('mediaBaseUrl')) {
            $mb = trim((string) $r->input('mediaBaseUrl'));
            $patch['media_base_url'] = $mb !== '' ? $mb : null;
        }
        if ($r->has('startTime')) {
            $patch['start_time'] = $r->input('startTime') ?: null;
        }
        if ($r->has('endTime')) {
            $patch['end_time'] = $r->input('endTime') ?: null;
        }

        if (! $patch) {
            return response()->json(['ok' => true, 'updated' => 0]);
        }
        $exam->forceFill($patch)->save();
        return response()->json(['ok' => true, 'updated' => count($patch)]);
    }

    // POST /api/teacher/questions/{questionId}/delete
    public function deleteQuestion(Request $r, string $questionId)
    {
        $u = $r->attributes->get('authUser');
        $q = ExamQuestion::find($questionId);
        if (! $q) {
            return response()->json(['error' => 'Question not found.'], 404);
        }
        $exam = Exam::find($q->exam_id);
        if (! $this->owns($u, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        $examDbId = $q->exam_id;
        $q->delete();

        // Compact positions so they stay 1..N (ascending order → target
        // position is always ≤ source, so no unique(exam_id,position) clash).
        $pos = 1;
        foreach (ExamQuestion::where('exam_id', $examDbId)->orderBy('position')->get(['id', 'position']) as $rq) {
            if ((int) $rq->position !== $pos) {
                ExamQuestion::where('id', $rq->id)->update(['position' => $pos]);
            }
            $pos++;
        }

        return response()->json(['ok' => true]);
    }

    /** Validate + normalise a question payload (port of validateAndShape). */
    private function shapeQuestion(array $b): array
    {
        $type = $b['type'] ?? '';
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid question type.');
        }
        $prompt = trim((string) ($b['prompt'] ?? ''));
        if (strlen($prompt) < 2) {
            throw new \InvalidArgumentException('Question prompt is required.');
        }
        $points = is_numeric($b['points'] ?? null) ? (float) $b['points'] : 0;
        if ($points <= 0 || $points > 100) {
            throw new \InvalidArgumentException('Points must be between 1 and 100.');
        }
        $topic = trim((string) ($b['topic'] ?? ''));
        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }
        $expl = trim((string) ($b['explanationText'] ?? ''));
        if ($expl === '') {
            throw new \InvalidArgumentException('Explanation is required.');
        }

        $options = null;
        if ($type === 'single_choice' || $type === 'multi_select') {
            $opts = $b['options'] ?? [];
            if (! is_array($opts) || count($opts) < 2) {
                throw new \InvalidArgumentException('Choice questions need at least 2 options.');
            }
            $cap = $type === 'multi_select' ? 6 : 5;
            if (count($opts) > $cap) {
                throw new \InvalidArgumentException("Maximum {$cap} options.");
            }
            $options = [];
            $seen = [];
            foreach ($opts as $o) {
                $id = strtoupper(trim((string) ($o['id'] ?? '')));
                $text = trim((string) ($o['text'] ?? ''));
                if ($id === '' || $text === '') {
                    throw new \InvalidArgumentException('Each option needs an ID and text.');
                }
                if (isset($seen[$id])) {
                    throw new \InvalidArgumentException("Duplicate option ID \"{$id}\".");
                }
                $seen[$id] = true;
                $options[] = ['id' => $id, 'text' => $text];
            }
        }

        $correct = '';
        if ($type === 'single_choice') {
            $c = strtoupper(trim((string) ($b['correctAnswer'] ?? '')));
            if (! collect($options)->contains('id', $c)) {
                throw new \InvalidArgumentException('Correct option must match one of the options.');
            }
            $correct = $c;
        } elseif ($type === 'multi_select') {
            $ca = $b['correctAnswer'] ?? [];
            if (! is_array($ca) || count($ca) === 0) {
                throw new \InvalidArgumentException('Pick at least one correct option.');
            }
            $valid = collect($options)->pluck('id')->flip();
            $correct = [];
            foreach ($ca as $id) {
                $up = strtoupper(trim((string) $id));
                if (! isset($valid[$up])) {
                    throw new \InvalidArgumentException('Correct options must match the listed options.');
                }
                $correct[] = $up;
            }
        } elseif ($type === 'short_text') {
            $c = trim((string) ($b['correctAnswer'] ?? ''));
            if ($c === '') {
                throw new \InvalidArgumentException('Provide the expected text answer.');
            }
            $correct = $c;
        } elseif ($type === 'numeric') {
            if (! is_numeric($b['correctAnswer'] ?? null)) {
                throw new \InvalidArgumentException('Provide a numeric correct answer.');
            }
            $correct = (float) $b['correctAnswer'];
        }

        return compact('type', 'topic', 'prompt', 'points', 'options', 'correct', 'expl');
    }
}
