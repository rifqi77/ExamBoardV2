<?php

namespace Tests\Feature;

use App\Models\ExamSubmission;
use App\Models\User;
use App\Services\OriginalitySignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OriginalitySignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_jaccard_similarity(): void
    {
        $this->assertSame(1.0, OriginalitySignal::jaccard(['a', 'b'], ['a', 'b']));
        $this->assertSame(0.0, OriginalitySignal::jaccard(['a'], ['b']));
        $this->assertEqualsWithDelta(2 / 3, OriginalitySignal::jaccard(['a', 'b', 'c'], ['a', 'b']), 1e-9);
    }

    private function setup2(string $a1, string $a2): array
    {
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'ot'.substr((string) Str::uuid(), 0, 5), 'full_name' => 'OT', 'role' => 'teacher', 'active' => true]);
        $s1 = User::create(['id' => (string) Str::uuid(), 'username' => 'o1'.substr((string) Str::uuid(), 0, 4), 'full_name' => 'Alice', 'role' => 'student', 'active' => true]);
        $s2 = User::create(['id' => (string) Str::uuid(), 'username' => 'o2'.substr((string) Str::uuid(), 0, 4), 'full_name' => 'Bob', 'role' => 'student', 'active' => true]);

        $examId = (string) Str::uuid();
        DB::table('exams')->insert(['id' => $examId, 'exam_code' => 'OG'.substr($examId, 0, 4), 'name' => 'OG', 'duration_minutes' => 30, 'passing_grade' => 50, 'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'created_by' => $teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        $qid = (string) Str::uuid();
        DB::table('exam_questions')->insert(['id' => $qid, 'exam_id' => $examId, 'position' => 1, 'type' => 'essay', 'topic' => 'T', 'prompt' => 'Explain', 'points' => 10, 'created_at' => now()]);

        $sub1 = (string) Str::uuid();
        foreach ([[$sub1, $s1->id, $a1], [(string) Str::uuid(), $s2->id, $a2]] as [$sid, $uid, $ans]) {
            DB::table('exam_submissions')->insert([
                'id' => $sid, 'exam_id' => $examId, 'user_id' => $uid, 'username' => 'x', 'full_name' => $uid === $s2->id ? 'Bob' : 'Alice',
                'exam_name' => 'OG', 'exam_mode' => 'strict', 'attempt' => 1, 'passing_grade' => 50,
                'final_score' => 0, 'possible_score' => 10, 'percent_score' => 0, 'passed' => 0, 'pending_essay_count' => 1,
                'answers_snapshot' => json_encode([$qid => $ans]), 'submitted_at' => now(),
            ]);
        }

        return [$sub1, $qid];
    }

    public function test_near_identical_answers_are_flagged(): void
    {
        $text = 'The mitochondria is the powerhouse of the cell and produces chemical energy for the body';
        [$sub1, $qid] = $this->setup2($text, $text);

        $out = OriginalitySignal::forSubmission(ExamSubmission::find($sub1));

        $this->assertTrue($out[$qid]['flag']);
        $this->assertSame(100, $out[$qid]['similarity']);
        $this->assertSame('Bob', $out[$qid]['matchName']);
    }

    public function test_distinct_answers_are_not_flagged(): void
    {
        [$sub1, $qid] = $this->setup2(
            'Photosynthesis converts sunlight water and carbon dioxide into glucose inside chloroplasts',
            'Newton second law states force equals mass multiplied by acceleration vector quantity'
        );

        $out = OriginalitySignal::forSubmission(ExamSubmission::find($sub1));

        $this->assertFalse($out[$qid]['flag']);
        $this->assertNull($out[$qid]['matchName']);
    }
}
