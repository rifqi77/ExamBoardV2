<?php

use App\Http\Controllers\AdminAnalyzeController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiGenerateController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\AdminTeachersController;
use App\Http\Controllers\AnswerAuditController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\ExamDetailController;
use App\Http\Controllers\ExamsController;
use App\Http\Controllers\LearningObjectivesController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ScoresController;
use App\Http\Controllers\ScoreToolsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentsController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TeacherGradeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect('/dashboard'));

// Login page (Inertia/React). Posts to /api/auth/login which sets the cookie.
Route::get('/login', fn () => Inertia::render('Login'));

// Role-based home: send each user to their own dashboard.
Route::get('/dashboard', function (Request $request) {
    $user = $request->attributes->get('authUser');
    return redirect('/'.$user->role);
})->middleware('jwt.auth');

Route::middleware('jwt.auth:admin')->get('/admin', [AdminController::class, 'index']);
Route::middleware('jwt.auth:teacher,admin')->get('/teacher', [TeacherController::class, 'index']);
Route::middleware('jwt.auth:student')->get('/student', [StudentController::class, 'index']);

// Management pages — shared controllers, scoped by the signed-in role
// (teacher = own data, admin = every teacher). Same component for both
// the /teacher/* and /admin/* paths.
Route::middleware('jwt.auth:teacher,admin')->group(function () {
    Route::get('/teacher/students', [StudentsController::class, 'index']);
    Route::get('/teacher/exams', [ExamsController::class, 'index']);
    Route::get('/teacher/bank', [BankController::class, 'page']);
    Route::get('/teacher/exams/new', fn () => Inertia::render('Teacher/ExamCreate', ['examsBasePath' => '/teacher/exams']));
    Route::get('/teacher/exams/{examId}', [ExamDetailController::class, 'show']);
    Route::get('/teacher/exams/{examId}/live', fn (string $examId) => Inertia::render('Teacher/LiveMonitor', ['examId' => $examId, 'examsBasePath' => '/teacher/exams']));
    Route::get('/teacher/exams/{examId}/audit', [AnswerAuditController::class, 'show']);
    Route::get('/teacher/exams/{examId}/analysis', [\App\Http\Controllers\ItemAnalysisController::class, 'show']);
    Route::get('/teacher/scores', [ScoresController::class, 'index']);
    Route::get('/teacher/pending-score', [ScoreToolsController::class, 'pending']);
    Route::get('/teacher/scores/{submissionId}', [TeacherGradeController::class, 'show']);
    Route::get('/teacher/reports', [ReportsController::class, 'index']);
    Route::get('/teacher/ai-generate', [AiGenerateController::class, 'page']);
    Route::get('/teacher/learning-objectives', [LearningObjectivesController::class, 'page']);
});
Route::middleware('jwt.auth:admin')->group(function () {
    Route::get('/admin/students', [StudentsController::class, 'index']);
    Route::get('/admin/exams', [ExamsController::class, 'index']);
    Route::get('/admin/bank', [BankController::class, 'page']);
    Route::get('/admin/exams/new', fn () => Inertia::render('Teacher/ExamCreate', ['examsBasePath' => '/admin/exams']));
    Route::get('/admin/exams/{examId}', [ExamDetailController::class, 'show']);
    Route::get('/admin/exams/{examId}/live', fn (string $examId) => Inertia::render('Teacher/LiveMonitor', ['examId' => $examId, 'examsBasePath' => '/admin/exams']));
    Route::get('/admin/exams/{examId}/audit', [AnswerAuditController::class, 'show']);
    Route::get('/admin/exams/{examId}/analysis', [\App\Http\Controllers\ItemAnalysisController::class, 'show']);
    Route::get('/admin/scores', [ScoresController::class, 'index']);
    Route::get('/admin/pending-score', [ScoreToolsController::class, 'pending']);
    Route::get('/admin/scores/{submissionId}', [TeacherGradeController::class, 'show']);
    Route::get('/admin/reports', [ReportsController::class, 'index']);
    Route::get('/admin/teachers', [AdminTeachersController::class, 'index']);
    Route::get('/admin/analyze', [AdminAnalyzeController::class, 'index']);
    Route::get('/admin/audit', [AuditController::class, 'index']);
    Route::get('/admin/system', [SystemController::class, 'page']);
    Route::get('/admin/ai-generate', [AiGenerateController::class, 'page']);
    Route::get('/admin/learning-objectives', [LearningObjectivesController::class, 'page']);
    Route::get('/admin/ai-settings', [AiSettingsController::class, 'page']);
});

// Student exam-taking screens (data fetched from the /api endpoints).
Route::middleware('jwt.auth:student,admin')->group(function () {
    Route::get('/exam/{examId}', fn (string $examId) => Inertia::render('Exam/Take', ['examId' => $examId]));
    Route::get('/student/result/{submissionId}', [StudentController::class, 'result']);
});
