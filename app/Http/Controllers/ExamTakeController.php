<?php

namespace App\Http\Controllers;

use App\Models\AnswerDraft;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Services\ExamAccessJwt;
use App\Services\ExamDraw;
use App\Services\Scoring;
use App\Services\Shuffle;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Student exam-taking flow, ported from the Next.js routes:
 *   POST /api/exam-access/validate   token -> exam-access cookie
 *   GET  /api/exams/{examId}         load exam + create/resume session
 *   POST /api/exams/{examId}/draft   autosave (bulk upsert)
 *   POST /api/exams/{examId}/submit  score + store submission
 */
class ExamTakeController extends Controller
{
    /** Anti-cheat event kinds the /events endpoint accepts from clients. */
    private const KNOWN_EVENT_KINDS = [
        'tab_blur', 'tab_focus', 'fullscreen_exit', 'fullscreen_enter',
        'paste_blocked', 'copy_blocked', 'contextmenu_blocked', 'seb_missing',
    ];

    /** Server-emitted kinds (resume marker, auto-submit marker). */
    private const SERVER_EVENT_KINDS = ['session_resumed', 'auto_submitted_timeout'];

    private const MAX_EVENTS_PER_SESSION = 5000;
    private const MAX_EVENT_DETAIL = 200;

    /** Set by findOrCreateSession() when an existing draft was resumed. */
    private bool $resumed = false;

    // ---- POST /api/exam-access/validate -------------------------------
    public function validateToken(Request $request)
    {
        $user = $request->attributes->get('authUser');
        $token = (string) $request->input('token', '');
        if (strlen(trim($token)) < 3) {
            return response()->json(['error' => 'Enter a valid exam token.'], 400);
        }

        $digest = hash('sha256', strtoupper(trim($token)));
        $now = now();

        $tok = DB::table('exam_access_tokens as t')
            ->join('exams as e', 'e.id', '=', 't.exam_id')
            ->where('t.token_digest', $digest)
            ->where('t.active', 1)
            ->where(fn ($q) => $q->whereNull('t.expires_at')->orWhere('t.expires_at', '>=', $now))
            ->where('e.active', 1)
            ->where(fn ($q) => $q->whereNull('e.start_time')->orWhere('e.start_time', '<=', $now))
            ->where(fn ($q) => $q->whereNull('e.end_time')->orWhere('e.end_time', '>=', $now))
            ->select('t.id as token_id', 't.used_count', 't.max_uses', 'e.id as exam_id', 'e.exam_code', 'e.name', 'e.exam_mode')
            ->first();

        if (! $tok || $tok->used_count >= $tok->max_uses) {
            return response()->json(['error' => 'Invalid or expired exam token.'], 403);
        }

        if ($tok->exam_mode === 'strict') {
            $existing = ExamSubmission::where('user_id', $user->id)->where('exam_id', $tok->exam_id)->first(['id']);
            if ($existing) {
                return response()->json([
                    'error' => 'You have already submitted this exam. Only one attempt is allowed.',
                    'alreadySubmitted' => true,
                    'submissionId' => $existing->id,
                ], 403);
            }
        }

        // Record redemption once per (token,user); bump used_count only on
        // first redemption.
        $inserted = DB::table('exam_token_redemptions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'token_id' => $tok->token_id,
            'user_id' => $user->id,
            'redeemed_at' => $now,
        ]);
        if ($inserted) {
            DB::table('exam_access_tokens')->where('id', $tok->token_id)->increment('used_count');
        }

        $jwt = ExamAccessJwt::sign($user->id, $tok->exam_id, $tok->token_id);

        return response()->json([
            'examId' => $tok->exam_code,
            'examDatabaseId' => $tok->exam_id,
            'examName' => $tok->name,
        ])->cookie(ExamAccessJwt::COOKIE, $jwt, ExamAccessJwt::ttlMinutes(), '/', null, (bool) config('session.secure'), true, false, 'lax');
    }

    // ---- GET /api/exams/{examId} -------------------------------------
    public function show(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }
        if (! $this->examAccessOk($request, $user, $exam->id)) {
            return response()->json(['error' => 'No exam access. Enter the token again.'], 403);
        }

        $isAdmin = $user->role === 'admin';
        if (! $isAdmin) {
            if (! $exam->active) {
                return response()->json(['error' => 'This exam is not active.'], 403);
            }
            if ($exam->start_time && $exam->start_time->getTimestamp() > time()) {
                return response()->json(['error' => 'This exam has not started yet.'], 403);
            }
            if ($exam->end_time && $exam->end_time->getTimestamp() < time()) {
                return response()->json(['error' => 'This exam has ended.'], 403);
            }
            if (! $this->sebOk($request, $exam)) {
                return response()->json([
                    'error' => 'This exam must be taken in Safe Exam Browser.',
                    'sebRequired' => true,
                ], 403);
            }
        }

        // Strict 1-attempt: refuse a new session if already submitted.
        if ($exam->exam_mode === 'strict' && ! $isAdmin) {
            $existing = ExamSubmission::where('user_id', $user->id)->where('exam_id', $exam->id)
                ->orderByDesc('submitted_at')->first(['id']);
            if ($existing) {
                return response()->json([
                    'error' => 'You have already submitted this strict-mode exam.',
                    'alreadySubmitted' => true,
                    'submissionId' => $existing->id,
                ], 409);
            }
        }

        $session = $this->findOrCreateSession($user->id, $exam);

        $durationSeconds = self::durationSecondsFor($exam, $user);
        $elapsed = time() - $session->started_at->getTimestamp();
        $remaining = max(0, $durationSeconds - $elapsed);

        // Auto-submit on expiry (server-side fallback).
        if (! $isAdmin && $elapsed > $durationSeconds) {
            $res = $this->gradeAndStore($user, $exam, $session, [], false, true);
            return response()->json([
                'error' => 'Your time is up. Your answers have been submitted automatically.',
                'autoSubmitted' => true,
                'submissionId' => $res['submissionId'],
            ], 409);
        }

        // Per-session seeded shuffle (essays pinned to the end), mirroring
        // the Next app. Seed = session id → stable across refreshes, fresh
        // per attempt. Scoring is by id, not position, so this is cosmetic.
        $rawQuestions = ExamDraw::filter(
            ExamQuestion::where('exam_id', $exam->id)->orderBy('position')->get(),
            $session->drawn_question_ids
        );
        $sessionId = $session->id;
        $nonEssay = $rawQuestions->filter(fn ($q) => $q->type !== 'essay')->values();
        $essay = $rawQuestions->filter(fn ($q) => $q->type === 'essay')->values();
        $orderedNonEssay = $exam->shuffle_questions
            ? collect(Shuffle::shuffleWithSeed($nonEssay->all(), $sessionId.'::q'))
            : $nonEssay;
        $ordered = $orderedNonEssay->concat($essay)->values();

        // Media per question (exam_media relation, ordered by sort_order).
        $mediaByQ = DB::table('exam_media')->whereIn('question_id', $ordered->pluck('id')->all())
            ->orderBy('sort_order')->get()->groupBy('question_id');

        // Server-side resume marker so the teacher monitor sees refresh /
        // return-to-tab activity even if the client never flushed (strict only).
        if (! $isAdmin && $this->resumed && $exam->exam_mode === 'strict') {
            $this->appendEvents($session, [[
                'kind' => 'session_resumed',
                'at' => now()->toIso8601String(),
            ]]);
        }

        $drafts = AnswerDraft::where('session_id', $session->id)->get(['question_id', 'value']);

        return response()->json([
            'metadata' => [
                'id' => $exam->id,
                'examId' => $exam->exam_code,
                'name' => $exam->name,
                'durationMinutes' => $exam->duration_minutes,
                'passingGrade' => $exam->passing_grade,
                'generalInstructions' => $exam->general_instructions ?? '',
                'examMode' => $exam->exam_mode,
                'sebRequired' => (bool) $exam->seb_required,
                'mediaBaseUrl' => $exam->media_base_url,
            ],
            'session' => [
                'id' => $session->id,
                'startedAt' => $session->started_at->toIso8601String(),
                'lastSavedAt' => optional($session->last_saved_at)->toIso8601String(),
                'timeRemainingSeconds' => $remaining,
            ],
            'questions' => $ordered->map(function ($q, $i) use ($exam, $sessionId, $mediaByQ) {
                $opts = $q->options;
                if ($exam->shuffle_options
                    && in_array($q->type, ['single_choice', 'multi_select'], true)
                    && is_array($opts) && count($opts) > 0) {
                    $opts = Shuffle::shuffleWithSeed($opts, $sessionId.'::o::'.$q->id);
                }
                $media = ($mediaByQ[$q->id] ?? collect())->map(fn ($m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'url' => $m->url,
                    'altText' => $m->alt_text,
                    'caption' => $m->caption,
                ])->values();
                return [
                    'id' => $q->id,
                    'position' => $i + 1,
                    'type' => $q->type,
                    'topic' => $q->topic,
                    'prompt' => $q->prompt,
                    'options' => $opts,
                    'points' => $q->points,
                    'media' => $media,
                ];
            }),
            'draftAnswers' => $drafts->mapWithKeys(fn ($d) => [$d->question_id => $d->value]),
        ]);
    }

    // ---- POST /api/exams/{examId}/events -----------------------------
    // Append-only anti-cheat event sink. The client buffers strict-mode
    // signals and flushes here every ~10s + on beforeunload (sendBeacon)
    // + on submit. Events land on exam_sessions.anti_cheat_events (the
    // source of truth until submit copies them onto the submission).
    public function events(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $exam || ! $this->examAccessOk($request, $user, $exam->id)) {
            return response()->json(['error' => 'No exam access.'], 403);
        }
        $events = $request->input('events', []);
        if (! is_array($events)) {
            return response()->json(['error' => 'events must be an array.'], 400);
        }
        if (count($events) === 0) {
            return response()->json(['saved' => 0, 'total' => 0]);
        }
        if (count($events) > 500) {
            return response()->json(['error' => 'Too many events in one batch (max 500).'], 400);
        }
        $sanitised = $this->sanitiseEvents($events);
        if (! $sanitised) {
            return response()->json(['saved' => 0, 'total' => 0]);
        }

        $session = ExamSession::where('user_id', $user->id)->where('exam_id', $exam->id)
            ->where('status', 'draft')->orderByDesc('created_at')->first();
        if (! $session) {
            return response()->json(['error' => 'No active exam session found.'], 404);
        }

        $res = $this->appendEvents($session, $sanitised);
        return response()->json(['saved' => $res['added'], 'total' => $res['total']]);
    }

    // ---- POST /api/exams/{examId}/draft (autosave) -------------------
    public function draft(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $exam || ! $this->examAccessOk($request, $user, $exam->id)) {
            return response()->json(['error' => 'No exam access.'], 403);
        }
        $session = ExamSession::where('user_id', $user->id)->where('exam_id', $exam->id)
            ->where('status', 'draft')->orderByDesc('created_at')->first();
        if (! $session) {
            return response()->json(['error' => 'No active session.'], 409);
        }
        $answers = $request->input('answers', []);
        if (! is_array($answers) || count($answers) === 0) {
            return response()->json(['saved' => 0]);
        }
        $this->upsertDrafts($session->id, $exam->id, $answers);
        ExamSession::where('id', $session->id)->update(['last_saved_at' => now()]);
        return response()->json(['saved' => count($answers)]);
    }

    // ---- POST /api/exams/{examId}/submit ----------------------------
    public function submit(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found.'], 404);
        }

        $session = ExamSession::where('user_id', $user->id)
            ->whereIn('status', ['draft', 'expired'])
            ->where('exam_id', $exam->id)
            ->orderByDesc('created_at')->first();

        if (! $session) {
            $existing = ExamSubmission::where('user_id', $user->id)->where('exam_id', $exam->id)
                ->orderByDesc('submitted_at')->first(['id']);
            if ($existing) {
                return response()->json(['submissionId' => $existing->id, 'alreadySubmitted' => true]);
            }
            return response()->json(['error' => 'No active exam session found.'], 404);
        }

        if (! $this->examAccessOk($request, $user, $exam->id)) {
            return response()->json(['error' => 'No exam access.'], 403);
        }

        if ($exam->exam_mode === 'strict' && $user->role !== 'admin') {
            $prior = ExamSubmission::where('user_id', $user->id)->where('exam_id', $exam->id)
                ->orderByDesc('submitted_at')->first(['id']);
            if ($prior) {
                return response()->json(['submissionId' => $prior->id, 'alreadySubmitted' => true]);
            }
        }

        $elapsed = time() - $session->started_at->getTimestamp();
        $isLate = $elapsed > self::durationSecondsFor($exam, $user) + 60;
        $answers = $isLate ? [] : (array) $request->input('answers', []);

        // Fold the client's final unflushed anti-cheat events into the
        // session before snapshotting (dedup-merged, row-locked).
        $tail = $request->input('antiCheatEvents', []);
        if (is_array($tail) && $tail) {
            $this->appendEvents($session, $this->sanitiseEvents($tail));
        }

        $res = $this->gradeAndStore($user, $exam, $session, $answers, ! $isLate, $isLate);
        return response()->json($res);
    }

    // ---- helpers ----------------------------------------------------
    private function resolveExam(string $idOrCode): ?Exam
    {
        return Exam::where('id', $idOrCode)->orWhere('exam_code', $idOrCode)->first();
    }

    private function examAccessOk(Request $request, $user, string $examDbId): bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        $claims = ExamAccessJwt::verify($request->cookie(ExamAccessJwt::COOKIE));
        return $claims && $claims['userId'] === $user->id && $claims['examId'] === $examDbId;
    }

    /**
     * Safe Exam Browser gate. When an exam is seb_required, the request
     * must carry SEB's per-request hash: SHA256(requestURL + storedKey),
     * sent as X-SafeExamBrowser-RequestHash (Browser Exam Key) or
     * -ConfigKeyHash (Config Key). Either may match the stored key.
     */
    private function sebOk(Request $request, Exam $exam): bool
    {
        if (! $exam->seb_required) {
            return true;
        }
        $secret = (string) ($exam->seb_secret ?? '');
        if ($secret === '') {
            return false; // required but no key configured — fail closed
        }
        $expected = hash('sha256', $request->fullUrl().$secret);
        $bek = (string) $request->header('X-SafeExamBrowser-RequestHash', '');
        $ck = (string) $request->header('X-SafeExamBrowser-ConfigKeyHash', '');
        return ($bek !== '' && hash_equals($expected, $bek))
            || ($ck !== '' && hash_equals($expected, $ck));
    }

    private function findOrCreateSession(string $userId, Exam $exam): ExamSession
    {
        $session = ExamSession::where('user_id', $userId)->where('exam_id', $exam->id)
            ->where('status', 'draft')->orderByDesc('created_at')->first();
        if ($session) {
            $this->resumed = true;
            return $session;
        }
        $prevAttempt = (int) (ExamSession::where('user_id', $userId)->where('exam_id', $exam->id)
            ->max('attempt') ?? 0);

        // Per-student question draw: snapshot the drawn subset at creation so
        // it's stable for this attempt (seed = the new session id).
        $id = (string) Str::uuid();
        $drawn = null;
        if ($exam->draw_count) {
            $allIds = ExamQuestion::where('exam_id', $exam->id)->orderBy('position')->pluck('id')->all();
            $drawn = ExamDraw::pick($allIds, (int) $exam->draw_count, $id);
        }

        try {
            return ExamSession::create([
                'id' => $id,
                'user_id' => $userId,
                'exam_id' => $exam->id,
                'attempt' => $prevAttempt + 1,
                'status' => 'draft',
                'started_at' => now(),
                'time_used_seconds' => 0,
                'drawn_question_ids' => $drawn,
            ]);
        } catch (QueryException $e) {
            $winner = ExamSession::where('user_id', $userId)->where('exam_id', $exam->id)
                ->where('status', 'draft')->orderByDesc('created_at')->first();
            if ($winner) {
                return $winner;
            }
            throw $e;
        }
    }

    private function upsertDrafts(string $sessionId, string $examId, array $answers): void
    {
        $validIds = ExamQuestion::where('exam_id', $examId)
            ->whereIn('id', array_keys($answers))->pluck('id')->flip();
        $now = now();
        $rows = [];
        foreach ($answers as $qid => $val) {
            if (! isset($validIds[$qid])) {
                continue;
            }
            $rows[] = [
                'id' => (string) Str::uuid(),
                'session_id' => $sessionId,
                'question_id' => (string) $qid,
                'value' => json_encode($val),
                'updated_at' => $now,
            ];
        }
        if ($rows) {
            AnswerDraft::upsert($rows, ['session_id', 'question_id'], ['value', 'updated_at']);
        }
    }

    /** Validate + normalise a batch of incoming anti-cheat events. */
    private function sanitiseEvents(array $events, bool $allowServerKinds = false): array
    {
        $allowed = $allowServerKinds
            ? array_merge(self::KNOWN_EVENT_KINDS, self::SERVER_EVENT_KINDS)
            : self::KNOWN_EVENT_KINDS;
        $out = [];
        foreach ($events as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $kind = $raw['kind'] ?? null;
            if (! is_string($kind) || ! in_array($kind, $allowed, true)) {
                continue;
            }
            $at = (isset($raw['at']) && is_string($raw['at']) && strtotime($raw['at']) !== false)
                ? $raw['at']
                : now()->toIso8601String();
            $ev = ['kind' => $kind, 'at' => $at];
            if (isset($raw['detail']) && is_string($raw['detail'])) {
                $ev['detail'] = substr($raw['detail'], 0, self::MAX_EVENT_DETAIL);
            }
            $out[] = $ev;
        }
        return $out;
    }

    private function eventKey(array $e): string
    {
        return ($e['kind'] ?? '').'|'.($e['at'] ?? '').'|'.($e['detail'] ?? '');
    }

    /**
     * Row-locked, deduped append of events onto a session's
     * anti_cheat_events JSON column. Concurrent flushes (periodic vs.
     * sendBeacon vs. submit, or two tabs) serialise on the row instead of
     * racing on a stale snapshot. Returns ['added'=>n,'total'=>m].
     */
    /** Exam duration in seconds, including the student's extra-time accommodation. */
    public static function durationSecondsFor(Exam $exam, $user): int
    {
        $base = (int) $exam->duration_minutes * 60;
        $pct = max(0, (int) ($user->extra_time_percent ?? 0));

        return (int) round($base * (1 + $pct / 100));
    }

    private function appendEvents(ExamSession $session, array $sanitised): array
    {
        if (! $sanitised) {
            $existing = is_array($session->anti_cheat_events) ? $session->anti_cheat_events : [];
            return ['added' => 0, 'total' => count($existing)];
        }
        return DB::transaction(function () use ($session, $sanitised) {
            $locked = ExamSession::where('id', $session->id)->lockForUpdate()->first(['id', 'anti_cheat_events']);
            $existing = is_array($locked->anti_cheat_events) ? $locked->anti_cheat_events : [];
            $seen = [];
            foreach ($existing as $e) {
                $seen[$this->eventKey($e)] = true;
            }
            $fresh = [];
            foreach ($sanitised as $e) {
                $k = $this->eventKey($e);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $fresh[] = $e;
            }
            if (! $fresh) {
                return ['added' => 0, 'total' => count($existing)];
            }
            $room = self::MAX_EVENTS_PER_SESSION - count($existing);
            if ($room <= 0) {
                return ['added' => 0, 'total' => count($existing)];
            }
            $toAdd = array_slice($fresh, 0, $room);
            $next = array_merge($existing, $toAdd);
            ExamSession::where('id', $session->id)->update(['anti_cheat_events' => $next]);
            return ['added' => count($toAdd), 'total' => count($next)];
        });
    }

    /**
     * Persist (optional) last answers, score everything, store the
     * submission, mark the session submitted. Returns the result payload.
     */
    private function gradeAndStore($user, Exam $exam, ExamSession $session, array $answers, bool $acceptAnswers, bool $autoTimeout = false): array
    {
        if ($acceptAnswers && count($answers) > 0) {
            $this->upsertDrafts($session->id, $exam->id, $answers);
        }

        if ($autoTimeout) {
            $this->appendEvents($session, [[
                'kind' => 'auto_submitted_timeout',
                'at' => now()->toIso8601String(),
            ]]);
        }
        // Consolidated anti-cheat events (client flushes + server markers)
        // ride along onto the submission so grading/audit can read them.
        $session->refresh();
        $antiCheat = is_array($session->anti_cheat_events) ? $session->anti_cheat_events : [];

        $questions = ExamDraw::filter(
            ExamQuestion::where('exam_id', $exam->id)->orderBy('position')
                ->get(['id', 'topic', 'points', 'correct_answer', 'type']),
            $session->drawn_question_ids
        );
        $drafts = AnswerDraft::where('session_id', $session->id)->get(['question_id', 'value']);

        $snapshot = [];
        foreach ($drafts as $d) {
            $snapshot[$d->question_id] = $d->value;
        }

        $score = Scoring::score(
            $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all(),
            $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
            $snapshot
        );
        $passed = $score['percentScore'] >= $exam->passing_grade;

        try {
            $sub = ExamSubmission::create([
                'id' => (string) Str::uuid(),
                'exam_id' => $exam->id,
                'user_id' => $user->id,
                'session_id' => $session->id,
                'attempt' => $session->attempt,
                'username' => $user->username,
                'full_name' => $user->full_name,
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
                'anti_cheat_events' => $antiCheat,
                'submitted_at' => now(),
            ]);
            $submissionId = $sub->id;
        } catch (QueryException $e) {
            // Unique (user, exam, attempt) — another submit won the race.
            $existing = ExamSubmission::where('user_id', $user->id)->where('exam_id', $exam->id)
                ->where('attempt', $session->attempt)->orderByDesc('submitted_at')->first(['id']);
            if (! $existing) {
                throw $e;
            }
            $submissionId = $existing->id;
        }

        ExamSession::where('id', $session->id)->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'last_saved_at' => now(),
        ]);

        return [
            'submissionId' => $submissionId,
            'percentScore' => $score['percentScore'],
            'passed' => $passed,
        ];
    }
}
