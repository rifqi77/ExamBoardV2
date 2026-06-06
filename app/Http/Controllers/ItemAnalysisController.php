<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Services\ItemAnalysis;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ItemAnalysisController extends Controller
{
    // GET /{role}/exams/{examId}/analysis
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

        return Inertia::render('Teacher/ItemAnalysis', array_merge(
            ItemAnalysis::forExam($exam),
            ['examsBasePath' => '/'.$user->role.'/exams']
        ));
    }
}
