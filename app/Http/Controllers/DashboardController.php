<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $authUser = $request->attributes->get('authUser');

        return Inertia::render('Dashboard', [
            'user' => [
                'fullName' => $authUser->full_name,
                'role' => $authUser->role,
            ],
            'stats' => [
                'teachers' => User::where('role', 'teacher')->count(),
                'students' => User::where('role', 'student')->count(),
                'exams' => Exam::count(),
                'submissions' => ExamSubmission::count(),
            ],
        ]);
    }
}
