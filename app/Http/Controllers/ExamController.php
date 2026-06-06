<?php

namespace App\Http\Controllers;

use App\Models\AnswerDraft;
use App\Models\Exam;
use App\Models\ExamSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Slice C — the exam autosave path, the make-or-break concurrency route.
 * Mirrors the Next app's draft autosave: a single bulk upsert keyed on
 * the (session_id, question_id) unique index, with NO interactive
 * transaction — the exact shape that fixed answer-loss at 2000-student
 * concurrency.
 *
 * Simplification for the slice: protected by the session cookie + a
 * session-ownership check rather than the separate exam-access token.
 */
class ExamController extends Controller
{
    private function resolveExam(string $idOrCode): ?Exam
    {
        return Exam::where('id', $idOrCode)
            ->orWhere('exam_code', $idOrCode)
            ->first();
    }

    public function questions(Request $request, string $exam)
    {
        $row = $this->resolveExam($exam);
        if (!$row) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $questions = $row->questions()
            ->orderBy('position')
            ->get(['id', 'position', 'type', 'topic', 'prompt', 'options', 'points', 'difficulty']);

        return response()->json([
            'exam' => [
                'id' => $row->id,
                'examCode' => $row->exam_code,
                'name' => $row->name,
                'durationMinutes' => $row->duration_minutes,
            ],
            'questions' => $questions,
        ]);
    }

    public function autosave(Request $request, string $exam)
    {
        $user = $request->attributes->get('authUser');
        $row = $this->resolveExam($exam);
        if (!$row) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $data = $request->validate([
            'sessionId' => 'required|string',
            'answers' => 'required|array', // { questionId: value, ... }
        ]);

        // The session must belong to this user + this exam (exam-access scope).
        $session = ExamSession::where('id', $data['sessionId'])
            ->where('user_id', $user->id)
            ->where('exam_id', $row->id)
            ->first();
        if (!$session) {
            return response()->json(['error' => 'Session not found for this user/exam.'], 403);
        }

        $now = now();
        $rows = [];
        foreach ($data['answers'] as $questionId => $value) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'session_id' => $session->id,
                'question_id' => (string) $questionId,
                // JSON column — bind the encoded string directly (MariaDB
                // has no CAST(? AS JSON), so we never wrap it).
                'value' => json_encode($value),
                'updated_at' => $now,
            ];
        }
        if (empty($rows)) {
            return response()->json(['saved' => 0]);
        }

        // One statement: INSERT ... ON DUPLICATE KEY UPDATE on the
        // (session_id, question_id) unique key. No transaction.
        AnswerDraft::upsert($rows, ['session_id', 'question_id'], ['value', 'updated_at']);
        ExamSession::where('id', $session->id)->update(['last_saved_at' => $now]);

        return response()->json(['saved' => count($rows)]);
    }
}
