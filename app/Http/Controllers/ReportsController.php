<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->attributes->get('authUser');
        $isTeacher = $u->role === 'teacher';

        $exams = Exam::when($isTeacher, fn ($q) => $q->where('created_by', $u->id))
            ->orderBy('name')->get(['id', 'exam_code', 'name', 'passing_grade']);
        $examIds = $exams->pluck('id');

        $students = User::where('role', 'student')
            ->when($isTeacher, fn ($q) => $q->where('created_by', $u->id))
            ->orderBy('full_name')->get(['id', 'full_name', 'username']);
        $studentIds = $students->pluck('id');

        $subsByStudent = ExamSubmission::whereIn('user_id', $studentIds)->whereIn('exam_id', $examIds)
            ->get(['exam_id', 'user_id', 'percent_score', 'passed', 'pending_essay_count', 'topic_breakdown'])
            ->groupBy('user_id');

        $classes = DB::table('student_classes')
            ->when($isTeacher, fn ($q) => $q->where('created_by', $u->id))->get()->keyBy('id');
        $firstClassByStudent = [];
        foreach (DB::table('class_students')->whereIn('class_id', $classes->keys())->get() as $l) {
            if (! isset($firstClassByStudent[$l->student_identifier])) {
                $firstClassByStudent[$l->student_identifier] = $l->class_id;
            }
        }

        $examCols = $exams->map(fn ($e) => [
            'examDatabaseId' => $e->id, 'examId' => $e->exam_code, 'examName' => $e->name, 'passingGrade' => $e->passing_grade,
        ]);

        $buckets = [];
        $noClass = [];
        foreach ($students as $stu) {
            $row = $this->buildRow($stu, $subsByStudent[$stu->id] ?? collect());
            $cid = $firstClassByStudent[$stu->id] ?? null;
            if ($cid && isset($classes[$cid])) {
                $buckets[$cid]['class'] = $classes[$cid];
                $buckets[$cid]['students'][] = $row;
            } else {
                $noClass[] = $row;
            }
        }

        $byName = fn ($a, $z) => strcmp($a['studentName'], $z['studentName']);
        $out = [];
        foreach ($buckets as $cid => $b) {
            usort($b['students'], $byName);
            $out[] = [
                'classId' => $cid, 'className' => $b['class']->name, 'academicYear' => $b['class']->academic_year,
                'studentCount' => count($b['students']), 'students' => $b['students'],
            ];
        }
        usort($out, fn ($a, $z) => strcmp($a['className'], $z['className']));
        if ($noClass) {
            usort($noClass, $byName);
            $out[] = ['classId' => null, 'className' => 'No class', 'academicYear' => null, 'studentCount' => count($noClass), 'students' => $noClass];
        }

        return Inertia::render('Teacher/Reports', ['exams' => $examCols, 'classes' => $out]);
    }

    private function buildRow($stu, $subs): array
    {
        $perExam = [];
        $totalGraded = 0.0;
        $graded = 0;
        $pending = 0;
        $passed = 0;
        $topicEarn = [];
        foreach ($subs as $s) {
            $isPending = $s->pending_essay_count > 0;
            $perExam[$s->exam_id] = [
                'percent' => $s->percent_score,
                'passed' => (bool) $s->passed,
                'status' => $isPending ? 'pending_grading' : 'graded',
            ];
            if ($isPending) {
                $pending++;
            } else {
                $graded++;
                $totalGraded += $s->percent_score;
                if ($s->passed) {
                    $passed++;
                }
                foreach ((is_array($s->topic_breakdown) ? $s->topic_breakdown : []) as $row) {
                    if (! isset($row['topic'])) {
                        continue;
                    }
                    $t = $row['topic'];
                    $topicEarn[$t] ??= ['e' => 0.0, 'p' => 0.0];
                    $topicEarn[$t]['e'] += (float) ($row['earned'] ?? 0);
                    $topicEarn[$t]['p'] += (float) ($row['possible'] ?? 0);
                }
            }
        }
        $avg = $graded === 0 ? null : round($totalGraded / $graded, 2);
        $strongest = null;
        $weakest = null;
        $best = -1;
        $worst = 101;
        foreach ($topicEarn as $t => $v) {
            if ($v['p'] <= 0) {
                continue;
            }
            $pct = $v['e'] / $v['p'] * 100;
            if ($pct > $best) { $best = $pct; $strongest = $t; }
            if ($pct < $worst) { $worst = $pct; $weakest = $t; }
        }

        return [
            'studentId' => $stu->id,
            'studentName' => $stu->full_name,
            'username' => $stu->username,
            'perExam' => (object) $perExam,
            'examsTaken' => count($perExam),
            'examsPassed' => $passed,
            'pendingCount' => $pending,
            'averagePercent' => $avg,
            'strongestTopic' => $strongest,
            'weakestTopic' => $weakest,
        ];
    }
}
