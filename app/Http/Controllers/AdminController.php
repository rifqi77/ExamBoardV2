<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'teachers' => User::where('role', 'teacher')->count(),
                'activeTeachers' => User::where('role', 'teacher')->where('active', true)->count(),
                'students' => User::where('role', 'student')->count(),
                'exams' => Exam::count(),
                'submissions' => ExamSubmission::count(),
                'pendingGrading' => ExamSubmission::where('pending_essay_count', '>', 0)->count(),
            ],
        ]);
    }
}
