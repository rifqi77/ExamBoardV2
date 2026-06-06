<?php

namespace Tests\Feature;

use App\Http\Controllers\TeacherGradeController;
use App\Models\ExamSubmission;
use App\Models\User;
use App\Services\AssistedGrading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistedGradingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Force the deterministic lexical-only path (provider with no key) so
        // the test never makes a live AI call.
        DB::table('app_config_ai')->updateOrInsert(['id' => 'ai'], [
            'text_provider' => 'openai', 'text_model' => 'gpt-4o', 'temperature' => 0.4,
            'image_provider' => 'off', 'updated_at' => now(),
        ]);
    }

    private function setupExam(string $teacherId, string $studentId, string $studentAnswer): array
    {
        $examId = (string) Str::uuid();
        DB::table('exams')->insert(['id' => $examId, 'exam_code' => 'AG'.substr($examId, 0, 4), 'name' => 'AG', 'duration_minutes' => 30, 'passing_grade' => 50, 'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'created_by' => $teacherId, 'created_at' => now(), 'updated_at' => now()]);
        $qid = (string) Str::uuid();
        DB::table('exam_questions')->insert(['id' => $qid, 'exam_id' => $examId, 'position' => 1, 'type' => 'essay', 'topic' => 'T', 'prompt' => 'Explain Newton 2', 'points' => 10, 'explanation_text' => "Newton's second law states force equals mass times acceleration", 'created_at' => now()]);
        $subId = (string) Str::uuid();
        DB::table('exam_submissions')->insert(['id' => $subId, 'exam_id' => $examId, 'user_id' => $studentId, 'username' => 's', 'full_name' => 'S', 'exam_name' => 'AG', 'exam_mode' => 'strict', 'attempt' => 1, 'passing_grade' => 50, 'final_score' => 0, 'possible_score' => 10, 'percent_score' => 0, 'passed' => 0, 'pending_essay_count' => 1, 'answers_snapshot' => json_encode([$qid => $studentAnswer]), 'submitted_at' => now()]);
        return [$examId, $qid, $subId];
    }

    public function test_lexical_suggestion_is_produced_and_persisted(): void
    {
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'tg', 'full_name' => 'TG', 'role' => 'teacher', 'active' => true]);
        $student = User::create(['id' => (string) Str::uuid(), 'username' => 'sg', 'full_name' => 'SG', 'role' => 'student', 'active' => true]);
        [, $qid, $subId] = $this->setupExam($teacher->id, $student->id, 'force equals mass times acceleration');

        $out = AssistedGrading::forSubmission(ExamSubmission::find($subId), 1);

        $this->assertArrayHasKey($qid, $out);
        $this->assertNull($out[$qid]['ai']);                       // no key → AI skipped (deterministic)
        $this->assertNotNull($out[$qid]['lexical']);               // lexical always runs
        $this->assertGreaterThan(0, $out[$qid]['lexical']['rouge1']);
        $this->assertNotNull($out[$qid]['suggested']);
        // Persisted for later chi-square comparison.
        $this->assertNotNull(ExamSubmission::find($subId)->grading_suggestions);
    }

    public function test_quality_endpoint_pairs_ai_with_human_grades(): void
    {
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'tq', 'full_name' => 'TQ', 'role' => 'teacher', 'active' => true]);
        $s1 = User::create(['id' => (string) Str::uuid(), 'username' => 's1', 'full_name' => 'S1', 'role' => 'student', 'active' => true]);
        $s2 = User::create(['id' => (string) Str::uuid(), 'username' => 's2', 'full_name' => 'S2', 'role' => 'student', 'active' => true]);

        $examId = (string) Str::uuid();
        DB::table('exams')->insert(['id' => $examId, 'exam_code' => 'QUAL1', 'name' => 'Q', 'duration_minutes' => 30, 'passing_grade' => 50, 'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'created_by' => $teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        $qid = (string) Str::uuid();
        DB::table('exam_questions')->insert(['id' => $qid, 'exam_id' => $examId, 'position' => 1, 'type' => 'essay', 'topic' => 'T', 'prompt' => 'P', 'points' => 10, 'explanation_text' => 'model', 'created_at' => now()]);

        foreach ([[$s1->id, 8.0, 8.0], [$s2->id, 6.0, 7.0]] as [$uid, $ai, $human]) {
            DB::table('exam_submissions')->insert([
                'id' => (string) Str::uuid(), 'exam_id' => $examId, 'user_id' => $uid, 'username' => 'x', 'full_name' => 'X',
                'exam_name' => 'Q', 'exam_mode' => 'strict', 'attempt' => 1, 'passing_grade' => 50,
                'final_score' => $human, 'possible_score' => 10, 'percent_score' => $human * 10, 'passed' => 1, 'pending_essay_count' => 0,
                'answers_snapshot' => json_encode([$qid => 'ans']),
                'manual_scores' => json_encode([$qid => $human]),
                'grading_suggestions' => json_encode([$qid => ['suggested' => $ai, 'maxPoints' => 10]]),
                'submitted_at' => now(),
            ]);
        }

        $req = Request::create('/x', 'GET');
        $req->attributes->set('authUser', $teacher);
        $res = json_decode((new TeacherGradeController())->quality($req, $examId)->getContent(), true);

        $this->assertSame(2, $res['pairs']);
        $this->assertArrayHasKey('chiSquare', $res);
        $this->assertArrayHasKey('pValue', $res);
        $this->assertTrue($res['aligned']); // 8/8 and 6/7 are close → aligned
    }
}
