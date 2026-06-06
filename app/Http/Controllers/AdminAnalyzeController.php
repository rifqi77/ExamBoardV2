<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminAnalyzeController extends Controller
{
    public function index(Request $request)
    {
        $teachers = User::where('role', 'teacher')->get(['id', 'full_name', 'subject', 'active']);
        $teacherIds = $teachers->pluck('id');

        $system = [
            'teacherCount' => $teachers->count(),
            'activeTeacherCount' => $teachers->where('active', true)->count(),
            'studentCount' => User::where('role', 'student')->count(),
            'examCount' => Exam::count(),
            'submissionCount' => ExamSubmission::count(),
        ];

        $examOwner = Exam::whereIn('created_by', $teacherIds)->pluck('created_by', 'id');
        $examCounts = Exam::whereIn('created_by', $teacherIds)
            ->selectRaw('created_by, count(*) c')->groupBy('created_by')->pluck('c', 'created_by');
        $studentCounts = User::where('role', 'student')->whereIn('created_by', $teacherIds)
            ->selectRaw('created_by, count(*) c')->groupBy('created_by')->pluck('c', 'created_by');

        $byOwner = [];
        foreach (ExamSubmission::whereIn('exam_id', $examOwner->keys())->get(['exam_id', 'percent_score']) as $s) {
            $o = $examOwner[$s->exam_id] ?? null;
            if (! $o) {
                continue;
            }
            $byOwner[$o]['n'] = ($byOwner[$o]['n'] ?? 0) + 1;
            $byOwner[$o]['sum'] = ($byOwner[$o]['sum'] ?? 0) + $s->percent_score;
        }

        $perTeacher = $teachers->map(fn ($t) => [
            'name' => $t->full_name,
            'subject' => $t->subject,
            'active' => (bool) $t->active,
            'exams' => (int) ($examCounts[$t->id] ?? 0),
            'students' => (int) ($studentCounts[$t->id] ?? 0),
            'submissions' => (int) ($byOwner[$t->id]['n'] ?? 0),
            'avgPercent' => isset($byOwner[$t->id]) && $byOwner[$t->id]['n'] > 0
                ? round($byOwner[$t->id]['sum'] / $byOwner[$t->id]['n'], 1) : null,
        ])->sortByDesc('submissions')->values();

        return Inertia::render('Admin/Analyze', ['system' => $system, 'perTeacher' => $perTeacher]);
    }
}
