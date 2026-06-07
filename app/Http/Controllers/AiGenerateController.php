<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateQuestionsJob;
use App\Models\AiJob;
use App\Models\Exam;
use App\Services\AiProviders;
use App\Services\AiQuestionGenerator;
use App\Services\Capabilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AiGenerateController extends Controller
{
    public function page(Request $r)
    {
        $u = $r->attributes->get('authUser');
        $isTeacher = $u->role === 'teacher';
        $exams = Exam::when($isTeacher, fn ($q) => $q->where('created_by', $u->id))
            ->orderByDesc('created_at')->get(['exam_code', 'name']);
        $s = AiProviders::getSettings();

        // Learning-objective context for the topic picker.
        $loScope = fn ($q) => $isTeacher ? $q->where('uploaded_by', $u->id) : $q;
        $loTopics = $loScope(DB::table('learning_objectives'))->whereNotNull('topic')->where('topic', '!=', '')
            ->distinct()->orderBy('topic')->pluck('topic')->all();
        $subjects = $loScope(DB::table('learning_objectives'))->whereNotNull('subject')->where('subject', '!=', '')
            ->distinct()->orderBy('subject')->pluck('subject')->all();

        return Inertia::render('Teacher/AiGenerate', [
            'exams' => $exams->map(fn ($e) => ['examId' => $e->exam_code, 'name' => $e->name]),
            'provider' => $s['textProvider'],
            'model' => $s['textModel'],
            'imageProvider' => $s['imageProvider'],
            'keyReady' => $s['textProvider'] === 'pollinations' ? true : (AiProviders::keyStatus()[$s['textProvider']] ?? false),
            'loTopics' => $loTopics,
            'subjects' => $subjects,
        ]);
    }

    // GET /api/teacher/ai-generate/status
    public function status()
    {
        $s = AiProviders::getSettings();
        $available = $s['textProvider'] === 'pollinations' || (AiProviders::keyStatus()[$s['textProvider']] ?? false);

        return response()->json([
            'available' => $available,
            'provider' => $s['textProvider'],
            'model' => $s['textModel'],
            'imageProvider' => $s['imageProvider'],
        ]);
    }

    // POST /api/teacher/ai-generate/run — validate, queue the work, return a job id to poll.
    public function run(Request $r)
    {
        $u = $r->attributes->get('authUser');
        if (! Capabilities::has($u, 'ai.generate')) {
            return response()->json(['error' => 'Your account is not permitted to use AI generation.'], 403);
        }
        $target = $r->input('target') === 'bank' ? 'bank' : 'exam';

        $exam = null;
        if ($target === 'exam') {
            $exam = Exam::where('exam_code', $r->input('examId'))->orWhere('id', $r->input('examId'))->first();
            if (! $exam || ($u->role !== 'admin' && $exam->created_by !== $u->id)) {
                return response()->json(['error' => 'Exam not found or not yours.'], 403);
            }
        }

        $settings = AiProviders::getSettings();
        if ($settings['textProvider'] !== 'pollinations' && ! (AiProviders::keyStatus()[$settings['textProvider']] ?? false)) {
            return response()->json(['error' => "No API key for {$settings['textProvider']}. Set it in Admin → AI settings, or switch to Pollinations."], 503);
        }

        $count = max(1, min(100, (int) $r->input('count', 5)));
        $params = [
            'target' => $target,
            'examInternalId' => $exam?->id,
            'count' => $count,
            'type' => in_array($r->input('type'), AiQuestionGenerator::TYPES, true) ? $r->input('type') : 'any',
            'topic' => trim((string) $r->input('topic', '')) ?: 'General',
            'subject' => trim((string) $r->input('subject', '')),
            'language' => trim((string) $r->input('language', 'English')) ?: 'English',
            'difficulty' => in_array($r->input('difficulty'), AiQuestionGenerator::DIFFS, true) ? $r->input('difficulty') : 'medium',
            'extra' => trim((string) $r->input('extraInstructions', '')),
            'lo' => trim((string) $r->input('learningObjective', '')),
            'olympiad' => in_array($r->input('olympiadIntensity'), ['intro', 'moderate', 'extreme'], true) ? $r->input('olympiadIntensity') : 'off',
            'wantImages' => filter_var($r->input('generateImages', false), FILTER_VALIDATE_BOOLEAN) && $settings['imageProvider'] !== 'off',
        ];

        $job = AiJob::create([
            'id' => (string) Str::uuid(),
            'user_id' => $u->id,
            'kind' => 'generate_questions',
            'status' => 'queued',
            'params' => $params,
            'total' => $count,
        ]);
        GenerateQuestionsJob::dispatch($job->id);

        return response()->json(['jobId' => $job->id]);
    }

    // GET /api/teacher/ai-jobs/{id} — poll progress/result (owner or admin).
    public function jobStatus(Request $r, string $id)
    {
        $u = $r->attributes->get('authUser');
        $job = AiJob::find($id);
        if (! $job || ($u->role !== 'admin' && $job->user_id !== $u->id)) {
            return response()->json(['error' => 'Job not found.'], 404);
        }

        return response()->json([
            'status' => $job->status,
            'progress' => $job->progress,
            'total' => $job->total,
            'result' => $job->result,
            'error' => $job->error,
        ]);
    }
}
