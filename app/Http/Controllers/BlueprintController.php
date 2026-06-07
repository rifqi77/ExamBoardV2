<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Services\ExamBlueprint;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BlueprintController extends Controller
{
    // GET /{role}/exams/{examId}/blueprint
    public function show(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = Exam::where('id', $examId)->orWhere('exam_code', $examId)->first();
        if (! $exam) {
            abort(404);
        }
        if (! ($user->role === 'admin' || $exam->created_by === $user->id)) {
            return redirect('/'.$user->role.'/exams');
        }

        return Inertia::render('Teacher/Blueprint', array_merge(
            ExamBlueprint::forExam($exam),
            ['examsBasePath' => '/'.$user->role.'/exams']
        ));
    }
}
