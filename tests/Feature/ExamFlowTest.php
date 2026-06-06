<?php

namespace Tests\Feature;

use App\Http\Controllers\ExamTakeController;
use App\Models\ExamSession;
use App\Models\ExamSubmission;
use App\Models\User;
use App\Services\ExamAccessJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExamFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_then_submit_scores_and_records_anticheat(): void
    {
        $student = User::create(['id' => (string) Str::uuid(), 'username' => 'flowstud', 'full_name' => 'Flow Student', 'role' => 'student', 'active' => true]);

        $examId = (string) Str::uuid();
        DB::table('exams')->insert(['id' => $examId, 'exam_code' => 'FLOW1', 'name' => 'Flow', 'duration_minutes' => 60, 'passing_grade' => 50, 'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'created_at' => now(), 'updated_at' => now()]);
        $qid = (string) Str::uuid();
        DB::table('exam_questions')->insert(['id' => $qid, 'exam_id' => $examId, 'position' => 1, 'type' => 'single_choice', 'topic' => 'T', 'prompt' => 'Pick A', 'points' => 1, 'options' => json_encode([['id' => 'A', 'text' => 'a'], ['id' => 'B', 'text' => 'b']]), 'correct_answer' => json_encode('A'), 'explanation_text' => 'x', 'created_at' => now()]);

        $ctrl = new ExamTakeController();
        $cookie = ExamAccessJwt::sign($student->id, $examId, 'tok');
        $mk = function (string $method, array $params = []) use ($student, $cookie, $examId) {
            $r = Request::create("/api/exams/$examId", $method, $params);
            $r->cookies->set(ExamAccessJwt::COOKIE, $cookie);
            $r->attributes->set('authUser', $student);
            return $r;
        };

        // Load (creates the session).
        $this->assertSame(200, $ctrl->show($mk('GET'), $examId)->getStatusCode());

        // Submit a correct answer + one anti-cheat event in the tail.
        $resp = $ctrl->submit($mk('POST', [
            'answers' => [$qid => 'A'],
            'antiCheatEvents' => [['kind' => 'tab_blur', 'at' => '2026-01-01T00:00:00Z']],
        ]), $examId);
        $body = json_decode($resp->getContent(), true);

        $this->assertArrayHasKey('submissionId', $body);
        $sub = ExamSubmission::find($body['submissionId']);
        $this->assertEqualsWithDelta(100.0, (float) $sub->percent_score, 0.01);
        $this->assertTrue((bool) $sub->passed);
        $this->assertNotEmpty($sub->anti_cheat_events);
        $this->assertSame('tab_blur', $sub->anti_cheat_events[0]['kind']);
    }

    public function test_strict_mode_blocks_second_attempt(): void
    {
        $student = User::create(['id' => (string) Str::uuid(), 'username' => 'flow2', 'full_name' => 'Flow Two', 'role' => 'student', 'active' => true]);
        $examId = (string) Str::uuid();
        DB::table('exams')->insert(['id' => $examId, 'exam_code' => 'FLOW2', 'name' => 'Flow2', 'duration_minutes' => 60, 'passing_grade' => 50, 'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'created_at' => now(), 'updated_at' => now()]);
        $qid = (string) Str::uuid();
        DB::table('exam_questions')->insert(['id' => $qid, 'exam_id' => $examId, 'position' => 1, 'type' => 'single_choice', 'topic' => 'T', 'prompt' => 'Pick A', 'points' => 1, 'options' => json_encode([['id' => 'A', 'text' => 'a'], ['id' => 'B', 'text' => 'b']]), 'correct_answer' => json_encode('A'), 'explanation_text' => 'x', 'created_at' => now()]);

        $ctrl = new ExamTakeController();
        $cookie = ExamAccessJwt::sign($student->id, $examId, 'tok');
        $mk = function (string $method, array $params = []) use ($student, $cookie, $examId) {
            $r = Request::create("/api/exams/$examId", $method, $params);
            $r->cookies->set(ExamAccessJwt::COOKIE, $cookie);
            $r->attributes->set('authUser', $student);
            return $r;
        };

        $ctrl->show($mk('GET'), $examId);
        $ctrl->submit($mk('POST', ['answers' => [$qid => 'A']]), $examId);

        // Second load of a strict, already-submitted exam → 409 already-submitted.
        $second = $ctrl->show($mk('GET'), $examId);
        $this->assertSame(409, $second->getStatusCode());
    }

    public function test_per_student_draw_serves_and_scores_a_subset(): void
    {
        $student = User::create(['id' => (string) Str::uuid(), 'username' => 'drawstud', 'full_name' => 'Draw Student', 'role' => 'student', 'active' => true]);
        $examId = (string) Str::uuid();
        // Pool of 4, draw 2 per student.
        DB::table('exams')->insert(['id' => $examId, 'exam_code' => 'DRAW1', 'name' => 'Draw', 'duration_minutes' => 60, 'passing_grade' => 50, 'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'draw_count' => 2, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 1; $i <= 4; $i++) {
            DB::table('exam_questions')->insert(['id' => (string) Str::uuid(), 'exam_id' => $examId, 'position' => $i, 'type' => 'single_choice', 'topic' => 'T', 'prompt' => "Q$i", 'points' => 1, 'options' => json_encode([['id' => 'A', 'text' => 'a'], ['id' => 'B', 'text' => 'b']]), 'correct_answer' => json_encode('A'), 'explanation_text' => 'x', 'created_at' => now()]);
        }

        $ctrl = new ExamTakeController();
        $cookie = ExamAccessJwt::sign($student->id, $examId, 'tok');
        $mk = function (string $method, array $params = []) use ($student, $cookie, $examId) {
            $r = Request::create("/api/exams/$examId", $method, $params);
            $r->cookies->set(ExamAccessJwt::COOKIE, $cookie);
            $r->attributes->set('authUser', $student);
            return $r;
        };

        $show = json_decode($ctrl->show($mk('GET'), $examId)->getContent(), true);
        $this->assertCount(2, $show['questions']); // only the drawn subset is served

        $session = ExamSession::where('exam_id', $examId)->where('user_id', $student->id)->first();
        $this->assertCount(2, $session->drawn_question_ids);

        // Answer the two served questions correctly.
        $answers = [];
        foreach ($show['questions'] as $q) {
            $answers[$q['id']] = 'A';
        }
        $body = json_decode($ctrl->submit($mk('POST', ['answers' => $answers]), $examId)->getContent(), true);
        $sub = ExamSubmission::find($body['submissionId']);

        // Scored over the 2 drawn questions only (possible = 2, not 4).
        $this->assertEqualsWithDelta(2.0, (float) $sub->possible_score, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $sub->percent_score, 0.01);
    }
}
