<?php

namespace App\Http\Controllers;

use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StudentsController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->attributes->get('authUser');
        $isTeacher = $u->role === 'teacher';

        $students = User::where('role', 'student')
            ->when($isTeacher, fn ($q) => $q->where('created_by', $u->id))
            ->orderBy('full_name')
            ->get(['id', 'username', 'full_name', 'active', 'extra_time_percent', 'created_at']);
        $byId = $students->keyBy('id');
        $ids = $students->pluck('id');

        $subCounts = ExamSubmission::whereIn('user_id', $ids)
            ->selectRaw('user_id, count(*) as c')->groupBy('user_id')->pluck('c', 'user_id');

        $classes = DB::table('student_classes')
            ->when($isTeacher, fn ($q) => $q->where('created_by', $u->id))
            ->orderBy('name')->get();
        $linksByClass = DB::table('class_students')
            ->whereIn('class_id', $classes->pluck('id'))->get()->groupBy('class_id');

        $row = fn ($s) => [
            'userId' => $s->id,
            'username' => $s->username,
            'fullName' => $s->full_name,
            'active' => (bool) $s->active,
            'extraTimePercent' => (int) $s->extra_time_percent,
            'submissions' => (int) ($subCounts[$s->id] ?? 0),
        ];

        $placed = [];
        $groups = [];
        foreach ($classes as $c) {
            $rows = [];
            foreach (($linksByClass[$c->id] ?? collect()) as $link) {
                $s = $byId->get($link->student_identifier);
                if (! $s) {
                    continue;
                }
                $placed[$s->id] = true;
                $rows[] = $row($s);
            }
            $groups[] = ['className' => $c->name, 'academicYear' => $c->academic_year, 'students' => $rows];
        }
        $orphans = $students->reject(fn ($s) => isset($placed[$s->id]))->map($row)->values();
        if ($orphans->isNotEmpty()) {
            $groups[] = ['className' => 'No class', 'academicYear' => null, 'students' => $orphans];
        }

        return Inertia::render('Teacher/Students', ['groups' => $groups]);
    }
}

