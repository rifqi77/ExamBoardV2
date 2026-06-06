<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\User;
use App\Services\ItemAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_difficulty_flags_and_topic_mastery(): void
    {
        // 4 students, 2 single_choice items (correct = A), one topic.
        $examId = (string) Str::uuid();
        DB::table('exams')->insert(['id' => $examId, 'exam_code' => 'IATEST', 'name' => 'IA', 'duration_minutes' => 30, 'passing_grade' => 50, 'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'created_at' => now(), 'updated_at' => now()]);

        $easy = (string) Str::uuid();
        $mid = (string) Str::uuid();
        foreach ([[$easy, 1], [$mid, 2]] as [$qid, $pos]) {
            DB::table('exam_questions')->insert(['id' => $qid, 'exam_id' => $examId, 'position' => $pos, 'type' => 'single_choice', 'topic' => 'T', 'prompt' => "Q$pos", 'points' => 1, 'options' => json_encode([['id' => 'A', 'text' => 'a'], ['id' => 'B', 'text' => 'b']]), 'correct_answer' => json_encode('A'), 'explanation_text' => 'x', 'created_at' => now()]);
        }

        // easy = all correct; mid = half correct.
        $matrix = [[1, 1], [1, 1], [1, 0], [1, 0]];
        foreach ($matrix as $i => [$ce, $cm]) {
            $uid = (string) Str::uuid();
            User::create(['id' => $uid, 'username' => "stud$i", 'full_name' => "Student $i", 'role' => 'student', 'active' => true]);
            $snap = [$easy => $ce ? 'A' : 'B', $mid => $cm ? 'A' : 'B'];
            DB::table('exam_submissions')->insert(['id' => (string) Str::uuid(), 'exam_id' => $examId, 'user_id' => $uid, 'username' => "stud$i", 'full_name' => "Student $i", 'exam_name' => 'IA', 'exam_mode' => 'strict', 'attempt' => 1, 'passing_grade' => 50, 'final_score' => 0, 'possible_score' => 2, 'percent_score' => 0, 'passed' => 0, 'pending_essay_count' => 0, 'answers_snapshot' => json_encode($snap), 'submitted_at' => now()]);
        }

        $a = ItemAnalysis::forExam(Exam::find($examId));
        $items = collect($a['items'])->keyBy('position');

        $this->assertSame(4, $a['summary']['n']);
        $this->assertSame(2, $a['summary']['autoItemCount']);

        // easy item: p = 1.0, flagged too_easy, discrimination undefined (no variance).
        $this->assertEqualsWithDelta(1.0, $items[1]['pValue'], 0.001);
        $this->assertContains('too_easy', $items[1]['flags']);
        $this->assertNull($items[1]['discrimination']);

        // mid item: p = 0.5.
        $this->assertEqualsWithDelta(0.5, $items[2]['pValue'], 0.001);

        // topic mastery T = (4 easy + 2 mid) / 8 possible = 75%.
        $t = collect($a['topics'])->firstWhere('topic', 'T');
        $this->assertEqualsWithDelta(75.0, $t['masteryPercent'], 0.1);
    }
}
