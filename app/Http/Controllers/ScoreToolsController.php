<?php

namespace App\Http\Controllers;

use App\Models\AnswerDraft;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Services\Audit;
use App\Services\ExamDraw;
use App\Services\Scoring;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Score recovery + bulk grading tools, ported from the Next app:
 *   - finalize-drafts  (recover lost submissions from draft/expired sessions)
 *   - reset-session    (clear a stuck student's sessions so they can restart)
 *   - grade-bulk       (apply AI-suggested essay scores in one batch)
 *   - ai-export        (markdown bundle of pending essays to paste into an AI)
 *   - bulk-delete / delete submission
 *   - pending-score page
 */
class ScoreToolsController extends Controller
{
    private function resolveExam(string $idOrCode): ?Exam
    {
        return Exam::where('id', $idOrCode)->orWhere('exam_code', $idOrCode)->first();
    }

    private function owns($user, ?Exam $exam): bool
    {
        return $exam && ($user->role === 'admin' || $exam->created_by === $user->id);
    }

    // POST /api/teacher/exams/{examId}/finalize-drafts
    public function finalizeDrafts(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $this->owns($user, $exam)) {
            return response()->json(['error' => "You don't own this exam."], 403);
        }

        $questions = ExamQuestion::where('exam_id', $exam->id)->orderBy('position')
            ->get(['id', 'topic', 'points', 'type', 'correct_answer']);
        if ($questions->isEmpty()) {
            return response()->json(['error' => 'This exam has no questions; nothing to finalize.'], 400);
        }
        $scoreInput = $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all();
        $keys = $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all();

        // Draft/expired sessions with no submission row yet.
        $withSub = ExamSubmission::where('exam_id', $exam->id)->pluck('session_id')->filter()->all();
        $sessions = ExamSession::where('exam_id', $exam->id)
            ->whereIn('status', ['draft', 'expired'])
            ->when($withSub, fn ($q) => $q->whereNotIn('id', $withSub))
            ->get();

        $created = 0;
        $skippedEmpty = 0;
        $errors = 0;
        $errorDetails = [];

        foreach ($sessions as $s) {
            $drafts = AnswerDraft::where('session_id', $s->id)->get(['question_id', 'value']);
            if ($drafts->isEmpty()) {
                $skippedEmpty++;
                continue;
            }
            $snapshot = [];
            foreach ($drafts as $d) {
                $snapshot[$d->question_id] = $d->value;
            }
            $sQ = ExamDraw::filter($questions, $s->drawn_question_ids);
            $score = Scoring::score(
                $sQ->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all(),
                $sQ->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
                $snapshot
            );
            $passed = $score['percentScore'] >= $exam->passing_grade;
            $events = is_array($s->anti_cheat_events) ? $s->anti_cheat_events : [];
            $usr = DB::table('users')->where('id', $s->user_id)->first(['username', 'full_name']);

            try {
                ExamSubmission::create([
                    'id' => (string) Str::uuid(),
                    'exam_id' => $exam->id,
                    'user_id' => $s->user_id,
                    'session_id' => $s->id,
                    'attempt' => $s->attempt,
                    'username' => $usr->username ?? '?',
                    'full_name' => $usr->full_name ?? '?',
                    'exam_name' => $exam->name,
                    'exam_mode' => $exam->exam_mode,
                    'passing_grade' => $exam->passing_grade,
                    'final_score' => $score['finalScore'],
                    'possible_score' => $score['possibleScore'],
                    'percent_score' => $score['percentScore'],
                    'passed' => $passed,
                    'pending_essay_count' => $score['pendingEssayCount'],
                    'topic_breakdown' => $score['topicBreakdown'],
                    'answers_snapshot' => $snapshot,
                    'anti_cheat_events' => $events,
                    'submitted_at' => $s->submitted_at ?? now(),
                ]);
                ExamSession::where('id', $s->id)->update([
                    'status' => 'submitted',
                    'submitted_at' => $s->submitted_at ?? now(),
                    'last_saved_at' => $s->last_saved_at ?? now(),
                ]);
                $created++;
            } catch (QueryException $e) {
                $errors++;
                $errorDetails[] = ['username' => $usr->username ?? '?', 'error' => substr($e->getMessage(), 0, 160)];
            }
        }

        Audit::log($request, 'exam.finalize_drafts', 'exam', $exam->id,
            "Recovered {$created} lost submission(s) for {$exam->name}",
            ['recovered' => $created, 'candidates' => $sessions->count()]);

        return response()->json([
            'examCode' => $exam->exam_code,
            'candidates' => $sessions->count(),
            'recovered' => $created,
            'skippedEmpty' => $skippedEmpty,
            'errors' => $errors,
            'errorDetails' => array_slice($errorDetails, 0, 10),
        ]);
    }

    // POST /api/teacher/exams/{examId}/reset-session   { userId }
    public function resetSession(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $this->owns($user, $exam)) {
            return response()->json(['error' => "You don't own this exam."], 403);
        }
        $targetId = (string) $request->input('userId', '');
        if ($targetId === '') {
            return response()->json(['error' => 'userId is required.'], 400);
        }
        // If they already have a real submission, refuse — this is only for
        // clearing stuck "did not attempt" sessions.
        if (ExamSubmission::where('exam_id', $exam->id)->where('user_id', $targetId)->exists()) {
            return response()->json(['error' => 'That student already has a submission; reset is only for stuck sessions.'], 409);
        }
        $sessionIds = ExamSession::where('exam_id', $exam->id)->where('user_id', $targetId)->pluck('id');
        AnswerDraft::whereIn('session_id', $sessionIds)->delete();
        ExamSession::whereIn('id', $sessionIds)->delete();
        Audit::log($request, 'session.reset', 'exam', $exam->id,
            "Reset a stuck session on {$exam->name}", ['userId' => $targetId, 'cleared' => $sessionIds->count()]);
        return response()->json(['ok' => true, 'cleared' => $sessionIds->count()]);
    }

    // POST /api/teacher/grade-bulk   { scores: [{submissionId, questionId, score}] }
    public function gradeBulk(Request $request)
    {
        $user = $request->attributes->get('authUser');
        $scores = $request->input('scores');
        if (! is_array($scores)) {
            return response()->json(['error' => '`scores` must be an array of { submissionId, questionId, score }.'], 400);
        }

        $applied = 0;
        $skipped = 0;
        $errors = [];

        foreach ($scores as $raw) {
            $submissionId = is_array($raw) && is_string($raw['submissionId'] ?? null) ? $raw['submissionId'] : null;
            $questionId = is_array($raw) && is_string($raw['questionId'] ?? null) ? $raw['questionId'] : null;
            $score = is_array($raw) && is_numeric($raw['score'] ?? null) ? (float) $raw['score'] : null;
            if (! $submissionId || ! $questionId) {
                $errors[] = ['reason' => 'Each row needs submissionId + questionId.'];
                $skipped++;
                continue;
            }
            if ($score === null || $score < 0) {
                $errors[] = ['submissionId' => $submissionId, 'questionId' => $questionId, 'reason' => 'score must be a non-negative number.'];
                $skipped++;
                continue;
            }
            $sub = ExamSubmission::find($submissionId);
            if (! $sub) {
                $errors[] = ['submissionId' => $submissionId, 'reason' => 'Submission not found.'];
                $skipped++;
                continue;
            }
            $exam = Exam::find($sub->exam_id);
            if (! $this->owns($user, $exam)) {
                $errors[] = ['submissionId' => $submissionId, 'reason' => 'Not owner of this exam.'];
                $skipped++;
                continue;
            }
            $q = ExamQuestion::where('id', $questionId)->where('exam_id', $exam->id)->where('type', 'essay')->first(['id', 'points']);
            if (! $q) {
                $errors[] = ['submissionId' => $submissionId, 'questionId' => $questionId, 'reason' => 'Question not found, wrong exam, or not an essay.'];
                $skipped++;
                continue;
            }
            $clamped = max(0, min((float) $q->points, $score));
            $manual = is_array($sub->manual_scores) ? $sub->manual_scores : [];
            $manual[$questionId] = $clamped;

            $questions = ExamQuestion::where('exam_id', $exam->id)->orderBy('position')->get(['id', 'topic', 'points', 'type', 'correct_answer']);
            $snap = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];
            $scoring = Scoring::score(
                $questions->map(fn ($x) => ['id' => $x->id, 'topic' => $x->topic, 'points' => $x->points, 'type' => $x->type])->all(),
                $questions->mapWithKeys(fn ($x) => [$x->id => $x->correct_answer])->all(),
                $snap,
                $manual
            );
            $passed = $scoring['pendingEssayCount'] === 0 && $scoring['percentScore'] >= $sub->passing_grade;
            $sub->forceFill([
                'manual_scores' => $manual,
                'final_score' => $scoring['finalScore'],
                'possible_score' => $scoring['possibleScore'],
                'percent_score' => $scoring['percentScore'],
                'pending_essay_count' => $scoring['pendingEssayCount'],
                'topic_breakdown' => $scoring['topicBreakdown'],
                'passed' => $passed,
                'graded_at' => $scoring['pendingEssayCount'] === 0 ? now() : null,
            ])->save();
            $applied++;
        }

        Audit::log($request, 'grade.bulk', null, null, "Applied {$applied} AI essay score(s)", ['applied' => $applied, 'skipped' => $skipped]);
        return response()->json(['applied' => $applied, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 50)]);
    }

    // GET /api/teacher/exams/{examId}/ai-export  → { markdown }
    public function aiExport(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $this->owns($user, $exam)) {
            return response()->json(['error' => "You don't own this exam."], 403);
        }
        $essays = ExamQuestion::where('exam_id', $exam->id)->where('type', 'essay')->orderBy('position')
            ->get(['id', 'position', 'prompt', 'points', 'explanation_text']);
        if ($essays->isEmpty()) {
            return response()->json(['error' => 'This exam has no essay questions.'], 400);
        }
        $essayIds = $essays->pluck('id');
        $subs = ExamSubmission::where('exam_id', $exam->id)->where('pending_essay_count', '>', 0)
            ->get(['id', 'full_name', 'username', 'answers_snapshot', 'manual_scores']);

        $lines = [];
        $lines[] = "# Essay grading — {$exam->name} ({$exam->exam_code})";
        $lines[] = '';
        $lines[] = 'You are grading essay answers. For each student answer below, decide a score between 0 and the stated maximum. **Return ONLY a JSON array** of objects: `{"submissionId": "...", "questionId": "...", "score": <number>}`.';
        $lines[] = '';
        foreach ($essays as $q) {
            $lines[] = "## Q{$q->position} (max {$q->points} pts) — questionId: `{$q->id}`";
            $lines[] = '';
            $lines[] = '**Prompt:** '.$q->prompt;
            if ($q->explanation_text) {
                $lines[] = '';
                $lines[] = '**Mark scheme / model answer:** '.$q->explanation_text;
            }
            $lines[] = '';
            $answered = 0;
            foreach ($subs as $sub) {
                $snap = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];
                $manual = is_array($sub->manual_scores) ? $sub->manual_scores : [];
                if (array_key_exists($q->id, $manual)) {
                    continue; // already graded
                }
                $ans = $snap[$q->id] ?? null;
                $ansText = is_array($ans) ? implode(', ', $ans) : (string) ($ans ?? '');
                if (trim($ansText) === '') {
                    continue;
                }
                $answered++;
                $lines[] = "- submissionId `{$sub->id}` — **{$sub->full_name}** ({$sub->username}):";
                $lines[] = '  > '.str_replace("\n", "\n  > ", $ansText);
            }
            if ($answered === 0) {
                $lines[] = '_No ungraded answers for this question._';
            }
            $lines[] = '';
        }

        return response()->json([
            'examCode' => $exam->exam_code,
            'pendingSubmissions' => $subs->count(),
            'markdown' => implode("\n", $lines),
        ]);
    }

    // POST /api/teacher/submissions/{submissionId}/delete
    public function deleteSubmission(Request $request, string $submissionId)
    {
        $user = $request->attributes->get('authUser');
        $sub = ExamSubmission::find($submissionId);
        if (! $sub) {
            return response()->json(['error' => 'Submission not found.'], 404);
        }
        if (! $this->owns($user, Exam::find($sub->exam_id))) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        Audit::log($request, 'submission.delete', 'submission', $sub->id, "Deleted submission for {$sub->full_name} ({$sub->exam_name})");
        $sub->delete();
        return response()->json(['ok' => true]);
    }

    // POST /api/teacher/submissions/bulk-delete   { ids: [...] }
    public function bulkDelete(Request $request)
    {
        $user = $request->attributes->get('authUser');
        $ids = $request->input('ids', []);
        if (! is_array($ids) || count($ids) === 0) {
            return response()->json(['error' => 'ids must be a non-empty array.'], 400);
        }
        if (count($ids) > 5000) {
            return response()->json(['error' => 'Too many ids (max 5000).'], 400);
        }
        $subs = ExamSubmission::whereIn('id', $ids)->get(['id', 'exam_id']);
        // Only delete submissions on exams the actor owns.
        $ownedExamIds = Exam::when($user->role !== 'admin', fn ($q) => $q->where('created_by', $user->id))
            ->pluck('id')->flip();
        $deletable = $subs->filter(fn ($s) => isset($ownedExamIds[$s->exam_id]))->pluck('id');
        $deleted = ExamSubmission::whereIn('id', $deletable)->delete();
        Audit::log($request, 'submission.bulk_delete', null, null, "Bulk-deleted {$deleted} submission(s)", ['deleted' => $deleted]);
        return response()->json([
            'deleted' => $deleted,
            'skipped' => count($ids) - $deleted,
        ]);
    }

    // GET /{role}/pending-score  — focused page of submissions awaiting essay grading
    public function pending(Request $request)
    {
        $u = $request->attributes->get('authUser');
        $isTeacher = $u->role === 'teacher';
        $exams = Exam::when($isTeacher, fn ($q) => $q->where('created_by', $u->id))->orderBy('name')->get(['id', 'exam_code', 'name']);
        $ids = $exams->pluck('id');

        $byExam = ExamSubmission::whereIn('exam_id', $ids)->where('pending_essay_count', '>', 0)
            ->orderByDesc('submitted_at')
            ->get(['id', 'exam_id', 'full_name', 'username', 'pending_essay_count', 'percent_score', 'submitted_at'])
            ->groupBy('exam_id');

        $groups = $exams->map(fn ($e) => [
            'examId' => $e->exam_code,
            'name' => $e->name,
            'submissions' => ($byExam[$e->id] ?? collect())->map(fn ($s) => [
                'id' => $s->id,
                'studentName' => $s->full_name,
                'username' => $s->username,
                'pendingEssayCount' => $s->pending_essay_count,
                'percentScore' => $s->percent_score,
                'submittedAt' => optional($s->submitted_at)->toIso8601String(),
            ])->values(),
        ])->filter(fn ($g) => count($g['submissions']) > 0)->values();

        return Inertia::render('Teacher/PendingScore', [
            'groups' => $groups,
            'scoresBasePath' => '/'.$u->role.'/scores',
        ]);
    }
}
