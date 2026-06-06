<?php

namespace App\Http\Controllers;

use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Services\Shuffle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Question bank: browse/filter, edit, delete, import (JSON), and the two
 * exam-builder operations — add selected bank questions to an exam, and
 * auto-fill an exam from the bank to match its type+difficulty
 * distribution. Ported from the Next /api/teacher/bank* + exam from-bank
 * + auto-fill routes.
 *
 * Visibility rule (mirrors the Next app): a teacher works only with bank
 * questions THEY uploaded (uploaded_by = self); an admin sees the whole
 * shared bank.
 */
class BankController extends Controller
{
    private const TYPE_KEYS = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];
    private const DIFFICULTY_KEYS = ['easy', 'medium', 'hard', 'hots', 'olympiad'];
    private const DEFAULT_DIFFICULTY = ['easy' => 30, 'medium' => 40, 'hard' => 20, 'hots' => 7, 'olympiad' => 3];

    private function scope($query, $user)
    {
        return $user->role === 'admin' ? $query : $query->where('uploaded_by', $user->id);
    }

    private function owns($user, ?BankQuestion $q): bool
    {
        return $q && ($user->role === 'admin' || $q->uploaded_by === $user->id);
    }

    private function loadOwnedExam(string $idOrCode, $user): ?Exam
    {
        $exam = Exam::where('id', $idOrCode)->orWhere('exam_code', $idOrCode)->first();
        if (! $exam) {
            return null;
        }
        return ($user->role === 'admin' || $exam->created_by === $user->id) ? $exam : null;
    }

    // GET /{role}/bank  — the browse page
    public function page(Request $request)
    {
        $u = $request->attributes->get('authUser');
        return Inertia::render('Teacher/Bank', [
            'options' => $this->optionData($u),
            'initial' => $this->query($request, $u, 200),
            'bankBasePath' => '/'.$u->role.'/bank',
        ]);
    }

    // GET /api/teacher/bank  — filtered list (JSON)
    public function list(Request $request)
    {
        $u = $request->attributes->get('authUser');
        return response()->json(['questions' => $this->query($request, $u, (int) $request->input('limit', 300))]);
    }

    // GET /api/teacher/bank/options
    public function options(Request $request)
    {
        return response()->json($this->optionData($request->attributes->get('authUser')));
    }

    private function query(Request $request, $u, int $limit): array
    {
        $limit = max(1, min(2000, $limit));
        $rows = $this->scope(BankQuestion::query(), $u)
            ->when($request->filled('subject'), fn ($q) => $q->where('subject', $request->input('subject')))
            ->when($request->filled('topic'), fn ($q) => $q->where('topic', $request->input('topic')))
            ->when($request->filled('subtopic'), fn ($q) => $q->where('subtopic', $request->input('subtopic')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('difficulty'), fn ($q) => $q->where('difficulty', $request->input('difficulty')))
            ->when($request->filled('language'), fn ($q) => $q->where('language', $request->input('language')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = '%'.$request->input('q').'%';
                $q->where(fn ($w) => $w->where('prompt', 'like', $s)->orWhere('topic', 'like', $s)->orWhere('subtopic', 'like', $s));
            })
            ->orderByDesc('created_at')->limit($limit)->get();

        return $rows->map(fn ($b) => [
            'id' => $b->id,
            'type' => $b->type,
            'language' => $b->language,
            'subject' => $b->subject,
            'topic' => $b->topic,
            'subtopic' => $b->subtopic,
            'difficulty' => $b->difficulty,
            'prompt' => $b->prompt,
            'options' => $b->options,
            'points' => $b->points,
            'correctAnswer' => $b->correct_answer,
            'explanationText' => $b->explanation_text,
            'mediaUrl' => $b->media_url,
            'mediaType' => $b->media_type,
            'uploadedByName' => $b->uploaded_by_name,
        ])->all();
    }

    private function optionData($u): array
    {
        $base = fn () => $this->scope(BankQuestion::query(), $u);
        $distinct = fn (string $col) => $base()->whereNotNull($col)->where($col, '!=', '')->distinct()->orderBy($col)->pluck($col)->all();
        return [
            'subjects' => $distinct('subject'),
            'topics' => $distinct('topic'),
            'subtopics' => $distinct('subtopic'),
            'languages' => $distinct('language'),
            'difficulties' => $distinct('difficulty'),
            'types' => $distinct('type'),
        ];
    }

    // POST /api/teacher/bank/{id}  — edit a bank question
    public function update(Request $request, string $id)
    {
        $u = $request->attributes->get('authUser');
        $q = BankQuestion::find($id);
        if (! $this->owns($u, $q)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        try {
            $shaped = $this->shape($request->all());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
        $q->forceFill($shaped)->save();
        return response()->json(['ok' => true]);
    }

    // POST /api/teacher/bank/{id}/delete
    public function destroy(Request $request, string $id)
    {
        $u = $request->attributes->get('authUser');
        $q = BankQuestion::find($id);
        if (! $this->owns($u, $q)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        $q->delete();
        return response()->json(['ok' => true]);
    }

    // POST /api/teacher/bank/import  — { questions: [...] }
    public function import(Request $request)
    {
        $u = $request->attributes->get('authUser');
        $items = $request->input('questions');
        if (! is_array($items) || count($items) === 0) {
            return response()->json(['error' => 'Provide a non-empty "questions" array.'], 400);
        }
        if (count($items) > 2000) {
            return response()->json(['error' => 'Too many questions (max 2000).'], 400);
        }
        $imported = 0;
        $skipped = 0;
        $errors = [];
        foreach ($items as $i => $raw) {
            try {
                $shaped = $this->shape(is_array($raw) ? $raw : []);
            } catch (\InvalidArgumentException $e) {
                $skipped++;
                if (count($errors) < 20) {
                    $errors[] = ['index' => $i, 'error' => $e->getMessage()];
                }
                continue;
            }
            BankQuestion::create(array_merge($shaped, [
                'id' => (string) Str::uuid(),
                'created_by' => $u->id,
                'created_by_name' => $u->full_name,
                'uploaded_by' => $u->id,
                'uploaded_by_name' => $u->full_name,
                'source_file_name' => (string) $request->input('sourceFileName', 'json-import'),
                'created_at' => now(),
            ]));
            $imported++;
        }
        return response()->json(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    }

    // POST /api/teacher/exams/{examId}/questions/from-bank  — { bankIds: [] }
    public function fromBank(Request $request, string $examId)
    {
        $u = $request->attributes->get('authUser');
        $exam = $this->loadOwnedExam($examId, $u);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }
        $bankIds = collect($request->input('bankIds', []))->filter(fn ($v) => is_string($v))->values();
        if ($bankIds->isEmpty()) {
            return response()->json(['added' => 0]);
        }

        $bankRows = $this->scope(BankQuestion::whereIn('id', $bankIds), $u)->get();
        $already = ExamQuestion::where('exam_id', $exam->id)->whereIn('source_bank_question_id', $bankIds)
            ->pluck('source_bank_question_id')->filter()->flip();
        $toAdd = $bankRows->filter(fn ($b) => ! isset($already[$b->id]));
        if ($toAdd->isEmpty()) {
            return response()->json(['added' => 0]);
        }

        $pos = (int) (ExamQuestion::where('exam_id', $exam->id)->max('position') ?? 0);
        $added = 0;
        DB::transaction(function () use ($toAdd, $exam, &$pos, &$added) {
            foreach ($toAdd as $bank) {
                $pos++;
                $added++;
                $this->copyBankToExam($bank, $exam->id, $pos);
            }
        });
        return response()->json(['added' => $added]);
    }

    // POST /api/teacher/exams/{examId}/auto-fill
    public function autoFill(Request $request, string $examId)
    {
        $u = $request->attributes->get('authUser');
        $exam = $this->loadOwnedExam($examId, $u);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $typeDist = array_merge(array_fill_keys(self::TYPE_KEYS, 0), is_array($exam->type_distribution) ? $exam->type_distribution : []);
        $diffDist = array_merge(self::DEFAULT_DIFFICULTY, is_array($exam->difficulty_distribution) ? $exam->difficulty_distribution : []);
        $totalTarget = array_sum(array_intersect_key($typeDist, array_flip(self::TYPE_KEYS)));
        if ($totalTarget === 0) {
            return response()->json(['added' => 0, 'warnings' => ["No type targets set — fill in the exam's question-type counts first."], 'matrix' => []]);
        }

        $examLang = trim((string) ($exam->language ?? ''));
        $examSubject = trim((string) ($exam->subject ?? ''));

        $candidates = $this->scope(BankQuestion::query(), $u)
            ->when($examLang !== '', fn ($q) => $q->where('language', $examLang))
            ->when($examSubject !== '', fn ($q) => $q->where('subject', $examSubject))
            ->get();
        $alreadyCopied = ExamQuestion::where('exam_id', $exam->id)->whereNotNull('source_bank_question_id')
            ->pluck('source_bank_question_id')->filter()->flip();
        $inScope = $candidates->filter(fn ($q) => ! isset($alreadyCopied[$q->id]))->values();

        $warnings = [];
        if ($inScope->isEmpty()) {
            $owned = $this->scope(BankQuestion::query(), $u)->count();
            if ($owned > 0) {
                $scope = $examSubject ? "language \"{$exam->language}\" and subject \"{$exam->subject}\"" : "language \"{$exam->language}\"";
                $warnings[] = "No bank questions match the exam {$scope}.";
            } else {
                $warnings[] = 'Your question bank is empty — import questions first.';
            }
        }

        // Bucket pool by type|difficulty.
        $bankByKey = [];
        foreach ($inScope as $q) {
            $key = $q->type.'|'.($q->difficulty ?: 'medium');
            $bankByKey[$key][] = $q;
        }

        // Current per-cell counts (top-up).
        $existingCounts = [];
        foreach (ExamQuestion::where('exam_id', $exam->id)->get(['type', 'difficulty']) as $r) {
            $key = $r->type.'|'.($r->difficulty ?: 'medium');
            $existingCounts[$key] = ($existingCounts[$key] ?? 0) + 1;
        }

        $matrix = [];
        $toCopy = [];
        foreach (self::TYPE_KEYS as $type) {
            $typeTarget = (int) ($typeDist[$type] ?? 0);
            if ($typeTarget === 0) {
                continue;
            }
            $cellTargets = $this->distributeByDifficulty($typeTarget, $diffDist);
            foreach (self::DIFFICULTY_KEYS as $difficulty) {
                $wanted = $cellTargets[$difficulty];
                if ($wanted === 0) {
                    continue;
                }
                $existingHere = $existingCounts[$type.'|'.$difficulty] ?? 0;
                $remaining = max(0, $wanted - $existingHere);
                if ($remaining === 0) {
                    $matrix[] = compact('type', 'difficulty', 'wanted') + ['got' => 0];
                    continue;
                }
                $pool = $bankByKey[$type.'|'.$difficulty] ?? [];
                $shuffled = Shuffle::shuffleWithSeed($pool, $exam->exam_code.'_autofill_'.$type.'_'.$difficulty);
                $got = min($remaining, count($shuffled));
                if ($existingHere + $got < $wanted) {
                    $warnings[] = "Wanted {$wanted} {$type} ({$difficulty}) but exam will have ".($existingHere + $got)." ({$existingHere} already + ".count($shuffled)." available).";
                }
                $matrix[] = compact('type', 'difficulty', 'wanted', 'got');
                for ($i = 0; $i < $got; $i++) {
                    $toCopy[] = $shuffled[$i];
                }
            }
        }

        if (count($toCopy) === 0) {
            return response()->json(['added' => 0, 'warnings' => $warnings ?: ['Bank has no matching questions.'], 'matrix' => $matrix]);
        }

        // Order picks by curriculum topic order (learning_objectives.sort_order).
        $loRows = DB::table('learning_objectives')
            ->when($u->role !== 'admin', fn ($q) => $q->where('uploaded_by', $u->id))
            ->orderBy('subject')->orderBy('sort_order')->pluck('topic');
        $topicRankMap = [];
        $idx = 0;
        foreach ($loRows as $t) {
            $key = strtolower(trim((string) $t));
            if ($key === '' || isset($topicRankMap[$key])) {
                continue;
            }
            $topicRankMap[$key] = $idx++;
        }
        $rank = fn ($topic) => $topicRankMap[strtolower(trim((string) $topic))] ?? PHP_INT_MAX;
        usort($toCopy, function ($a, $b) use ($rank) {
            $ra = $rank($a->topic);
            $rb = $rank($b->topic);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $tc = strcmp((string) $a->topic, (string) $b->topic);
            if ($tc !== 0) {
                return $tc;
            }
            return array_search($a->type, self::TYPE_KEYS) <=> array_search($b->type, self::TYPE_KEYS);
        });

        $pos = (int) (ExamQuestion::where('exam_id', $exam->id)->max('position') ?? 0);
        $added = 0;
        DB::transaction(function () use ($toCopy, $exam, &$pos, &$added) {
            foreach ($toCopy as $bank) {
                $pos++;
                $added++;
                $this->copyBankToExam($bank, $exam->id, $pos);
            }
        });

        return response()->json(['added' => $added, 'warnings' => $warnings, 'matrix' => $matrix]);
    }

    /** Copy one bank question into an exam at the given position (+ media). */
    private function copyBankToExam(BankQuestion $bank, string $examId, int $position): void
    {
        $qid = (string) Str::uuid();
        ExamQuestion::create([
            'id' => $qid,
            'exam_id' => $examId,
            'position' => $position,
            'type' => $bank->type,
            'topic' => $bank->topic,
            'tags' => $bank->tags,
            'prompt' => $bank->prompt,
            'options' => $bank->options,
            'points' => $bank->points,
            'difficulty' => $bank->difficulty,
            'language' => $bank->language,
            'source_bank_question_id' => $bank->id,
            'correct_answer' => $bank->correct_answer,
            'explanation_text' => $bank->explanation_text ?? '',
        ]);
        if ($bank->media_url && $bank->media_type) {
            DB::table('exam_media')->insert([
                'id' => (string) Str::uuid(),
                'question_id' => $qid,
                'type' => $bank->media_type,
                'url' => $bank->media_url,
                'sort_order' => 0,
                'created_at' => now(),
            ]);
        }
    }

    /** Hare-Niemeyer split of a type target across difficulty buckets. */
    private function distributeByDifficulty(int $typeTarget, array $percents): array
    {
        $result = array_fill_keys(self::DIFFICULTY_KEYS, 0);
        if ($typeTarget <= 0) {
            return $result;
        }
        $raw = [];
        $sumFloor = 0;
        foreach (self::DIFFICULTY_KEYS as $k) {
            $raw[$k] = ($typeTarget * ($percents[$k] ?? 0)) / 100;
            $result[$k] = (int) floor($raw[$k]);
            $sumFloor += $result[$k];
        }
        $leftover = $typeTarget - $sumFloor;
        $byRemainder = self::DIFFICULTY_KEYS;
        usort($byRemainder, fn ($a, $b) => ($raw[$b] - floor($raw[$b])) <=> ($raw[$a] - floor($raw[$a])));
        $i = 0;
        while ($leftover > 0 && $i < count($byRemainder)) {
            $result[$byRemainder[$i]]++;
            $leftover--;
            $i++;
        }
        return $result;
    }

    /** Validate + normalise a bank-question payload. */
    private function shape(array $b): array
    {
        $type = $b['type'] ?? '';
        if (! in_array($type, self::TYPE_KEYS, true)) {
            throw new \InvalidArgumentException('Invalid question type.');
        }
        $prompt = trim((string) ($b['prompt'] ?? ''));
        if (strlen($prompt) < 2) {
            throw new \InvalidArgumentException('Question prompt is required.');
        }
        $points = is_numeric($b['points'] ?? null) ? (float) $b['points'] : 1.0;
        if ($points <= 0 || $points > 100) {
            $points = 1.0;
        }
        $topic = trim((string) ($b['topic'] ?? ''));
        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }
        $difficulty = $b['difficulty'] ?? null;
        if ($difficulty !== null && ! in_array($difficulty, self::DIFFICULTY_KEYS, true)) {
            $difficulty = null;
        }

        $options = null;
        $correct = null;
        if ($type === 'single_choice' || $type === 'multi_select') {
            $opts = $b['options'] ?? [];
            if (! is_array($opts) || count($opts) < 2) {
                throw new \InvalidArgumentException('Choice questions need at least 2 options.');
            }
            $options = [];
            foreach ($opts as $o) {
                $oid = strtoupper(trim((string) ($o['id'] ?? '')));
                $text = trim((string) ($o['text'] ?? ''));
                if ($oid === '' || $text === '') {
                    continue;
                }
                $options[] = ['id' => $oid, 'text' => $text];
            }
            if (count($options) < 2) {
                throw new \InvalidArgumentException('Choice questions need at least 2 non-empty options.');
            }
            if ($type === 'single_choice') {
                $correct = strtoupper(trim((string) ($b['correctAnswer'] ?? '')));
                if (! collect($options)->contains('id', $correct)) {
                    throw new \InvalidArgumentException('Correct option must match one of the options.');
                }
            } else {
                $ca = $b['correctAnswer'] ?? [];
                if (! is_array($ca) || count($ca) === 0) {
                    throw new \InvalidArgumentException('Pick at least one correct option.');
                }
                $valid = collect($options)->pluck('id')->flip();
                $correct = [];
                foreach ($ca as $cid) {
                    $up = strtoupper(trim((string) $cid));
                    if (isset($valid[$up])) {
                        $correct[] = $up;
                    }
                }
            }
        } elseif ($type === 'short_text') {
            $correct = trim((string) ($b['correctAnswer'] ?? ''));
        } elseif ($type === 'numeric') {
            if (! is_numeric($b['correctAnswer'] ?? null)) {
                throw new \InvalidArgumentException('Provide a numeric correct answer.');
            }
            $correct = (float) $b['correctAnswer'];
        }

        return [
            'type' => $type,
            'language' => isset($b['language']) ? (trim((string) $b['language']) ?: null) : null,
            'subject' => isset($b['subject']) ? (trim((string) $b['subject']) ?: null) : null,
            'topic' => $topic,
            'subtopic' => isset($b['subtopic']) ? (trim((string) $b['subtopic']) ?: null) : null,
            'difficulty' => $difficulty,
            'prompt' => $prompt,
            'options' => $options,
            'points' => $points,
            'correct_answer' => $correct,
            'explanation_text' => trim((string) ($b['explanationText'] ?? '')),
        ];
    }
}
