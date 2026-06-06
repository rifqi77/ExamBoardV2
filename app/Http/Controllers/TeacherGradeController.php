<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Services\Audit;
use App\Services\Scoring;
use Illuminate\Http\Request;
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
                'studentAnswer' => $snapshot[$q->id] ?? null,
                'manualScore' => array_key_exists($q->id, $manual) ? $manual[$q->id] : null,
            ]);

        return Inertia::render('Teacher/Grade', [
            'submission' => [
                'id' => $sub->id,
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
}
