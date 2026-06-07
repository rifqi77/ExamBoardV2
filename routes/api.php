<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\ExamAuthorController;
use App\Http\Controllers\ExamDetailController;
use App\Http\Controllers\ExamTakeController;
use App\Http\Controllers\LiveMonitorController;
use App\Http\Controllers\ScoreToolsController;
use App\Http\Controllers\StudentMgmtController;
use App\Http\Controllers\TeacherGradeController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Authenticated (JWT cookie, same as the Next app)
Route::middleware('jwt.auth')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    // Stop impersonation — allowed from inside the impersonated (teacher) session.
    Route::post('/admin/impersonate/stop', [\App\Http\Controllers\ImpersonationController::class, 'stop']);

    // Student exam-taking flow
    Route::post('/exam-access/validate', [ExamTakeController::class, 'validateToken'])->middleware('throttle:token');
    Route::get('/exams/{examId}', [ExamTakeController::class, 'show']);
    Route::post('/exams/{examId}/draft', [ExamTakeController::class, 'draft']);
    Route::post('/exams/{examId}/events', [ExamTakeController::class, 'events']);
    Route::post('/exams/{examId}/submit', [ExamTakeController::class, 'submit']);
});

// Teacher/admin write actions
Route::middleware('jwt.auth:teacher,admin')->group(function () {
    Route::post('/teacher/exams/{examId}/tokens', [ExamDetailController::class, 'generateToken']);
    Route::post('/teacher/tokens/{tokenId}/deactivate', [ExamDetailController::class, 'deactivateToken']);
    Route::post('/teacher/tokens/{tokenId}/active', [ExamDetailController::class, 'setTokenActive']);
    Route::post('/teacher/tokens/{tokenId}/delete', [ExamDetailController::class, 'deleteTokenHard']);
    Route::post('/teacher/tokens/{tokenId}/regenerate', [ExamDetailController::class, 'regenerateToken']);
    Route::post('/teacher/exams/{examId}/seb', [ExamDetailController::class, 'saveSeb']);
    Route::get('/teacher/exams/{examId}/seb-config', [ExamDetailController::class, 'sebConfig']);
    Route::post('/teacher/submissions/{submissionId}/grade', [TeacherGradeController::class, 'grade']);
    Route::post('/teacher/submissions/{submissionId}/suggest-grades', [TeacherGradeController::class, 'suggest'])->middleware('throttle:ai');
    Route::get('/teacher/exams/{examId}/grading-quality', [TeacherGradeController::class, 'quality']);
    Route::post('/teacher/exams', [ExamAuthorController::class, 'createExam']);
    Route::post('/teacher/exams/{examId}/settings', [ExamAuthorController::class, 'updateExam']);
    Route::post('/teacher/exams/{examId}/questions', [ExamAuthorController::class, 'addQuestion']);
    Route::post('/teacher/questions/{questionId}', [ExamAuthorController::class, 'updateQuestion']);
    Route::post('/teacher/questions/{questionId}/delete', [ExamAuthorController::class, 'deleteQuestion']);
    Route::post('/teacher/students', [StudentMgmtController::class, 'create']);
    Route::post('/teacher/students/bulk', [StudentMgmtController::class, 'bulk']);
    Route::post('/teacher/classes/import', [\App\Http\Controllers\ImportController::class, 'importClasses']);
    Route::get('/teacher/exams/{examId}/live-scores', [LiveMonitorController::class, 'data']);
    // Question bank
    Route::get('/teacher/bank', [BankController::class, 'list']);
    Route::get('/teacher/bank/options', [BankController::class, 'options']);
    Route::post('/teacher/bank/import', [BankController::class, 'import']);
    Route::post('/teacher/bank/{id}/delete', [BankController::class, 'destroy']);
    Route::post('/teacher/bank/{id}', [BankController::class, 'update']);
    Route::post('/teacher/exams/{examId}/questions/from-bank', [BankController::class, 'fromBank']);
    Route::post('/teacher/exams/{examId}/auto-fill', [BankController::class, 'autoFill']);
    // Score recovery + bulk grading tools
    Route::post('/teacher/exams/{examId}/finalize-drafts', [ScoreToolsController::class, 'finalizeDrafts']);
    Route::post('/teacher/exams/{examId}/reset-session', [ScoreToolsController::class, 'resetSession']);
    Route::get('/teacher/exams/{examId}/ai-export', [ScoreToolsController::class, 'aiExport']);
    Route::post('/teacher/grade-bulk', [ScoreToolsController::class, 'gradeBulk']);
    Route::post('/teacher/submissions/{submissionId}/delete', [ScoreToolsController::class, 'deleteSubmission']);
    Route::post('/teacher/submissions/bulk-delete', [ScoreToolsController::class, 'bulkDelete']);
    Route::get('/teacher/ai-generate/status', [\App\Http\Controllers\AiGenerateController::class, 'status']);
    Route::post('/teacher/ai-generate/run', [\App\Http\Controllers\AiGenerateController::class, 'run'])->middleware('throttle:ai');
    Route::get('/teacher/ai-jobs/{id}', [\App\Http\Controllers\AiGenerateController::class, 'jobStatus']);
    // Learning objectives
    Route::post('/teacher/learning-objectives', [\App\Http\Controllers\LearningObjectivesController::class, 'upload']);
    Route::post('/teacher/learning-objectives/bulk-delete', [\App\Http\Controllers\LearningObjectivesController::class, 'bulkDelete']);
    Route::post('/teacher/learning-objectives/{id}/delete', [\App\Http\Controllers\LearningObjectivesController::class, 'destroy']);
});

// Admin-only write actions
Route::middleware('jwt.auth:admin')->group(function () {
    Route::post('/admin/impersonate/{uid}', [\App\Http\Controllers\ImpersonationController::class, 'start']);
    Route::get('/admin/system-health', [\App\Http\Controllers\SystemController::class, 'data']);
    Route::post('/admin/teachers', [\App\Http\Controllers\AdminTeachersController::class, 'create']);
    Route::post('/admin/teachers/{uid}', [\App\Http\Controllers\AdminTeachersController::class, 'update']);
    Route::get('/admin/teachers/{uid}/capabilities', [\App\Http\Controllers\AdminTeachersController::class, 'capabilities']);
    Route::post('/admin/teachers/{uid}/capabilities', [\App\Http\Controllers\AdminTeachersController::class, 'setCapabilities']);
    Route::put('/admin/ai-settings', [\App\Http\Controllers\AiSettingsController::class, 'saveSettings']);
    Route::patch('/admin/ai-settings', [\App\Http\Controllers\AiSettingsController::class, 'saveKeys']);
});
