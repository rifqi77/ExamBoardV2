<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ExamsController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->attributes->get('authUser');
        $isTeacher = $u->role === 'teacher';

        $exams = Exam::when($isTeacher, fn ($q) => $q->where('created_by', $u->id))
            ->orderByDesc('created_at')
            ->get();
        $ids = $exams->pluck('id');

        $agg = ExamSubmission::whereIn('exam_id', $ids)
            ->selectRaw('exam_id, count(*) as c, avg(percent_score) as a, sum(passed) as p')
            ->groupBy('exam_id')->get()->keyBy('exam_id');
        $tokens = DB::table('exam_access_tokens')->whereIn('exam_id', $ids)->where('active', 1)
            ->selectRaw('exam_id, count(*) as c')->groupBy('exam_id')->pluck('c', 'exam_id');
        $owners = User::whereIn('id', $exams->pluck('created_by')->filter()->unique()->values())
            ->pluck('full_name', 'id');

        $rows = $exams->map(function ($e) use ($agg, $tokens, $owners) {
            $a = $agg->get($e->id);
            return [
                'examId' => $e->exam_code,
                'name' => $e->name,
                'owner' => $owners[$e->created_by] ?? '—',
                'durationMinutes' => $e->duration_minutes,
                'passingGrade' => $e->passing_grade,
                'active' => (bool) $e->active,
                'submissions' => $a->c ?? 0,
                'avg' => $a && $a->a !== null ? round((float) $a->a, 1) : null,
                'passed' => $a->p ?? 0,
                'activeTokens' => (int) ($tokens[$e->id] ?? 0),
            ];
        });

        return Inertia::render('Teacher/Exams', [
            'exams' => $rows,
            'scope' => $isTeacher ? 'created by you' : 'across every teacher',
            'examsBasePath' => '/'.$u->role.'/exams',
        ]);
    }
}
