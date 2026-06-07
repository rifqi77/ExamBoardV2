<?php

namespace App\Http\Controllers;

use App\Jobs\SuggestGradesJob;
use App\Models\AiJob;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Services\Audit;
use App\Services\GradingStats;
use App\Services\Scoring;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TeacherGradeController extends Controller
{
    private function owns($user, ?Exam $exam): bool
    {
        return $exam && ($user->role === 'admin' || $exam->created_by === $user->id);
    }

    // GET /{role}/scores/{submissionId}  — the grade page
    public function show(Request $request, string $submissionId)
    {
        $user = $request->attributes->get('authUser');
        $sub = ExamSubmission::find($submissionId);
        if (! $sub) {
            abort(404);
        }
        $exam = Exam::find($sub->exam_id);
        if (! $this->owns($user, $exam)) {
            return redirect('/'.$user->role.'/scores');
        }

        $snapshot = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];
        $manual = is_array($sub->manual_scores) ? $sub->manual_scores : [];

        $questions = ExamQuestion::where('exam_id', $sub->exam_id)->orderBy('position')->get()
            ->values()->map(fn ($q, $i) => [
                'id' => $q->id,
                'position' => $i + 1,
                'type' => $q->type,
                'topic' => $q->topic,
                'prompt' => $q->prompt,
                'points' => $q->points,
                'options' => $q->options,
                'correctAnswer' => $q->correct_answer,
                'rubric' => is_array($q->rubric) ? $q->rubric : null,
                'studentAnswer' => $snapshot[$q->id] ?? null,
                'manualScore' => array_key_exists($q->id, $manual) ? $manual[$q->id] : null,
            ]);

        return Inertia::render('Teacher/Grade', [
            'submission' => [
                'id' => $sub->id,
                'examId' => $exam->exam_code,
                'studentName' => $sub->full_name,
                'username' => $sub->username,
                'examName' => $sub->exam_name,
                'finalScore' => $sub->final_score,
                'possibleScore' => $sub->possible_score,
                'percentScore' => $sub->percent_score,
                'passed' => (bool) $sub->passed,
                'passingGrade' => $sub->passing_grade,
                'pendingEssayCount' => $sub->pending_essay_count,
                'antiCheatEvents' => is_array($sub->anti_cheat_events) ? $sub->anti_cheat_events : [],
                'gradingSuggestions' => is_array($sub->grading_suggestions) ? $sub->grading_suggestions : null,
            ],
            'questions' => $questions,
            'scoresBasePath' => '/'.$user->role.'/scores',
        ]);
    }

    // POST /api/teacher/submissions/{submissionId}/grade
    //   body: { questionId, score: number|null }
    public function grade(Request $request, string $submissionId)
    {
        $user = $request->attributes->get('authUser');
        $sub = ExamSubmission::find($submissionId);
        if (! $sub) {
            return response()->json(['error' => 'Submission not found.'], 404);
        }
        $exam = Exam::find($sub->exam_id);
        if (! $this->owns($user, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }

        $questionId = (string) $request->input('questionId', '');
        $scoreRaw = $request->input('score', null);
        if ($questionId === '') {
            return response()->json(['error' => 'questionId required.'], 400);
        }

        $questions = ExamQuestion::where('exam_id', $sub->exam_id)->orderBy('position')->get();
        $q = $questions->firstWhere('id', $questionId);
        if (! $q) {
            return response()->json(['error' => 'Question not in this exam.'], 404);
        }
        if ($q->type !== 'essay') {
            return response()->json(['error' => 'Only essay questions can be manually scored.'], 400);
        }

        $score = null;
        if ($scoreRaw !== null && $scoreRaw !== '') {
            if (! is_numeric($scoreRaw) || (float) $scoreRaw < 0) {
                return response()->json(['error' => 'Score must be 0 or greater.'], 400);
            }
            $score = (float) $scoreRaw;
            if ($score > (float) $q->points) {
                return response()->json(['error' => "Score cannot exceed the question max of {$q->points}."], 400);
            }
        }

        $manual = is_array($sub->manual_scores) ? $sub->manual_scores : [];
        if ($score === null) {
            unset($manual[$questionId]);
        } else {
            $manual[$questionId] = $score;
        }

        $snapshot = is_array($sub->answers_snapshot) ? $sub->answers_snapshot : [];
        $scoreResult = Scoring::score(
            $questions->map(fn ($x) => ['id' => $x->id, 'topic' => $x->topic, 'points' => $x->points, 'type' => $x->type])->all(),
            $questions->mapWithKeys(fn ($x) => [$x->id => $x->correct_answer])->all(),
            $snapshot,
            $manual
        );
        $passed = $scoreResult['pendingEssayCount'] === 0 && $scoreResult['percentScore'] >= $sub->passing_grade;

        $sub->forceFill([
            'manual_scores' => $manual,
            'final_score' => $scoreResult['finalScore'],
            'possible_score' => $scoreResult['possibleScore'],
            'percent_score' => $scoreResult['percentScore'],
            'pending_essay_count' => $scoreResult['pendingEssayCount'],
            'topic_breakdown' => $scoreResult['topicBreakdown'],
            'passed' => $passed,
            'graded_at' => $scoreResult['pendingEssayCount'] === 0 ? now() : null,
        ])->save();

        Audit::log($request, 'grade.set', 'submission', $sub->id,
            "Manually scored an essay for {$sub->full_name} ({$sub->exam_name})",
            ['questionId' => $questionId, 'score' => $score]);

        return response()->json([
            'ok' => true,
            'finalScore' => $scoreResult['finalScore'],
            'percentScore' => $scoreResult['percentScore'],
            'pendingEssayCount' => $scoreResult['pendingEssayCount'],
            'passed' => $passed,
        ]);
    }

    // POST /api/teacher/submissions/{submissionId}/suggest-grades  { runs? }
    // AI DRAFTS grades for the essays — never finalizes. Runs async (see
    // SuggestGradesJob); poll /teacher/ai-jobs/{id} for the result. Persisted so
    // the chi-square check can compare drafts to the teacher's final grades.
    public function suggest(Request $request, string $submissionId)
    {
        $user = $request->attributes->get('authUser');
        $sub = ExamSubmission::find($submissionId);
        if (! $sub) {
            return response()->json(['error' => 'Submission not found.'], 404);
        }
        if (! $this->owns($user, Exam::find($sub->exam_id))) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        $runs = max(1, min(10, (int) $request->input('runs', 3)));

        $job = AiJob::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'kind' => 'suggest_grades',
            'status' => 'queued',
            'params' => ['submissionId' => $sub->id, 'runs' => $runs],
        ]);
        Audit::log($request, 'grade.ai_suggest', 'submission', $sub->id, "Ran AI grading suggestions for {$sub->full_name}");
        SuggestGradesJob::dispatch($job->id);

        return response()->json(['jobId' => $job->id]);
    }

    // GET /api/teacher/exams/{examId}/grading-quality
    // Chi-square: are the AI drafts statistically indistinguishable from the
    // teacher's final grades? (target p > 0.05). Pairs stored suggestions
    // against finalized manual_scores across this exam's essays.
    public function quality(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = Exam::where('id', $examId)->orWhere('exam_code', $examId)->first();
        if (! $this->owns($user, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        $subs = ExamSubmission::where('exam_id', $exam->id)
            ->whereNotNull('grading_suggestions')->whereNotNull('manual_scores')
            ->get(['grading_suggestions', 'manual_scores']);
        $ai = [];
        $human = [];
        foreach ($subs as $s) {
            $sugg = is_array($s->grading_suggestions) ? $s->grading_suggestions : [];
            $manual = is_array($s->manual_scores) ? $s->manual_scores : [];
            foreach ($sugg as $qid => $row) {
                if (! array_key_exists($qid, $manual)) {
                    continue;
                }
                $aiScore = $row['suggested'] ?? ($row['ai']['mean'] ?? null);
                if ($aiScore === null) {
                    continue;
                }
                $ai[] = (float) $aiScore;
                $human[] = (float) $manual[$qid];
            }
        }
        if (count($ai) < 2) {
            return response()->json(['ok' => true, 'pairs' => count($ai), 'message' => 'Not enough graded + AI-suggested essays yet to validate (need ≥2).']);
        }
        return response()->json(['ok' => true] + GradingStats::chiSquare($ai, $human));
    }
}
