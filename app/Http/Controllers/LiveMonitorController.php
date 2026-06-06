<?php

namespace App\Http\Controllers;

use App\Models\AnswerDraft;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Models\User;
use App\Services\ExamDraw;
use App\Services\Scoring;
use Illuminate\Http\Request;

class LiveMonitorController extends Controller
{
    private function resolveExam(string $idOrCode): ?Exam
    {
        return Exam::where('id', $idOrCode)->orWhere('exam_code', $idOrCode)->first();
    }

    private function owns($user, ?Exam $exam): bool
    {
        return $exam && ($user->role === 'admin' || $exam->created_by === $user->id);
    }

    private function isAnswered($v): bool
    {
        if (is_array($v)) return count($v) > 0;
        if (is_string($v)) return trim($v) !== '';
        return $v !== null;
    }

    // GET /api/teacher/exams/{examId}/live-scores  (polled)
    public function data(Request $request, string $examId)
    {
        $u = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $this->owns($u, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }

        $questions = ExamQuestion::where('exam_id', $exam->id)->orderBy('position')
            ->get(['id', 'type', 'points', 'topic', 'correct_answer']);
        $total = $questions->count();
        $scoreInput = $questions->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all();
        $keys = $questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all();

        // Latest session per student.
        $sessions = ExamSession::where('exam_id', $exam->id)->orderBy('user_id')->orderByDesc('created_at')->get();
        $seen = [];
        $latest = [];
        foreach ($sessions as $s) {
            if (isset($seen[$s->user_id])) continue;
            $seen[$s->user_id] = true;
            $latest[] = $s;
        }
        $sessionIds = collect($latest)->pluck('id');
        $users = User::whereIn('id', collect($latest)->pluck('user_id'))->get(['id', 'username', 'full_name'])->keyBy('id');
        $draftsBySession = AnswerDraft::whereIn('session_id', $sessionIds)->get(['session_id', 'question_id', 'value'])->groupBy('session_id');
        $subsBySession = ExamSubmission::whereIn('session_id', $sessionIds)->get(['session_id', 'answers_snapshot', 'manual_scores'])->keyBy('session_id');

        $rows = [];
        foreach ($latest as $s) {
            $submitted = $s->status === 'submitted';
            $sub = $subsBySession[$s->id] ?? null;
            if ($submitted && $sub && is_array($sub->answers_snapshot)) {
                $answers = $sub->answers_snapshot;
                $manual = is_array($sub->manual_scores) ? $sub->manual_scores : [];
            } else {
                $answers = [];
                foreach (($draftsBySession[$s->id] ?? collect()) as $d) {
                    $answers[$d->question_id] = $d->value;
                }
                $manual = [];
            }

            $sQ = ExamDraw::filter($questions, $s->drawn_question_ids);
            $score = Scoring::score(
                $sQ->map(fn ($q) => ['id' => $q->id, 'topic' => $q->topic, 'points' => $q->points, 'type' => $q->type])->all(),
                $sQ->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all(),
                $answers, $manual
            );
            $autoEarned = 0.0;
            $autoPossible = 0.0;
            $essayPossible = 0.0;
            $essayPending = 0;
            foreach ($score['itemResults'] as $it) {
                if ($it['type'] === 'essay') {
                    $essayPossible += $it['possible'];
                    if ($it['requiresGrading']) $essayPending++;
                } else {
                    $autoEarned += $it['awarded'];
                    $autoPossible += $it['possible'];
                }
            }
            $answered = 0;
            foreach ($sQ as $q) {
                if ($this->isAnswered($answers[$q->id] ?? null)) $answered++;
            }
            $elapsed = time() - $s->started_at->getTimestamp();
            $usr = $users[$s->user_id] ?? null;
            $rows[] = [
                'userId' => $s->user_id,
                'username' => $usr->username ?? '?',
                'fullName' => $usr->full_name ?? '?',
                'status' => $submitted ? 'submitted' : $s->status,
                'answeredCount' => $answered,
                'totalQuestions' => $sQ->count(),
                'autoEarned' => round($autoEarned, 2),
                'autoPossible' => round($autoPossible, 2),
                'autoPct' => $autoPossible > 0 ? round($autoEarned / $autoPossible * 100, 1) : 0,
                'essayPending' => $essayPending,
                'antiCheatCount' => is_array($s->anti_cheat_events) ? count($s->anti_cheat_events) : 0,
                'timeRemainingSeconds' => $submitted ? 0 : max(0, $exam->duration_minutes * 60 - $elapsed),
            ];
        }
        usort($rows, function ($a, $b) {
            $rank = fn ($st) => $st === 'draft' ? 0 : ($st === 'submitted' ? 1 : 2);
            if ($rank($a['status']) !== $rank($b['status'])) return $rank($a['status']) - $rank($b['status']);
            return $b['autoPct'] <=> $a['autoPct'];
        });

        return response()->json([
            'exam' => ['examCode' => $exam->exam_code, 'name' => $exam->name, 'passingGrade' => $exam->passing_grade, 'totalQuestions' => $total],
            'students' => $rows,
        ]);
    }
}
