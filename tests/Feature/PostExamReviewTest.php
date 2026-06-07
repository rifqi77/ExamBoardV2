<?php

namespace Tests\Feature;

use App\Models\ExamSubmission;
use App\Models\User;
use App\Services\AnswerReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostExamReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeExam(bool $allowReview): array
    {
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'rt'.substr((string) Str::uuid(), 0, 6), 'full_name' => 'RT', 'role' => 'teacher', 'active' => true]);
        $student = User::create(['id' => (string) Str::uuid(), 'username' => 'rs'.substr((string) Str::uuid(), 0, 6), 'full_name' => 'RS', 'role' => 'student', 'active' => true]);

        $examId = (string) Str::uuid();
        DB::table('exams')->insert([
            'id' => $examId, 'exam_code' => 'RV'.substr($examId, 0, 4), 'name' => 'RV', 'duration_minutes' => 30,
            'passing_grade' => 50, 'active' => 1, 'exam_mode' => 'strict', 'language' => 'English',
            'allow_answer_review' => $allowReview ? 1 : 0, 'created_by' => $teacher->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $qid = (string) Str::uuid();
        DB::table('exam_questions')->insert([
            'id' => $qid, 'exam_id' => $examId, 'position' => 1, 'type' => 'single_choice', 'topic' => 'T',
            'prompt' => 'Pick A', 'points' => 5, 'options' => json_encode([['id' => 'A', 'text' => 'Alpha'], ['id' => 'B', 'text' => 'Beta']]),
            'correct_answer' => json_encode('A'), 'explanation_text' => 'A is correct', 'created_at' => now(),
        ]);
        $subId = (string) Str::uuid();
        DB::table('exam_submissions')->insert([
            'id' => $subId, 'exam_id' => $examId, 'user_id' => $student->id, 'username' => 's', 'full_name' => 'S',
            'exam_name' => 'RV', 'exam_mode' => 'strict', 'attempt' => 1, 'passing_grade' => 50,
            'final_score' => 5, 'possible_score' => 5, 'percent_score' => 100, 'passed' => 1, 'pending_essay_count' => 0,
            'answers_snapshot' => json_encode([$qid => 'A']), 'submitted_at' => now(),
        ]);

        return [$subId, $qid];
    }

    public function test_review_is_built_with_correctness_when_enabled(): void
    {
        [$subId, $qid] = $this->makeExam(true);

        $review = AnswerReview::forSubmission(ExamSubmission::find($subId));

        $this->assertIsArray($review);
        $this->assertCount(1, $review);
        $this->assertSame('A', $review[0]['studentAnswer']);
        $this->assertSame('A', $review[0]['correctAnswer']);
        $this->assertTrue($review[0]['isCorrect']);
        $this->assertSame('A is correct', $review[0]['explanation']);
    }

    public function test_review_is_null_when_disabled(): void
    {
        [$subId] = $this->makeExam(false);

        $this->assertNull(AnswerReview::forSubmission(ExamSubmission::find($subId)));
    }
}
