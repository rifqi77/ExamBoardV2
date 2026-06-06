<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ScoresController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->attributes->get('authUser');
        $isTeacher = $u->role === 'teacher';

        $exams = Exam::when($isTeacher, fn ($q) => $q->where('created_by', $u->id))
            ->orderBy('name')->get(['id', 'exam_code', 'name']);
        $ids = $exams->pluck('id');

        $byExam = ExamSubmission::whereIn('exam_id', $ids)
            ->orderByDesc('submitted_at')
            ->get(['id', 'exam_id', 'full_name', 'username', 'final_score', 'possible_score', 'percent_score', 'passed', 'pending_essay_count', 'submitted_at'])
            ->groupBy('exam_id');

        $groups = $exams->map(fn ($e) => [
            'examId' => $e->exam_code,
            'name' => $e->name,
            'submissions' => ($byExam[$e->id] ?? collect())->map(fn ($s) => [
                'id' => $s->id,
                'studentName' => $s->full_name,
                'username' => $s->username,
                'finalScore' => $s->final_score,
                'possibleScore' => $s->possible_score,
                'percentScore' => $s->percent_score,
                'passed' => (bool) $s->passed,
                'pendingEssayCount' => $s->pending_essay_count,
                'submittedAt' => optional($s->submitted_at)->toIso8601String(),
            ])->values(),
        ])->filter(fn ($g) => count($g['submissions']) > 0)->values();

        return Inertia::render('Teacher/Scores', [
            'groups' => $groups,
            'scoresBasePath' => '/'.$u->role.'/scores',
        ]);
    }
}
