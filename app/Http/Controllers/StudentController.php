<?php

namespace App\Http\Controllers;

use App\Models\ExamSubmission;
use App\Services\AnswerReview;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->attributes->get('authUser');

        $submissions = ExamSubmission::where('user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->get(['id', 'exam_name', 'percent_score', 'passed', 'pending_essay_count', 'submitted_at'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'examName' => $s->exam_name,
                'percentScore' => $s->percent_score,
                'passed' => (bool) $s->passed,
                'pendingEssayCount' => $s->pending_essay_count,
                'submittedAt' => optional($s->submitted_at)->toIso8601String(),
            ]);

        return Inertia::render('Student/Hub', [
            'submissions' => $submissions,
        ]);
    }

    public function result(Request $request, string $submissionId)
    {
        $user = $request->attributes->get('authUser');
        $sub = ExamSubmission::where('id', $submissionId)->first();
        if (! $sub || ($user->role !== 'admin' && $sub->user_id !== $user->id)) {
            abort(404);
        }
        return Inertia::render('Exam/Result', [
            'submission' => [
                'examName' => $sub->exam_name,
                'finalScore' => $sub->final_score,
                'possibleScore' => $sub->possible_score,
                'percentScore' => $sub->percent_score,
                'passed' => (bool) $sub->passed,
                'passingGrade' => $sub->passing_grade,
                'pendingEssayCount' => $sub->pending_essay_count,
                'topicBreakdown' => $sub->topic_breakdown,
                'submittedAt' => optional($sub->submitted_at)->toIso8601String(),
            ],
            'review' => AnswerReview::forSubmission($sub),
        ]);
    }
}
