<?php

namespace App\Http\Controllers;

use App\Models\BankQuestion;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import an exam package — { metadata, questions[], media[] } — matching the
 * Next.js app's export format so packages move between the two systems. Creates
 * the exam + its questions (+ inline media) and mirrors each question into the
 * shared question bank, all in one transaction.
 */
class ExamImportController extends Controller
{
    private const TYPES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

    // POST /api/teacher/exams/import
    public function import(Request $r)
    {
        $u = $r->attributes->get('authUser');
        $meta = (array) $r->input('metadata', []);

        $code = strtoupper(trim((string) ($meta['examCode'] ?? '')));
        if (! preg_match('/^[A-Z0-9-]{3,40}$/', $code)) {
            return response()->json(['error' => 'Exam code must be 3–40 chars: uppercase letters, digits, dashes.'], 400);
        }
        $name = trim((string) ($meta['name'] ?? ''));
        if (strlen($name) < 2 || strlen($name) > 120) {
            return response()->json(['error' => 'Exam name must be 2–120 characters.'], 400);
        }
        $duration = (int) ($meta['durationMinutes'] ?? 0);
        if ($duration < 1 || $duration > 480) {
            return response()->json(['error' => 'Duration must be 1–480 minutes.'], 400);
        }
        $passing = (int) ($meta['passingGrade'] ?? -1);
        if ($passing < 0 || $passing > 100) {
            return response()->json(['error' => 'Passing grade must be 0–100.'], 400);
        }
        $instr = trim((string) ($meta['generalInstructions'] ?? ''));
        if (strlen($instr) < 5) {
            return response()->json(['error' => 'Instructions are required (at least 5 characters).'], 400);
        }
        $language = trim((string) ($meta['language'] ?? 'English')) ?: 'English';
        $subject = trim((string) ($meta['subject'] ?? ''));
        $mode = ($meta['examMode'] ?? 'strict') === 'try_out' ? 'try_out' : 'strict';

        $questionsIn = $r->input('questions', []);
        if (! is_array($questionsIn) || count($questionsIn) === 0) {
            return response()->json(['error' => 'Package has no questions.'], 400);
        }
        if (Exam::where('exam_code', $code)->exists()) {
            return response()->json(['error' => "Exam code \"{$code}\" is already in use."], 409);
        }

        $mediaByFile = [];
        foreach ((array) $r->input('media', []) as $m) {
            if (is_array($m) && isset($m['fileName'])) {
                $mediaByFile[(string) $m['fileName']] = $m;
            }
        }

        $warnings = [];
        $prepared = [];
        usort($questionsIn, fn ($a, $b) => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));
        foreach ($questionsIn as $q) {
            $pos = (int) ($q['position'] ?? 0);
            $prompt = trim((string) ($q['prompt'] ?? ''));
            if (strlen($prompt) < 2) {
                $warnings[] = "Question at position {$pos}: prompt too short, skipped.";

                continue;
            }
            $points = is_numeric($q['points'] ?? null) ? (float) $q['points'] : 0;
            if ($points <= 0 || $points > 100) {
                $warnings[] = "Question at position {$pos}: invalid points, skipped.";

                continue;
            }
            $type = (string) ($q['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                $warnings[] = "Question at position {$pos}: invalid type, skipped.";

                continue;
            }
            $mediaEntry = null;
            $mediaFile = $q['mediaFile'] ?? null;
            if ($mediaFile) {
                if (isset($mediaByFile[$mediaFile])) {
                    $mediaEntry = $mediaByFile[$mediaFile];
                } else {
                    $warnings[] = "Question at position {$pos}: media \"{$mediaFile}\" not found in package.";
                }
            }
            [$options, $correct] = $this->shape($type, $q['options'] ?? null, $q['correctAnswer'] ?? null);
            $prepared[] = [
                'position' => count($prepared) + 1,
                'type' => $type,
                'topic' => trim((string) ($q['topic'] ?? '')) ?: 'General',
                'points' => $points,
                'prompt' => $prompt,
                'options' => $options,
                'correct' => $correct,
                'expl' => trim((string) ($q['explanationText'] ?? '')),
                'media' => $mediaEntry,
            ];
        }
        if (count($prepared) === 0) {
            return response()->json(['error' => 'All questions in the package failed validation.', 'warnings' => $warnings], 400);
        }

        $result = DB::transaction(function () use ($u, $code, $name, $duration, $passing, $instr, $mode, $language, $subject, $prepared) {
            $exam = Exam::create([
                'id' => (string) Str::uuid(),
                'exam_code' => $code, 'name' => $name, 'duration_minutes' => $duration,
                'passing_grade' => $passing, 'general_instructions' => $instr, 'exam_mode' => $mode,
                'language' => $language, 'subject' => $subject !== '' ? $subject : null,
                'active' => true, 'shuffle_questions' => false, 'shuffle_options' => false,
                'created_by' => $u->id, 'created_by_name' => $u->full_name,
            ]);

            $bankOwner = User::where('role', 'admin')->orderBy('created_at')->first();
            $bankOwnerId = $bankOwner->id ?? $u->id;
            $bankOwnerName = $bankOwner->full_name ?? $u->full_name;

            $qCreated = 0;
            $mCreated = 0;
            $bCreated = 0;
            foreach ($prepared as $p) {
                $qid = (string) Str::uuid();
                ExamQuestion::create([
                    'id' => $qid, 'exam_id' => $exam->id, 'position' => $p['position'],
                    'type' => $p['type'], 'topic' => $p['topic'], 'prompt' => $p['prompt'],
                    'options' => $p['options'], 'points' => $p['points'], 'difficulty' => 'medium',
                    'language' => $language, 'correct_answer' => $p['correct'], 'explanation_text' => $p['expl'],
                ]);
                $qCreated++;

                $mediaUrl = null;
                $mediaType = null;
                if ($p['media'] && isset($p['media']['dataUrl'])) {
                    $mediaUrl = (string) $p['media']['dataUrl'];
                    $mediaType = (string) ($p['media']['type'] ?? 'image');
                    DB::table('exam_media')->insert([
                        'id' => (string) Str::uuid(), 'question_id' => $qid, 'type' => $mediaType,
                        'url' => $mediaUrl, 'sort_order' => 0, 'created_at' => now(),
                    ]);
                    $mCreated++;
                }

                BankQuestion::create([
                    'id' => (string) Str::uuid(), 'type' => $p['type'], 'language' => $language,
                    'subject' => $subject ?: null, 'topic' => $p['topic'], 'subtopic' => null, 'difficulty' => 'medium',
                    'prompt' => $p['prompt'], 'options' => $p['options'], 'points' => $p['points'],
                    'correct_answer' => $p['correct'], 'explanation_text' => $p['expl'],
                    'media_url' => $mediaUrl, 'media_type' => $mediaType,
                    'created_by' => $bankOwnerId, 'created_by_name' => $bankOwnerName,
                    'uploaded_by' => $u->id, 'uploaded_by_name' => $u->full_name,
                    'source_file_name' => "{$name} (exam package)", 'created_at' => now(),
                ]);
                $bCreated++;
            }

            return ['examId' => $code, 'examDatabaseId' => $exam->id, 'questionsCreated' => $qCreated, 'mediaCreated' => $mCreated, 'bankCreated' => $bCreated];
        });

        Audit::log($r, 'exam.import', 'exam', $result['examDatabaseId'], "Imported exam {$code} ({$result['questionsCreated']} questions)", ['code' => $code, 'questions' => $result['questionsCreated']]);

        return response()->json($result + ['warnings' => $warnings]);
    }

    /** Normalize options + correct answer per question type. @return array{0:?array,1:mixed} */
    private function shape(string $type, $options, $correct): array
    {
        if ($type === 'single_choice' || $type === 'multi_select') {
            $opts = [];
            foreach ((array) $options as $o) {
                $id = strtoupper(trim((string) ($o['id'] ?? '')));
                $t = trim((string) ($o['text'] ?? ''));
                if ($id !== '' && $t !== '') {
                    $opts[] = ['id' => $id, 'text' => $t];
                }
            }
            $validIds = array_column($opts, 'id');
            if ($type === 'single_choice') {
                $c = strtoupper(trim((string) $correct));
                $correctOut = in_array($c, $validIds, true) ? $c : ($opts[0]['id'] ?? '');
            } else {
                $ca = is_array($correct) ? $correct : [$correct];
                $correctOut = [];
                foreach ($ca as $id) {
                    $up = strtoupper(trim((string) $id));
                    if (in_array($up, $validIds, true)) {
                        $correctOut[] = $up;
                    }
                }
            }

            return [$opts ?: null, $correctOut];
        }
        if ($type === 'numeric') {
            return [null, is_numeric($correct) ? (float) $correct : 0];
        }
        if ($type === 'short_text') {
            return [null, trim((string) $correct)];
        }

        return [null, null]; // essay
    }
}
