<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeacherController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->attributes->get('authUser');
        // Admin viewing /teacher sees everything; a teacher sees their own.
        $isTeacher = $user->role === 'teacher';
        $examIds = Exam::when($isTeacher, fn ($q) => $q->where('created_by', $user->id))
            ->pluck('id');

        return Inertia::render('Teacher/Dashboard', [
            'stats' => [
                'exams' => $examIds->count(),
                'students' => User::where('role', 'student')
                    ->when($isTeacher, fn ($q) => $q->where('created_by', $user->id))
                    ->count(),
                'submissions' => ExamSubmission::whereIn('exam_id', $examIds)->count(),
                'pendingGrading' => ExamSubmission::whereIn('exam_id', $examIds)
                    ->where('pending_essay_count', '>', 0)
                    ->count(),
            ],
        ]);
    }
}
