<?php

namespace App\Http\Controllers;

use App\Models\AnswerDraft;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AnswerAuditController extends Controller
{
    private function resolveExam(string $idOrCode): ?Exam
    {
        return Exam::where('id', $idOrCode)->orWhere('exam_code', $idOrCode)->first();
    }

    private function owns($user, ?Exam $exam): bool
    {
        return $exam && ($user->role === 'admin' || $exam->created_by === $user->id);
    }

    private function sortedJson($v): string
    {
        if (is_array($v)) {
            $a = array_map('strval', $v);
            sort($a, SORT_STRING);
            return json_encode($a);
        }
        return json_encode($v);
    }

    private function eq($a, $b): bool
    {
        if ($a === $b) return true;
        if ($a === null || $b === null) return false;
        return $this->sortedJson($a) === $this->sortedJson($b);
    }

    // GET /{role}/exams/{examId}/audit
    public function show(Request $request, string $examId)
    {
        $u = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $exam) {
            abort(404);
        }
        if (! $this->owns($u, $exam)) {
            return redirect('/'.$u->role.'/exams');
        }

        $questions = ExamQuestion::where('exam_id', $exam->id)->orderBy('position')->get(['id', 'position']);
        $sessions = ExamSession::where('exam_id', $exam->id)->orderBy('user_id')->orderBy('attempt')->get();
        $sessionIds = $sessions->pluck('id');
        $users = User::whereIn('id', $sessions->pluck('user_id'))->get(['id', 'username', 'full_name'])->keyBy('id');
        $draftsBySession = AnswerDraft::whereIn('session_id', $sessionIds)->get(['session_id', 'question_id', 'value'])->groupBy('session_id');
        $subsBySession = ExamSubmission::whereIn('session_id', $sessionIds)
            ->get(['session_id', 'id', 'final_score', 'possible_score', 'percent_score', 'pending_essay_count', 'answers_snapshot', 'submitted_at'])
            ->keyBy('session_id');

        $rows = [];
        foreach ($sessions as $s) {
            $draftMap = [];
            foreach (($draftsBySession[$s->id] ?? collect()) as $d) {
                $draftMap[$d->question_id] = $d->value;
            }
            $sub = $subsBySession[$s->id] ?? null;
            $snap = ($sub && is_array($sub->answers_snapshot)) ? $sub->answers_snapshot : [];

            $mismatch = 0;
            foreach ($questions as $q) {
                $hasD = array_key_exists($q->id, $draftMap);
                $hasS = array_key_exists($q->id, $snap);
                $match = (! $hasD && ! $hasS) ? true
                    : (($hasD && $hasS) ? $this->eq($draftMap[$q->id], $snap[$q->id]) : false);
                if (! $match) $mismatch++;
            }

            $usr = $users[$s->user_id] ?? null;
            $rows[] = [
                'username' => $usr->username ?? '?',
                'fullName' => $usr->full_name ?? '?',
                'status' => $s->status,
                'attempt' => $s->attempt,
                'draftCount' => count($draftMap),
                'snapCount' => count($snap),
                'mismatchCount' => $mismatch,
                'percentScore' => $sub->percent_score ?? null,
                'pendingEssayCount' => $sub->pending_essay_count ?? null,
                'submittedAt' => optional($sub?->submitted_at)->toIso8601String(),
            ];
        }
        usort($rows, function ($a, $b) {
            $am = $a['mismatchCount'] > 0 ? 1 : 0;
            $bm = $b['mismatchCount'] > 0 ? 1 : 0;
            if ($am !== $bm) return $bm - $am;
            return strcmp($a['fullName'], $b['fullName']);
        });

        return Inertia::render('Teacher/AnswerAudit', [
            'exam' => ['examId' => $exam->exam_code, 'name' => $exam->name, 'totalQuestions' => $questions->count()],
            'rows' => $rows,
            'totalMismatch' => array_sum(array_column($rows, 'mismatchCount')),
            'examsBasePath' => '/'.$u->role.'/exams',
        ]);
    }
}
