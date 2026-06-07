<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Services\Audit;
use App\Services\CryptoSecrets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ExamDetailController extends Controller
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private function resolveExam(string $idOrCode): ?Exam
    {
        return Exam::where('id', $idOrCode)->orWhere('exam_code', $idOrCode)->first();
    }

    private function owns($user, Exam $exam): bool
    {
        return $user->role === 'admin' || $exam->created_by === $user->id;
    }

    private function tokenDigest(string $token): string
    {
        return hash('sha256', strtoupper(trim($token)));
    }

    private function generatePlainToken(): string
    {
        $out = '';
        for ($i = 0; $i < 6; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $out;
    }

    // GET /{role}/exams/{examId}
    public function show(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $exam) {
            abort(404);
        }
        if (! $this->owns($user, $exam)) {
            return redirect('/'.$user->role.'/exams');
        }

        $questions = ExamQuestion::where('exam_id', $exam->id)->orderBy('position')
            ->get(['id', 'position', 'type', 'topic', 'prompt', 'points', 'options', 'correct_answer', 'explanation_text'])
            ->map(fn ($q) => [
                'id' => $q->id,
                'position' => $q->position,
                'type' => $q->type,
                'topic' => $q->topic,
                'prompt' => $q->prompt,
                'points' => $q->points,
                'options' => $q->options,
                'correctAnswer' => $q->correct_answer,
                'explanationText' => $q->explanation_text,
            ]);

        $tokens = DB::table('exam_access_tokens')->where('exam_id', $exam->id)
            ->orderByDesc('created_at')->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'code' => CryptoSecrets::decryptTokenPreview($t->token_preview) ?? $t->token_preview,
                'maxUses' => $t->max_uses,
                'usedCount' => $t->used_count,
                'active' => (bool) $t->active,
                'expiresAt' => $t->expires_at,
                'createdByName' => $t->created_by_name ?? '(unknown)',
            ]);

        return Inertia::render('Teacher/ExamDetail', [
            'exam' => [
                'examId' => $exam->exam_code,
                'name' => $exam->name,
                'durationMinutes' => $exam->duration_minutes,
                'passingGrade' => $exam->passing_grade,
                'active' => (bool) $exam->active,
                'examMode' => $exam->exam_mode,
                'subject' => $exam->subject,
                'sebRequired' => (bool) $exam->seb_required,
                'sebKeySet' => ! empty($exam->seb_secret),
                'generalInstructions' => $exam->general_instructions ?? '',
                'shuffleQuestions' => (bool) $exam->shuffle_questions,
                'shuffleOptions' => (bool) $exam->shuffle_options,
                'allowAnswerReview' => (bool) $exam->allow_answer_review,
                'drawCount' => $exam->draw_count,
                'questionCount' => $questions->count(),
                'mediaBaseUrl' => $exam->media_base_url,
                'startTime' => optional($exam->start_time)->format('Y-m-d\TH:i'),
                'endTime' => optional($exam->end_time)->format('Y-m-d\TH:i'),
            ],
            'questions' => $questions,
            'tokens' => $tokens,
            'examsBasePath' => '/'.$user->role.'/exams',
        ]);
    }

    // POST /api/teacher/exams/{examId}/tokens
    public function generateToken(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $exam || ! $this->owns($user, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }

        $maxUses = (int) $request->input('maxUses', 40);
        if ($maxUses < 1 || $maxUses > 5000) {
            return response()->json(['error' => 'Max uses must be 1–5000.'], 400);
        }
        $expiresRaw = $request->input('expiresAt');
        $expiresAt = ($expiresRaw && strtotime((string) $expiresRaw) !== false)
            ? date('Y-m-d H:i:s', strtotime((string) $expiresRaw))
            : null;

        // Generate a unique code (retry on the rare digest collision).
        $code = '';
        $digest = '';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->generatePlainToken();
            $digest = $this->tokenDigest($code);
            $exists = DB::table('exam_access_tokens')->where('token_digest', $digest)->exists();
            if (! $exists) {
                break;
            }
        }

        DB::table('exam_access_tokens')->insert([
            'id' => (string) Str::uuid(),
            'exam_id' => $exam->id,
            'class_id' => null,
            'token_digest' => $digest,
            'token_preview' => CryptoSecrets::encryptTokenPreview($code),
            'max_uses' => $maxUses,
            'used_count' => 0,
            'expires_at' => $expiresAt,
            'active' => 1,
            'created_by' => $user->id,
            'created_by_name' => $user->full_name,
            'created_at' => now(),
        ]);

        Audit::log($request, 'token.generate', 'exam', $exam->id, "Generated access token for {$exam->name}", ['maxUses' => $maxUses]);
        return response()->json(['code' => $code, 'maxUses' => $maxUses]);
    }

    // GET /api/teacher/exams/{examId}/seb-config  — download a .seb plist
    public function sebConfig(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $exam || ! $this->owns($user, $exam)) {
            abort(403);
        }
        $secret = (string) ($exam->seb_secret ?? '');
        if ($secret === '') {
            return response()->json(['error' => 'Set a Browser Exam Key first.'], 400);
        }
        $examUrl = url('/exam/'.$exam->exam_code);
        $xml = $this->buildSebConfigXml($exam->name, $examUrl, $secret);
        $slug = preg_replace('/^_+|_+$/', '', preg_replace('/[^a-z0-9]+/', '_', strtolower($exam->name))) ?: 'exam';

        return response($xml, 200, [
            'Content-Type' => 'application/seb',
            'Content-Disposition' => 'attachment; filename="'.substr($slug, 0, 40).'.seb"',
        ]);
    }

    private function buildSebConfigXml(string $name, string $url, string $key): string
    {
        $esc = fn ($v) => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $n = $esc($name);
        $u = $esc($url);
        $k = $esc($key);
        return <<<XML
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
          <key>originatorVersion</key><string>Exam Dashboard</string>
          <key>startURL</key><string>{$u}</string>
          <key>hashedAdminPassword</key><string></string>
          <key>hashedQuitPassword</key><string></string>
          <key>browserExamKey</key><string>{$k}</string>
          <key>sendBrowserExamKey</key><true/>
          <key>enableQuitURL</key><true/>
          <key>browserViewMode</key><integer>0</integer>
          <key>browserWindowAllowReload</key><true/>
          <key>browserWindowShowURL</key><integer>0</integer>
          <key>copyToClipboard</key><false/>
          <key>cutToClipboard</key><false/>
          <key>pasteFromClipboard</key><false/>
          <key>enableF12</key><false/>
          <key>enablePrintScreen</key><false/>
          <key>showTaskBar</key><false/>
          <key>showTime</key><true/>
          <key>showReloadButton</key><true/>
          <key>title</key><string>{$n}</string>
        </dict>
        </plist>
        XML;
    }

    // POST /api/teacher/exams/{examId}/seb  — { sebRequired, sebKey? }
    public function saveSeb(Request $request, string $examId)
    {
        $user = $request->attributes->get('authUser');
        $exam = $this->resolveExam($examId);
        if (! $exam || ! $this->owns($user, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        $required = filter_var($request->input('sebRequired', false), FILTER_VALIDATE_BOOLEAN);
        $key = trim((string) $request->input('sebKey', ''));
        if ($required && $key === '' && empty($exam->seb_secret)) {
            return response()->json(['error' => 'Paste the Browser Exam Key (from the SEB Config Tool) before requiring SEB.'], 400);
        }
        $exam->seb_required = $required;
        if ($key !== '') {
            $exam->seb_secret = $key;
        }
        $exam->save();
        Audit::log($request, 'exam.seb', 'exam', $exam->id, ($required ? 'Enabled' : 'Disabled')." SEB requirement for {$exam->name}");
        return response()->json([
            'ok' => true,
            'sebRequired' => (bool) $exam->seb_required,
            'sebKeySet' => ! empty($exam->seb_secret),
        ]);
    }

    // POST /api/teacher/tokens/{tokenId}/deactivate
    public function deactivateToken(Request $request, string $tokenId)
    {
        $user = $request->attributes->get('authUser');
        $tok = DB::table('exam_access_tokens')->where('id', $tokenId)->first();
        if (! $tok) {
            return response()->json(['error' => 'Token not found.'], 404);
        }
        $exam = Exam::find($tok->exam_id);
        if (! $exam || ! $this->owns($user, $exam)) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        DB::table('exam_access_tokens')->where('id', $tokenId)->update(['active' => 0]);
        Audit::log($request, 'token.deactivate', 'token', $tokenId, 'Deactivated an access token');
        return response()->json(['ok' => true]);
    }

    /** Resolve a token row + its exam, enforcing ownership. */
    private function ownedToken($user, string $tokenId): ?object
    {
        $tok = DB::table('exam_access_tokens')->where('id', $tokenId)->first();
        if (! $tok) {
            return null;
        }
        $exam = Exam::find($tok->exam_id);
        if (! $exam || ! $this->owns($user, $exam)) {
            return null;
        }
        return $tok;
    }

    // POST /api/teacher/tokens/{tokenId}/active   { active: bool }
    public function setTokenActive(Request $request, string $tokenId)
    {
        $tok = $this->ownedToken($request->attributes->get('authUser'), $tokenId);
        if (! $tok) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        $active = filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN);
        DB::table('exam_access_tokens')->where('id', $tokenId)->update(['active' => $active ? 1 : 0]);
        Audit::log($request, 'token.set_active', 'token', $tokenId, ($active ? 'Activated' : 'Deactivated').' an access token');
        return response()->json(['ok' => true, 'active' => $active]);
    }

    // POST /api/teacher/tokens/{tokenId}/delete   (hard delete + redemptions)
    public function deleteTokenHard(Request $request, string $tokenId)
    {
        $tok = $this->ownedToken($request->attributes->get('authUser'), $tokenId);
        if (! $tok) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        DB::table('exam_token_redemptions')->where('token_id', $tokenId)->delete();
        DB::table('exam_access_tokens')->where('id', $tokenId)->delete();
        Audit::log($request, 'token.delete', 'token', $tokenId, 'Permanently deleted an access token');
        return response()->json(['ok' => true]);
    }

    // POST /api/teacher/tokens/{tokenId}/regenerate
    //   Issue a fresh code (same maxUses + expiry) and deactivate the old one.
    public function regenerateToken(Request $request, string $tokenId)
    {
        $user = $request->attributes->get('authUser');
        $tok = $this->ownedToken($user, $tokenId);
        if (! $tok) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }

        $code = '';
        $digest = '';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->generatePlainToken();
            $digest = $this->tokenDigest($code);
            if (! DB::table('exam_access_tokens')->where('token_digest', $digest)->exists()) {
                break;
            }
        }

        DB::table('exam_access_tokens')->insert([
            'id' => (string) Str::uuid(),
            'exam_id' => $tok->exam_id,
            'class_id' => $tok->class_id ?? null,
            'token_digest' => $digest,
            'token_preview' => CryptoSecrets::encryptTokenPreview($code),
            'max_uses' => $tok->max_uses,
            'used_count' => 0,
            'expires_at' => $tok->expires_at,
            'active' => 1,
            'created_by' => $user->id,
            'created_by_name' => $user->full_name,
            'created_at' => now(),
        ]);
        DB::table('exam_access_tokens')->where('id', $tokenId)->update(['active' => 0]);
        Audit::log($request, 'token.regenerate', 'token', $tokenId, 'Regenerated an access token (issued a fresh code)');

        return response()->json(['ok' => true, 'code' => $code]);
    }
}
